<?php

namespace App\Controllers\Admin;

use App\Services\SocialTask\SocialTaskService;
use App\Services\SocialTask\TrustScoreService;
use App\Services\SocialTask\SilentAntiFraudService;
use App\Services\WalletService;
use App\Services\AuditTrail;
use Core\Database;

/**
 * AdminSocialTaskController
 *
 * مدیریت کامل ماژول SocialTask از پنل ادمین:
 *
 * آگهی‌ها (Ads):
 *   GET  /admin/social-tasks                     → index
 *   GET  /admin/social-tasks/{id}                → show
 *   POST /admin/social-tasks/{id}/approve        → approve
 *   POST /admin/social-tasks/{id}/reject         → reject
 *   POST /admin/social-tasks/{id}/pause          → pause
 *   POST /admin/social-tasks/{id}/resume         → resume
 *   POST /admin/social-tasks/{id}/cancel         → cancel
 *
 * اجراها (Executions):
 *   GET  /admin/social-executions                → executions
 *   GET  /admin/social-executions/{id}           → executionShow
 *   POST /admin/social-executions/{id}/flag      → flagExecution
 *   POST /admin/social-executions/{id}/override  → overrideDecision
 *
 * Trust & Fraud:
 *   GET  /admin/social-trust                     → trustDashboard
 *   GET  /admin/social-trust/user/{id}           → userTrust
 *   POST /admin/social-trust/user/{id}/adjust    → adjustTrust
 *
 * آمار:
 *   GET  /admin/social-tasks/stats               → stats
 */
class SocialTaskController extends BaseAdminController
{
    private SocialTaskService      $service;
    private TrustScoreService      $trust;
    private SilentAntiFraudService $antiFraud;
    private WalletService          $wallet;
    private Database               $db;

    public function __construct(
        SocialTaskService      $service,
        TrustScoreService      $trust,
        SilentAntiFraudService $antiFraud,
        WalletService          $wallet,
        Database               $db
    ) {
        parent::__construct();
        $this->service   = $service;
        $this->trust     = $trust;
        $this->antiFraud = $antiFraud;
        $this->wallet    = $wallet;
        $this->db        = $db;
    }

    // ─────────────────────────────────────────────────────────────
    // آگهی‌ها
    // ─────────────────────────────────────────────────────────────

    public function index(): void
    {
        $page    = max(1, (int)($this->request->get('page') ?? 1));
        $limit   = 30;
        $filters = [
            'status'   => $this->request->get('status')   ?? '',
            'platform' => $this->request->get('platform') ?? '',
            'search'   => $this->request->get('search')   ?? '',
        ];

        [$ads, $total] = $this->getAds($filters, $limit, ($page - 1) * $limit);
        $stats         = $this->getAdStats();

        view('admin.social-tasks.index', [
            'title'      => 'مدیریت آگهی‌های اجتماعی',
            'ads'        => $ads,
            'stats'      => $stats,
            'filters'    => $filters,
            'page'       => $page,
            'total'      => $total,
            'totalPages' => (int)ceil($total / $limit),
        ]);
    }

    public function show(): void
    {
        $id  = (int)$this->request->param('id');
        $ad  = $this->getAdById($id);

        if (!$ad) {
            $this->session->setFlash('error', 'آگهی یافت نشد.');
            redirect(url('/admin/social-tasks'));
            return;
        }

        $executions  = $this->getAdExecutions($id, 50, 0);
        $adStats     = $this->service->getAdvertiserAdStats((int)$ad->advertiser_id, $id);

        view('admin.social-tasks.show', [
            'title'      => 'جزئیات آگهی #' . $id,
            'ad'         => $ad,
            'executions' => $executions,
            'adStats'    => $adStats,
        ]);
    }

    public function approve(): void
    {
        $id     = (int)$this->request->param('id');
        $result = $this->changeAdStatus($id, 'active', 'admin_approved');

        if (is_ajax()) { $this->response->json($result); return; }
        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(url('/admin/social-tasks'));
    }

    public function reject(): void
    {
        $id     = (int)$this->request->param('id');
        $reason = trim($this->request->post('reason') ?? '');

        if (!$reason) {
            $this->response->json(['success' => false, 'message' => 'دلیل رد الزامی است']);
            return;
        }

        $this->db->query(
            "UPDATE social_ads SET status = 'rejected', reject_reason = ?, updated_at = NOW() WHERE id = ?",
            [$reason, $id]
        );

        AuditTrail::record('social_ad.rejected', admin_id(), [
            'ad_id'  => $id,
            'reason' => $reason,
        ]);

        $result = ['success' => true, 'message' => 'آگهی رد شد'];
        if (is_ajax()) { $this->response->json($result); return; }
        $this->session->setFlash('success', $result['message']);
        redirect(url('/admin/social-tasks'));
    }

