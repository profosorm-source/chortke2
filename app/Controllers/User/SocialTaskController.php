<?php

namespace App\Controllers\User;

use App\Services\SocialTask\SocialTaskService;
use App\Services\SocialTask\TrustScoreService;

/**
 * SocialTaskController
 *
 * جایگزین: AdsocialController + TaskController
 *
 * Executor routes:
 *   GET  /social-tasks                    → index (لیست تسک‌ها)
 *   GET  /social-tasks/history            → history
 *   GET  /social-tasks/dashboard          → dashboard
 *   POST /social-tasks/start              → start
 *   GET  /social-tasks/{id}/execute       → showExecute
 *   POST /social-tasks/{id}/submit        → submit
 *
 * Advertiser routes:
 *   GET  /social-ads                      → myAds
 *   GET  /social-ads/dashboard            → advertiserDashboard
 *   GET  /social-ads/create               → create
 *   POST /social-ads/store                → store
 *   GET  /social-ads/{id}                 → show
 *   POST /social-ads/{id}/pause           → pause
 *   POST /social-ads/{id}/resume          → resume
 *   POST /social-ads/{id}/cancel          → cancel
 *   GET  /social-ads/execution/{id}       → executionDetail
 *   POST /social-ads/execution/{id}/approve → approveExecution
 *   POST /social-ads/execution/{id}/reject  → rejectExecution
 */
class SocialTaskController extends BaseUserController
{
    private SocialTaskService $service;
    private TrustScoreService $trustService;

    public function __construct(SocialTaskService $service, TrustScoreService $trustService)
    {
        parent::__construct();
        $this->service      = $service;
        $this->trustService = $trustService;
    }

    // ─────────────────────────────────────────────────────────────
    // EXECUTOR
    // ─────────────────────────────────────────────────────────────

    /**
     * لیست تسک‌ها با فیلتر پیشرفته
     */
    public function index(): void
    {
        $userId  = (int)user_id();
        $filters = [
            'platform'   => $this->request->get('platform') ?? '',
            'task_type'  => $this->request->get('task_type') ?? '',
            'min_reward' => $this->request->get('min_reward') ?? '',
            'max_reward' => $this->request->get('max_reward') ?? '',
            'sort'       => $this->request->get('sort') ?? 'random',
            'search'     => $this->request->get('q') ?? '',
            'is_mobile'  => $this->isMobileRequest(),
        ];

        $result = $this->service->getTasksForExecutor($userId, $filters, 30);

        view('user.social-tasks.index', [
            'title'       => 'تسک‌های شبکه اجتماعی',
            'tasks'       => $result['tasks'],
            'trust_score' => $result['trust_score'],
            'filters'     => $filters,
            'platforms'   => $this->platformLabels(),
            'task_types'  => $this->taskTypeLabels(),
        ]);
    }

    /**
     * داشبورد Executor
     */
    public function executorDashboard(): void
    {
        $userId  = (int)user_id();
        $stats   = $this->service->getExecutorStats($userId);
        $history = $this->service->getExecutorHistory($userId, 7);
        $weekly  = $this->trustService->getWeeklyStats($userId);

        view('user.social-tasks.dashboard', [
            'title'        => 'داشبورد تسک‌های اجتماعی',
            'stats'        => $stats,
            'recent'       => $history,
            'trust_score'  => $this->trustService->get($userId),
            'weekly_stats' => $weekly,
        ]);
    }