    public function pause(): void
    {
        $result = $this->changeAdStatus((int)$this->request->param('id'), 'paused', 'admin_paused');
        if (is_ajax()) { $this->response->json($result); return; }
        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(url('/admin/social-tasks'));
    }

    public function resume(): void
    {
        $result = $this->changeAdStatus((int)$this->request->param('id'), 'active', 'admin_resumed');
        if (is_ajax()) { $this->response->json($result); return; }
        $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        redirect(url('/admin/social-tasks'));
    }

    public function cancel(): void
    {
        $id     = (int)$this->request->param('id');
        $ad     = $this->getAdById($id);

        if (!$ad) {
            $this->response->json(['success' => false, 'message' => 'آگهی یافت نشد']);
            return;
        }

        // بازگشت بودجه باقیمانده به تبلیغ‌دهنده
        $refund = (float)$ad->remaining_slots * (float)$ad->reward;
        if ($refund > 0) {
            $this->wallet->deposit((int)$ad->advertiser_id, $refund, 'irt', [
                'source' => 'social_ad_cancel_refund',
                'ad_id'  => $id,
                'note'   => 'بازگشت بودجه پس از لغو توسط ادمین',
            ]);
        }

        $this->db->query(
            "UPDATE social_ads SET status = 'cancelled', updated_at = NOW() WHERE id = ?",
            [$id]
        );

        AuditTrail::record('social_ad.cancelled', admin_id(), [
            'ad_id'  => $id,
            'refund' => $refund,
        ]);

        $result = ['success' => true, 'message' => 'آگهی لغو شد' . ($refund > 0 ? " و {$refund} تومان برگشت داده شد" : '')];
        if (is_ajax()) { $this->response->json($result); return; }
        $this->session->setFlash('success', $result['message']);
        redirect(url('/admin/social-tasks'));
    }

    // ─────────────────────────────────────────────────────────────
    // اجراها (Executions)
    // ─────────────────────────────────────────────────────────────

    public function executions(): void
    {
        $page    = max(1, (int)($this->request->get('page') ?? 1));
        $limit   = 30;
        $filters = [
            'decision'  => $this->request->get('decision')  ?? '',
            'platform'  => $this->request->get('platform')  ?? '',
            'flag'      => $this->request->get('flag')      ?? '',
            'search'    => $this->request->get('search')    ?? '',
        ];

        [$executions, $total] = $this->getExecutions($filters, $limit, ($page - 1) * $limit);
        $execStats            = $this->getExecutionStats();

        view('admin.social-tasks.executions', [
            'title'      => 'اجراهای تسک اجتماعی',
            'executions' => $executions,
            'execStats'  => $execStats,
            'filters'    => $filters,
            'page'       => $page,
            'total'      => $total,
            'totalPages' => (int)ceil($total / $limit),
        ]);
    }

    public function executionShow(): void
    {
        $id   = (int)$this->request->param('id');
        $exec = $this->getExecutionById($id);

        if (!$exec) {
            $this->session->setFlash('error', 'اجرا یافت نشد.');
            redirect(url('/admin/social-executions'));
            return;
        }

        // behavior data decoded
        $behaviorData = [];
        if (!empty($exec->behavior_data)) {
            $behaviorData = json_decode($exec->behavior_data, true) ?? [];
        }

        view('admin.social-tasks.execution-show', [
            'title'        => 'جزئیات اجرا #' . $id,
            'exec'         => $exec,
            'behaviorData' => $behaviorData,
            'trustScore'   => $this->trust->get((int)$exec->executor_id),
            'restriction'  => $this->antiFraud->getRestrictionLevel((int)$exec->executor_id),
        ]);
    }

    public function flagExecution(): void
    {
        $id   = (int)$this->request->param('id');
        $note = trim($this->request->post('note') ?? '');

        $this->db->query(
            "UPDATE social_task_executions SET flag_review = 1, flag_note = ?, updated_at = NOW() WHERE id = ?",
            [$note, $id]
        );

        AuditTrail::record('social_exec.flagged', admin_id(), ['execution_id' => $id, 'note' => $note]);

        $this->response->json(['success' => true, 'message' => 'فلگ شد']);
    }

    public function overrideDecision(): void
    {
        $id       = (int)$this->request->param('id');
        $decision = $this->request->post('decision') ?? '';
        $reason   = trim($this->request->post('reason') ?? '');

        if (!in_array($decision, ['approved', 'soft_approved', 'rejected'], true)) {
            $this->response->json(['success' => false, 'message' => 'تصمیم معتبر نیست']);
            return;
        }
        if (!$reason) {
            $this->response->json(['success' => false, 'message' => 'دلیل override الزامی است']);
            return;
        }

        $exec = $this->getExecutionById($id);
        if (!$exec) {
            $this->response->json(['success' => false, 'message' => 'اجرا یافت نشد']);
            return;
        }

        $this->db->query(
            "UPDATE social_task_executions
             SET decision = ?, decision_reason = CONCAT('admin_override: ', ?), updated_at = NOW()
             WHERE id = ?",
            [$decision, $reason, $id]
        );

        // اگر override به approved تغییر کرد، trust را جایزه بده
        if ($decision === 'approved' && $exec->decision !== 'approved') {
            $this->trust->rewardGoodTask((int)$exec->executor_id, $id);
        }
        // اگر override به rejected تغییر کرد، trust جریمه شود
        if ($decision === 'rejected' && $exec->decision !== 'rejected') {
            $this->trust->penalizeRejection((int)$exec->executor_id, $id);
        }

        AuditTrail::record('social_exec.override', admin_id(), [
            'execution_id'  => $id,
            'old_decision'  => $exec->decision,
            'new_decision'  => $decision,
            'reason'        => $reason,
        ]);

        $this->response->json(['success' => true, 'message' => 'تصمیم override شد']);
    }

    // ─────────────────────────────────────────────────────────────
    // Trust Dashboard
    // ─────────────────────────────────────────────────────────────

    public function trustDashboard(): void
    {
        $page  = max(1, (int)($this->request->get('page') ?? 1));
        $limit = 30;

        $lowTrustUsers  = $this->getLowTrustUsers($limit, ($page - 1) * $limit);
        $totalLow       = $this->countLowTrustUsers();
        $trustStats     = $this->getTrustStats();

        view('admin.social-tasks.trust', [
            'title'         => 'داشبورد Trust Score',
            'lowTrustUsers' => $lowTrustUsers,
            'trustStats'    => $trustStats,
            'page'          => $page,
            'total'         => $totalLow,
            'totalPages'    => (int)ceil($totalLow / $limit),
        ]);
    }

    public function userTrust(): void
    {
        $userId  = (int)$this->request->param('id');
        $trust   = $this->trust->get($userId);
        $weekly  = $this->trust->getWeeklyStats($userId);
        $history = $this->getTrustHistory($userId);
        $restriction = $this->antiFraud->getRestrictionLevel($userId);

        $user = $this->db->fetch("SELECT id, full_name, email FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (!$user) {
            $this->session->setFlash('error', 'کاربر یافت نشد.');
            redirect(url('/admin/social-trust'));
            return;
        }

        view('admin.social-tasks.user-trust', [
            'title'       => 'Trust Score کاربر: ' . ($user->full_name ?? ''),
            'user'        => $user,
            'trust'       => $trust,
            'weekly'      => $weekly,
            'history'     => $history,
            'restriction' => $restriction,
        ]);
    }