    /**
     * شروع execution
     */
    public function start(): void
    {
        $userId = (int)user_id();
        $adId   = (int)($this->request->body()['ad_id'] ?? 0);

        try {
            $result = $this->service->startExecution($userId, $adId, [
                'ip'         => get_client_ip(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]);
            $this->response->json($result);
        } catch (\Exception $e) {
            logger()->error('social_task.start.failed', ['err' => $e->getMessage(), 'user' => $userId]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی. لطفاً دوباره تلاش کنید.']);
        }
    }

    /**
     * صفحه انجام تسک
     */
    public function showExecute(): void
    {
        $userId      = (int)user_id();
        $executionId = (int)$this->request->param('id');

        $exec = $this->getExecutionForUser($executionId, $userId);
        if (!$exec) {
            redirect(url('/social-tasks'));
            return;
        }

        view('user.social-tasks.execute', [
            'title'     => 'انجام تسک',
            'execution' => $exec,
            'task'      => $exec, // ad data joined
        ]);
    }

    /**
     * ثبت نهایی تسک
     */
    public function submit(): void
    {
        $userId      = (int)user_id();
        $executionId = (int)$this->request->param('id');
        $body        = $this->request->body();

        try {
            $result = $this->service->submitExecution($executionId, $userId, array_merge($body, [
                'ip'          => get_client_ip(),
                'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]));

            if (is_ajax()) {
                $this->response->json($result);
                return;
            }
            $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        } catch (\Exception $e) {
            logger()->error('social_task.submit.failed', ['err' => $e->getMessage(), 'user' => $userId]);
            if (is_ajax()) {
                $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']);
                return;
            }
            $this->session->setFlash('error', 'خطای سیستمی.');
        }

        redirect(url('/social-tasks'));
    }

    /**
     * تاریخچه
     */
    public function history(): void
    {
        $userId = (int)user_id();
        $page   = max(1, (int)($this->request->get('page') ?? 1));
        $limit  = 20;

        $history = $this->service->getExecutorHistory($userId, $limit, ($page - 1) * $limit);
        $stats   = $this->service->getExecutorStats($userId);

        view('user.social-tasks.history', [
            'title'       => 'تاریخچه تسک‌ها',
            'history'     => $history,
            'stats'       => $stats,
            'trust_score' => $this->trustService->get($userId),
            'page'        => $page,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // ADVERTISER
    // ─────────────────────────────────────────────────────────────

    public function myAds(): void
    {
        $userId = (int)user_id();
        $page   = max(1, (int)($this->request->get('page') ?? 1));
        $limit  = 20;

        $ads = $this->getMyAds($userId, $limit, ($page - 1) * $limit);

        view('user.social-tasks.my-ads', [
            'title' => 'آگهی‌های من',
            'ads'   => $ads,
            'page'  => $page,
        ]);
    }

    public function advertiserDashboard(): void
    {
        $userId = (int)user_id();
        $summary = $this->getAdvertiserSummary($userId);

        view('user.social-tasks.advertiser-dashboard', [
            'title'   => 'داشبورد تبلیغ‌دهنده',
            'summary' => $summary,
        ]);
    }

    public function create(): void
    {
        view('user.social-tasks.create', [
            'title'      => 'ثبت آگهی جدید',
            'platforms'  => $this->platformLabels(),
            'task_types' => $this->taskTypeLabels(),
        ]);
    }

    public function store(): void
    {
        $userId = (int)user_id();

        try {
            $result = $this->service->createAd($userId, $this->request->body());
            $this->session->setFlash(
                $result['success'] ? 'success' : 'error',
                $result['success'] ? 'آگهی با موفقیت ثبت شد.' : ($result['message'] ?? 'خطا در ثبت آگهی')
            );
            redirect($result['success'] ? url('/social-ads') : url('/social-ads/create'));
        } catch (\Exception $e) {
            logger()->error('social_task.store.failed', ['err' => $e->getMessage(), 'user' => $userId]);
            $this->session->setFlash('error', 'خطای سیستمی در ثبت آگهی.');
            redirect(url('/social-ads/create'));
        }
    }

    public function show(): void
    {
        $userId = (int)user_id();
        $adId   = (int)$this->request->param('id');

        $stats = $this->service->getAdvertiserAdStats($userId, $adId);
        if (!$stats) {
            redirect(url('/social-ads'));
            return;
        }

        $executions = $this->getAdExecutions($adId, 20, 0);

        view('user.social-tasks.show', [
            'title'      => 'مدیریت آگهی',
            'ad'         => $stats,
            'executions' => $executions,
        ]);
    }

    public function executionDetail(): void
    {
        $userId      = (int)user_id();
        $executionId = (int)$this->request->param('id');

        $exec = $this->getExecutionForAdvertiser($userId, $executionId);
        if (!$exec) {
            redirect(url('/social-ads'));
            return;
        }

        view('user.social-tasks.execution-detail', [
            'title' => 'جزئیات اجرا',
            'exec'  => $exec,
        ]);
    }

    public function approveExecution(): void
    {
        $userId      = (int)user_id();
        $executionId = (int)$this->request->param('id');

        $result = $this->service->advertiserApprove($userId, $executionId);

        if (is_ajax()) {
            $this->response->json($result);
            return;
        }
        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(url('/social-ads'));
    }

    public function rejectExecution(): void
    {
        $userId      = (int)user_id();
        $executionId = (int)$this->request->param('id');
        $reason      = trim($this->request->post('reason') ?? '');

        $result = $this->service->advertiserReject($userId, $executionId, $reason);

        if (is_ajax()) {
            $this->response->json($result);
            return;
        }
        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(url('/social-ads'));
    }

    public function pause(): void  { $this->toggleAdStatus('paused'); }
    public function resume(): void { $this->toggleAdStatus('active'); }
    public function cancel(): void { $this->toggleAdStatus('cancelled'); }

    // ─────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────

    private function toggleAdStatus(string $status): void
    {
        $userId = (int)user_id();
        $adId   = (int)$this->request->param('id');

        // فقط advertiser مالک می‌تواند تغییر دهد
        $affected = $this->db()->query(
            "UPDATE social_ads SET status = ?, updated_at = NOW()
             WHERE id = ? AND advertiser_id = ?",
            [$status, $adId, $userId]
        );

        $result = ['success' => (bool)$affected, 'message' => $affected ? 'وضعیت تغییر کرد' : 'خطا'];

        if (is_ajax()) {
            $this->response->json($result);
            return;
        }
        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(url('/social-ads'));
    }

    private function getExecutionForUser(int $executionId, int $userId): ?object
    {
        return $this->db()->fetch(
            "SELECT ste.*, sa.title, sa.platform, sa.task_type, sa.reward,
                    sa.target_url, sa.target_username, sa.description, sa.expected_time
             FROM social_task_executions ste
             JOIN social_ads sa ON sa.id = ste.ad_id
             WHERE ste.id = ? AND ste.executor_id = ? LIMIT 1",
            [$executionId, $userId]
        ) ?: null;
    }

    private function getExecutionForAdvertiser(int $advertiserId, int $executionId): ?object
    {
        return $this->db()->fetch(
            "SELECT ste.*, sa.title, sa.platform, sa.task_type,
                    u.full_name AS executor_name
             FROM social_task_executions ste
             JOIN social_ads sa ON sa.id = ste.ad_id
             JOIN users u ON u.id = ste.executor_id
             WHERE ste.id = ? AND sa.advertiser_id = ? LIMIT 1",
            [$executionId, $advertiserId]
        ) ?: null;
    }

    private function getMyAds(int $userId, int $limit, int $offset): array
    {
        return $this->db()->fetchAll(
            "SELECT sa.*,
                    COUNT(ste.id)                              AS total_executions,
                    SUM(ste.decision = 'approved')             AS approved_count,
                    SUM(ste.decision = 'rejected')             AS rejected_count
             FROM social_ads sa
             LEFT JOIN social_task_executions ste ON ste.ad_id = sa.id
             WHERE sa.advertiser_id = ?
             GROUP BY sa.id
             ORDER BY sa.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        ) ?: [];
    }

    private function getAdExecutions(int $adId, int $limit, int $offset): array
    {
        return $this->db()->fetchAll(
            "SELECT ste.*, u.full_name AS executor_name,
                    COALESCE(ut.trust_score, 50) AS executor_trust
             FROM social_task_executions ste
             JOIN users u ON u.id = ste.executor_id
             LEFT JOIN social_user_trust ut ON ut.user_id = ste.executor_id
             WHERE ste.ad_id = ?
             ORDER BY ste.created_at DESC
             LIMIT ? OFFSET ?",
            [$adId, $limit, $offset]
        ) ?: [];
    }

    private function getAdvertiserSummary(int $userId): array
    {
        $row = $this->db()->fetch(
            "SELECT
                COUNT(DISTINCT sa.id) AS total_ads,
                SUM(sa.max_slots * sa.reward) AS total_budget,
                SUM(CASE WHEN ste.decision IN ('approved','soft_approved') THEN sa.reward ELSE 0 END) AS spent,
                COUNT(ste.id) AS total_executions,
                AVG(ste.task_score) AS avg_score
             FROM social_ads sa
             LEFT JOIN social_task_executions ste ON ste.ad_id = sa.id
             WHERE sa.advertiser_id = ?",
            [$userId]
        );

        return $row ? (array)$row : [];
    }

    private function isMobileRequest(): bool
    {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        return (bool)preg_match('/mobile|android|iphone|ipad/', $ua);
    }

    private function db(): \Core\Database
    {
        return app()->make(\Core\Database::class);
    }

    private function platformLabels(): array
    {
        return [
            'instagram' => 'اینستاگرام',
            'telegram'  => 'تلگرام',
            'twitter'   => 'توییتر/X',
            'tiktok'    => 'تیک‌تاک',
        ];
    }

    private function taskTypeLabels(): array
    {
        return [
            'follow'       => 'فالو',
            'like'         => 'لایک',
            'comment'      => 'کامنت',
            'share'        => 'اشتراک‌گذاری',
            'retweet'      => 'ریتوییت',
            'join_channel' => 'عضویت در کانال',
            'join_group'   => 'عضویت در گروه',
        ];
    }
}