    public function adjustTrust(): void
    {
        $userId = (int)$this->request->param('id');
        $delta  = (float)($this->request->post('delta') ?? 0);
        $reason = trim($this->request->post('reason') ?? '');

        if (!$reason) {
            $this->response->json(['success' => false, 'message' => 'دلیل الزامی است']);
            return;
        }
        if ($delta === 0.0) {
            $this->response->json(['success' => false, 'message' => 'مقدار تغییر نمی‌تواند صفر باشد']);
            return;
        }

        // استفاده از UserScoreService موجود برای ثبت event
        $scoreService = app()->make(\App\Services\UserScoreService::class);
        $scoreService->applyEventDelta($userId, 'social_trust', $delta, 'admin_manual', [
            'reason'   => $reason,
            'admin_id' => admin_id(),
        ]);

        // اعمال مستقیم روی جدول trust
        $current = $this->trust->get($userId);
        $newVal  = max(0, min(100, $current + $delta));
        $this->db->query(
            "INSERT INTO social_user_trust (user_id, trust_score, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE trust_score = ?, updated_at = NOW()",
            [$userId, $newVal, $newVal]
        );

        AuditTrail::record('social_trust.adjusted', admin_id(), [
            'user_id'   => $userId,
            'delta'     => $delta,
            'old_trust' => $current,
            'new_trust' => $newVal,
            'reason'    => $reason,
        ]);

        $this->response->json([
            'success'   => true,
            'message'   => 'Trust Score به‌روز شد',
            'new_trust' => $newVal,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // آمار کلی
    // ─────────────────────────────────────────────────────────────

    public function stats(): void
    {
        $stats = $this->getFullStats();

        view('admin.social-tasks.stats', [
            'title' => 'آمار SocialTask',
            'stats' => $stats,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Private helpers — DB queries
    // ─────────────────────────────────────────────────────────────

    private function getAds(array $filters, int $limit, int $offset): array
    {
        $where  = ['sa.deleted_at IS NULL'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]  = 'sa.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['platform'])) {
            $where[]  = 'sa.platform = ?';
            $params[] = $filters['platform'];
        }
        if (!empty($filters['search'])) {
            $like     = '%' . $filters['search'] . '%';
            $where[]  = '(sa.title LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereStr = implode(' AND ', $where);

        $total = (int)($this->db->fetch(
            "SELECT COUNT(*) AS cnt FROM social_ads sa
             LEFT JOIN users u ON u.id = sa.advertiser_id
             WHERE {$whereStr}",
            $params
        )?->cnt ?? 0);

        $params[] = $limit;
        $params[] = $offset;

        $ads = $this->db->fetchAll(
            "SELECT sa.*,
                    u.full_name AS advertiser_name,
                    u.email     AS advertiser_email,
                    COUNT(ste.id) AS total_execs,
                    SUM(ste.decision = 'approved') AS approved_execs
             FROM social_ads sa
             LEFT JOIN users u ON u.id = sa.advertiser_id
             LEFT JOIN social_task_executions ste ON ste.ad_id = sa.id
             WHERE {$whereStr}
             GROUP BY sa.id
             ORDER BY sa.created_at DESC
             LIMIT ? OFFSET ?",
            $params
        ) ?: [];

        return [$ads, $total];
    }

    private function getAdById(int $id): ?object
    {
        return $this->db->fetch(
            "SELECT sa.*, u.full_name AS advertiser_name, u.email AS advertiser_email
             FROM social_ads sa
             LEFT JOIN users u ON u.id = sa.advertiser_id
             WHERE sa.id = ? LIMIT 1",
            [$id]
        ) ?: null;
    }

    private function getAdExecutions(int $adId, int $limit, int $offset): array
    {
        return $this->db->fetchAll(
            "SELECT ste.*, u.full_name AS executor_name, u.email AS executor_email,
                    COALESCE(ut.trust_score, 50) AS trust_score
             FROM social_task_executions ste
             JOIN users u ON u.id = ste.executor_id
             LEFT JOIN social_user_trust ut ON ut.user_id = ste.executor_id
             WHERE ste.ad_id = ?
             ORDER BY ste.created_at DESC
             LIMIT ? OFFSET ?",
            [$adId, $limit, $offset]
        ) ?: [];
    }

    private function getAdStats(): object
    {
        return $this->db->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'active')          AS active,
                SUM(status = 'pending_review')  AS pending,
                SUM(status = 'paused')          AS paused,
                SUM(status = 'cancelled')       AS cancelled,
                SUM(status = 'rejected')        AS rejected,
                SUM(max_slots * reward)         AS total_budget
             FROM social_ads WHERE deleted_at IS NULL"
        ) ?: (object)[];
    }

    private function getExecutions(array $filters, int $limit, int $offset): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['decision'])) {
            $where[]  = 'ste.decision = ?';
            $params[] = $filters['decision'];
        }
        if (!empty($filters['flag'])) {
            $where[]  = 'ste.flag_review = 1';
        }
        if (!empty($filters['platform'])) {
            $where[]  = 'sa.platform = ?';
            $params[] = $filters['platform'];
        }
        if (!empty($filters['search'])) {
            $like     = '%' . $filters['search'] . '%';
            $where[]  = '(u.full_name LIKE ? OR u.email LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        $whereStr = implode(' AND ', $where);

        $total = (int)($this->db->fetch(
            "SELECT COUNT(*) AS cnt
             FROM social_task_executions ste
             JOIN social_ads sa ON sa.id = ste.ad_id
             JOIN users u ON u.id = ste.executor_id
             WHERE {$whereStr}",
            $params
        )?->cnt ?? 0);

        $params[] = $limit;
        $params[] = $offset;

        $rows = $this->db->fetchAll(
            "SELECT ste.*,
                    u.full_name AS executor_name, u.email AS executor_email,
                    sa.platform, sa.task_type, sa.title AS ad_title,
                    COALESCE(ut.trust_score, 50) AS trust_score
             FROM social_task_executions ste
             JOIN social_ads sa ON sa.id = ste.ad_id
             JOIN users u ON u.id = ste.executor_id
             LEFT JOIN social_user_trust ut ON ut.user_id = ste.executor_id
             WHERE {$whereStr}
             ORDER BY ste.created_at DESC
             LIMIT ? OFFSET ?",
            $params
        ) ?: [];

        return [$rows, $total];
    }

    private function getExecutionById(int $id): ?object
    {
        return $this->db->fetch(
            "SELECT ste.*,
                    u.full_name AS executor_name, u.email AS executor_email,
                    sa.platform, sa.task_type, sa.title AS ad_title,
                    sa.reward, sa.advertiser_id,
                    COALESCE(ut.trust_score, 50) AS trust_score
             FROM social_task_executions ste
             JOIN social_ads sa ON sa.id = ste.ad_id
             JOIN users u ON u.id = ste.executor_id
             LEFT JOIN social_user_trust ut ON ut.user_id = ste.executor_id
             WHERE ste.id = ? LIMIT 1",
            [$id]
        ) ?: null;
    }

    private function getExecutionStats(): object
    {
        return $this->db->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(decision = 'approved')      AS approved,
                SUM(decision = 'soft_approved') AS soft_approved,
                SUM(decision = 'rejected')      AS rejected,
                SUM(flag_review = 1)            AS flagged,
                AVG(task_score)                 AS avg_score
             FROM social_task_executions"
        ) ?: (object)[];
    }

    private function getLowTrustUsers(int $limit, int $offset): array
    {
        return $this->db->fetchAll(
            "SELECT ut.*, u.full_name, u.email,
                    COUNT(ste.id) AS total_execs,
                    SUM(ste.decision = 'rejected') AS rejected_execs
             FROM social_user_trust ut
             JOIN users u ON u.id = ut.user_id
             LEFT JOIN social_task_executions ste ON ste.executor_id = ut.user_id
             WHERE ut.trust_score < 40
             GROUP BY ut.user_id
             ORDER BY ut.trust_score ASC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        ) ?: [];
    }

    private function countLowTrustUsers(): int
    {
        return (int)($this->db->fetch(
            "SELECT COUNT(*) AS cnt FROM social_user_trust WHERE trust_score < 40"
        )?->cnt ?? 0);
    }

    private function getTrustStats(): object
    {
        return $this->db->fetch(
            "SELECT
                COUNT(*) AS total_users,
                AVG(trust_score) AS avg_trust,
                SUM(trust_score >= 60) AS high_trust,
                SUM(trust_score BETWEEN 40 AND 59) AS mid_trust,
                SUM(trust_score < 40) AS low_trust
             FROM social_user_trust"
        ) ?: (object)[];
    }

    private function getTrustHistory(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT *
             FROM user_score_events
             WHERE user_id = ? AND domain = 'social_trust'
             ORDER BY created_at DESC
             LIMIT 30",
            [$userId]
        ) ?: [];
    }

    private function getFullStats(): object
    {
        return $this->db->fetch(
            "SELECT
                (SELECT COUNT(*) FROM social_ads) AS total_ads,
                (SELECT COUNT(*) FROM social_ads WHERE status = 'active') AS active_ads,
                (SELECT COUNT(*) FROM social_ads WHERE status = 'pending_review') AS pending_ads,
                (SELECT COUNT(*) FROM social_task_executions) AS total_execs,
                (SELECT COUNT(*) FROM social_task_executions WHERE decision = 'approved') AS approved_execs,
                (SELECT COUNT(*) FROM social_task_executions WHERE decision = 'rejected') AS rejected_execs,
                (SELECT COUNT(*) FROM social_task_executions WHERE flag_review = 1) AS flagged_execs,
                (SELECT ROUND(AVG(task_score),1) FROM social_task_executions) AS avg_score,
                (SELECT COUNT(*) FROM social_user_trust WHERE trust_score < 40) AS low_trust_users,
                (SELECT ROUND(AVG(trust_score),1) FROM social_user_trust) AS avg_trust"
        ) ?: (object)[];
    }

    private function changeAdStatus(int $id, string $status, string $auditEvent): array
    {
        $ad = $this->getAdById($id);
        if (!$ad) {
            return ['success' => false, 'message' => 'آگهی یافت نشد'];
        }

        $this->db->query(
            "UPDATE social_ads SET status = ?, updated_at = NOW() WHERE id = ?",
            [$status, $id]
        );

        AuditTrail::record('social_ad.' . $auditEvent, admin_id(), ['ad_id' => $id, 'status' => $status]);

        return ['success' => true, 'message' => 'وضعیت آگهی تغییر کرد'];
    }
}
