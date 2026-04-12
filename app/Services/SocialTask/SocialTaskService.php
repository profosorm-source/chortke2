<?php

namespace App\Services\SocialTask;

use App\Services\WalletService;
use App\Services\NotificationService;
use App\Services\ApiRateLimiter;
use Core\Database;
use Core\Logger;

/**
 * SocialTaskService
 *
 * هماهنگ‌کننده اصلی ماژول SocialTask.
 * تنها نقطه ورود برای:
 *   - گرفتن لیست تسک برای Executor
 *   - شروع execution
 *   - ثبت behavior signals
 *   - submit نهایی
 *   - تصمیم‌گیری و پرداخت
 *   - ایجاد آگهی توسط Advertiser
 */
class SocialTaskService
{
    private Database                $db;
    private SocialTaskScoringService $scoring;
    private TrustScoreService       $trust;
    private SilentAntiFraudService  $antiFraud;
    private WalletService           $wallet;
    private NotificationService     $notification;
    private ApiRateLimiter          $rateLimiter;
    private Logger                  $logger;

    // تسک‌های یوتیوب جدا هستند — از این سرویس حذف می‌شوند
    private const EXCLUDED_PLATFORMS_FROM_SOCIAL = ['youtube'];

    // platform → نوع تسک‌های مجاز
    private const PLATFORM_TASK_TYPES = [
        'instagram' => ['follow', 'like', 'comment', 'share'],
        'telegram'  => ['join_channel', 'join_group'],
        'twitter'   => ['follow', 'like', 'retweet', 'comment'],
        'tiktok'    => ['follow', 'like', 'comment', 'share'],
    ];

    // زمان انتظار (ثانیه) برای rate limit per task_type
    private const TASK_EXPECTED_TIME = [
        'follow'       => 45,
        'like'         => 20,
        'comment'      => 90,
        'share'        => 30,
        'retweet'      => 25,
        'join_channel' => 30,
        'join_group'   => 30,
    ];

    public function __construct(
        Database                 $db,
        SocialTaskScoringService $scoring,
        TrustScoreService        $trust,
        SilentAntiFraudService   $antiFraud,
        WalletService            $wallet,
        NotificationService      $notification,
        ApiRateLimiter           $rateLimiter,
        Logger                   $logger
    ) {
        $this->db           = $db;
        $this->scoring      = $scoring;
        $this->trust        = $trust;
        $this->antiFraud    = $antiFraud;
        $this->wallet       = $wallet;
        $this->notification = $notification;
        $this->rateLimiter  = $rateLimiter;
        $this->logger       = $logger;
    }

    // ─────────────────────────────────────────────────────────────
    // Executor — گرفتن تسک‌ها
    // ─────────────────────────────────────────────────────────────

    /**
     * لیست تسک‌های فعال برای کاربر با اعمال فیلتر نامحسوس
     *
     * @param array $filters [platform, task_type, min_reward, max_reward, sort, search]
     */
    public function getTasksForExecutor(int $userId, array $filters = [], int $limit = 20): array
    {
        // سطح محدودیت نامحسوس
        $restriction = $this->antiFraud->getRestrictionLevel($userId);

        // حداکثر تعداد با اعمال restriction
        $effectiveLimit = $this->antiFraud->filterTaskCount($userId, $limit);

        $where  = ["sa.status = 'active'",
                   "sa.remaining_slots > 0",
                   "sa.platform NOT IN ('" . implode("','", self::EXCLUDED_PLATFORMS_FROM_SOCIAL) . "')",
                   // تسک‌هایی که کاربر قبلاً انجام داده یا در صف دارد حذف شوند
                   "NOT EXISTS (
                       SELECT 1 FROM social_task_executions ste
                       WHERE ste.ad_id = sa.id
                         AND ste.executor_id = ?
                         AND ste.status NOT IN ('expired','cancelled')
                   )"];
        $params = [$userId];

        // فیلتر پلتفرم
        if (!empty($filters['platform'])) {
            $where[]  = 'sa.platform = ?';
            $params[] = $filters['platform'];
        }

        // فیلتر نوع تسک
        if (!empty($filters['task_type'])) {
            $where[]  = 'sa.task_type = ?';
            $params[] = $filters['task_type'];
        }

        // فیلتر قیمت
        if (!empty($filters['min_reward'])) {
            $where[]  = 'sa.reward >= ?';
            $params[] = (float)$filters['min_reward'];
        }
        if (!empty($filters['max_reward'])) {
            $where[]  = 'sa.reward <= ?';
            $params[] = (float)$filters['max_reward'];
        }

        // فیلتر Web/Mobile — Cron شبانه median را می‌گذارد
        $medianReward = $this->getMedianReward();
        if (!empty($filters['is_mobile']) && $filters['is_mobile']) {
            // موبایل: همه تسک‌ها
        } else {
            // وب: فقط تسک‌های reward ≤ median
            $where[]  = 'sa.reward <= ?';
            $params[] = $medianReward;
        }

        // جستجو از GlobalSearchService منطق استفاده می‌کنیم (search خودمان)
        if (!empty($filters['search'])) {
            $like     = '%' . $this->sanitizeSearch($filters['search']) . '%';
            $where[]  = '(sa.title LIKE ? OR sa.description LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        // مرتب‌سازی
        $orderBy = match ($filters['sort'] ?? 'random') {
            'price_desc' => 'sa.reward DESC',
            'price_asc'  => 'sa.reward ASC',
            'newest'     => 'sa.created_at DESC',
            default      => 'RAND()', // random
        };

        $whereStr = implode(' AND ', $where);
        $params[] = $effectiveLimit;

        $tasks = $this->db->fetchAll(
            "SELECT sa.*,
                    u.full_name  AS advertiser_name,
                    COALESCE(ut.trust_score, 50) AS advertiser_trust
             FROM social_ads sa
             JOIN users u ON u.id = sa.advertiser_id
             LEFT JOIN social_user_trust ut ON ut.user_id = sa.advertiser_id
             WHERE {$whereStr}
             ORDER BY {$orderBy}
             LIMIT ?",
            $params
        );

        // پاداش واقعی با اعمال restriction نامحسوس
        foreach ($tasks as &$task) {
            $task->display_reward  = $this->antiFraud->adjustedReward($userId, (float)$task->reward);
            $task->trust_display   = $this->trust->get($userId);
        }
        unset($task);

        return [
            'tasks'            => $tasks,
            'restriction_level'=> $restriction['level'], // فقط برای debug داخلی — به view ارسال نمی‌شود
            'trust_score'      => $this->trust->get($userId),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Executor — شروع execution
    // ─────────────────────────────────────────────────────────────

    /**
     * شروع یک execution جدید
     */
    public function startExecution(int $userId, int $adId, array $context = []): array
    {
        $ad = $this->db->fetch(
            "SELECT * FROM social_ads WHERE id = ? AND status = 'active' AND remaining_slots > 0 LIMIT 1",
            [$adId]
        );

        if (!$ad) {
            return ['success' => false, 'message' => 'تسک موجود نیست یا ظرفیت تکمیل شده'];
        }

        // بررسی تکراری نبودن
        $existing = $this->db->fetch(
            "SELECT id FROM social_task_executions
             WHERE ad_id = ? AND executor_id = ? AND status NOT IN ('expired','cancelled') LIMIT 1",
            [$adId, $userId]
        );
        if ($existing) {
            return ['success' => false, 'message' => 'قبلاً این تسک را شروع کرده‌اید'];
        }

        // Rate Limit — استفاده از ApiRateLimiter موجود
        if (!$this->rateLimiter->check('task_submit', $userId, 50, 60)) {
            return ['success' => false, 'message' => 'تعداد تسک در این ساعت به حد مجاز رسیده است'];
        }

        $expectedTime = self::TASK_EXPECTED_TIME[$ad->task_type] ?? 60;

        $execId = $this->db->insert(
            "INSERT INTO social_task_executions
               (ad_id, executor_id, status, ip_address, user_agent, started_at, expected_time, created_at)
             VALUES (?, ?, 'pending', ?, ?, NOW(), ?, NOW())",
            [
                $adId,
                $userId,
                $context['ip'] ?? '',
                $context['user_agent'] ?? '',
                $expectedTime,
            ]
        );

        // کاهش remaining_slots
        $this->db->query(
            "UPDATE social_ads SET remaining_slots = remaining_slots - 1 WHERE id = ?",
            [$adId]
        );

        return [
            'success'       => true,
            'execution_id'  => $execId,
            'expected_time' => $expectedTime,
            'target_url'    => $ad->target_url,
            'task_type'     => $ad->task_type,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Executor — ثبت Behavior Signals (از موبایل/وب)
    // ─────────────────────────────────────────────────────────────

    /**
     * ذخیره سیگنال‌های رفتاری در طول انجام تسک.
     * چند بار قابل فراخوانی است (incremental update).
     */
    public function recordBehaviorSignals(int $executionId, int $userId, array $signals): bool
    {
        $exec = $this->db->fetch(
            "SELECT id FROM social_task_executions WHERE id = ? AND executor_id = ? LIMIT 1",
            [$executionId, $userId]
        );
        if (!$exec) {
            return false;
        }

        // merge با داده قبلی
        $existing = $this->db->fetch(
            "SELECT behavior_data FROM social_task_executions WHERE id = ? LIMIT 1",
            [$executionId]
        );
        $prevData = [];
        if ($existing && $existing->behavior_data) {
            $prevData = json_decode($existing->behavior_data, true) ?? [];
        }

        $merged = $this->mergeBehaviorSignals($prevData, $signals);

        $this->db->query(
            "UPDATE social_task_executions SET behavior_data = ?, updated_at = NOW() WHERE id = ?",
            [json_encode($merged, JSON_UNESCAPED_UNICODE), $executionId]
        );

        return true;
    }

    // ─────────────────────────────────────────────────────────────
    // Executor — Submit نهایی
    // ─────────────────────────────────────────────────────────────

    /**
     * ثبت نهایی execution و تصمیم‌گیری.
     * @param array $submitData [active_time, interactions, behavior_signals, ip, fingerprint, session_id, ...]
     */
    public function submitExecution(int $executionId, int $userId, array $submitData): array
    {
        $exec = $this->db->fetch(
            "SELECT ste.*, sa.reward, sa.task_type, sa.advertiser_id
             FROM social_task_executions ste
             JOIN social_ads sa ON sa.id = ste.ad_id
             WHERE ste.id = ? AND ste.executor_id = ? AND ste.status = 'pending'
             LIMIT 1",
            [$executionId, $userId]
        );

        if (!$exec) {
            return ['success' => false, 'message' => 'execution یافت نشد یا قبلاً ثبت شده'];
        }

        // merge behavior از DB با submitData
        $savedSignals = [];
        if ($exec->behavior_data) {
            $savedSignals = json_decode($exec->behavior_data, true) ?? [];
        }
        $finalSignals = $this->mergeBehaviorSignals($savedSignals, $submitData['behavior_signals'] ?? []);

        // ── ۱. محاسبه امتیازها ──
        $trustModifier = $this->trust->getModifier($userId);
        $scoreResult   = $this->scoring->calculate([
            'active_time'      => $submitData['active_time'] ?? 0,
            'expected_time'    => (int)$exec->expected_time,
            'interactions'     => $submitData['interactions'] ?? [],
            'behavior_signals' => $finalSignals,
            'trust_modifier'   => $trustModifier,
        ]);

        // ── ۲. Risk Score ──
        $riskResult = $this->antiFraud->calculateRiskScore($userId, [
            'ip'          => $submitData['ip'] ?? $exec->ip_address,
            'fingerprint' => $submitData['fingerprint'] ?? '',
            'session_id'  => $submitData['session_id'] ?? '',
        ]);

        // اعمال risk modifier روی task score
        $riskMod    = $this->scoring->riskModifier($riskResult['risk_score']);
        $finalScore = max(0, min(100, $scoreResult['task_score'] + $riskMod));

        // ── ۳. تصمیم نهایی ──
        $decision = $this->antiFraud->decide($userId, $executionId, $finalScore, $riskResult);

        // ── ۴. ذخیره نتیجه ──
        $this->db->query(
            "UPDATE social_task_executions SET
               status           = ?,
               task_score       = ?,
               trust_score      = ?,
               risk_score       = ?,
               decision         = ?,
               decision_reason  = ?,
               time_score       = ?,
               interaction_score= ?,
               behavior_score   = ?,
               behavior_data    = ?,
               active_time      = ?,
               flag_review      = ?,
               completed_at     = NOW(),
               updated_at       = NOW()
             WHERE id = ?",
            [
                $decision['decision'],
                $finalScore,
                $decision['trust_score'],
                $decision['risk_score'],
                $decision['decision'],
                $decision['reason'],
                $scoreResult['time_score'],
                $scoreResult['interaction_score'],
                $scoreResult['behavior_score'],
                json_encode($finalSignals, JSON_UNESCAPED_UNICODE),
                $submitData['active_time'] ?? 0,
                $decision['flag_review'] ? 1 : 0,
                $executionId,
            ]
        );

        // ── ۵. پرداخت ──
        if ($decision['pay_reward']) {
            $reward = $this->antiFraud->adjustedReward($userId, (float)$exec->reward);
            $this->wallet->deposit($userId, $reward, 'irt', [
                'source'       => 'social_task',
                'execution_id' => $executionId,
                'decision'     => $decision['decision'],
            ]);
        }

        // ── ۶. نوتیفیکیشن ──
        $this->sendDecisionNotification($userId, $decision, $exec->task_type);

        return [
            'success'   => true,
            'decision'  => $decision['decision'],
            'task_score'=> $finalScore,
            'paid'      => $decision['pay_reward'],
            'message'   => $this->decisionMessage($decision['decision']),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Advertiser — ایجاد آگهی
    // ─────────────────────────────────────────────────────────────

    /**
     * ایجاد آگهی جدید توسط تبلیغ‌دهنده
     */
    public function createAd(int $advertiserId, array $data): array
    {
        $platform  = $data['platform'] ?? '';
        $taskType  = $data['task_type'] ?? '';
        $reward    = (float)($data['reward'] ?? 0);
        $maxSlots  = (int)($data['max_slots'] ?? 0);
        $totalCost = $reward * $maxSlots;

        // اعتبارسنجی platform — یوتیوب از این ماژول جدا است
        $allowed = array_keys(self::PLATFORM_TASK_TYPES);
        if (!in_array($platform, $allowed, true)) {
            return ['success' => false, 'message' => 'پلتفرم انتخابی در این ماژول مجاز نیست'];
        }

        $allowedTypes = self::PLATFORM_TASK_TYPES[$platform] ?? [];
        if (!in_array($taskType, $allowedTypes, true)) {
            return ['success' => false, 'message' => 'نوع تسک برای این پلتفرم مجاز نیست'];
        }

        if ($reward <= 0 || $maxSlots <= 0) {
            return ['success' => false, 'message' => 'پاداش و تعداد کاربر باید بیشتر از صفر باشد'];
        }

        // کسر هزینه از کیف پول تبلیغ‌دهنده
        try {
            $this->wallet->withdraw($advertiserId, $totalCost, 'irt', [
                'source' => 'social_ad_create',
                'note'   => "ایجاد آگهی {$platform}/{$taskType}",
            ]);
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'موجودی کافی نیست'];
        }

        // ذخیره آگهی — comment_templates به صورت JSON
        $commentTemplates = isset($data['comment_templates']) && is_array($data['comment_templates'])
            ? json_encode($data['comment_templates'], JSON_UNESCAPED_UNICODE)
            : null;

        $adId = $this->db->insert(
            "INSERT INTO social_ads
               (advertiser_id, platform, task_type, title, description,
                target_url, target_username, reward, max_slots,
                remaining_slots, allow_copy_paste, comment_templates,
                status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_review', NOW())",
            [
                $advertiserId,
                $platform,
                $taskType,
                trim($data['title'] ?? ''),
                trim($data['description'] ?? ''),
                trim($data['target_url'] ?? ''),
                trim($data['target_username'] ?? ''),
                $reward,
                $maxSlots,
                $maxSlots,
                isset($data['allow_copy_paste']) ? 1 : 0,
                $commentTemplates,
            ]
        );

        return ['success' => true, 'ad_id' => $adId];
    }

    // ─────────────────────────────────────────────────────────────
    // Advertiser — تأیید/رد دستی execution
    // ─────────────────────────────────────────────────────────────

    public function advertiserApprove(int $advertiserId, int $executionId): array
    {
        $exec = $this->getExecutionForAdvertiser($advertiserId, $executionId);
        if (!$exec) {
            return ['success' => false, 'message' => 'دسترسی مجاز نیست'];
        }

        $this->db->query(
            "UPDATE social_task_executions SET status = 'approved', updated_at = NOW() WHERE id = ?",
            [$executionId]
        );

        return ['success' => true, 'message' => 'اجرا تأیید شد'];
    }

    public function advertiserReject(int $advertiserId, int $executionId, string $reason): array
    {
        if (empty(trim($reason))) {
            return ['success' => false, 'message' => 'دلیل رد الزامی است'];
        }

        $exec = $this->getExecutionForAdvertiser($advertiserId, $executionId);
        if (!$exec) {
            return ['success' => false, 'message' => 'دسترسی مجاز نیست'];
        }

        $this->db->query(
            "UPDATE social_task_executions SET status = 'rejected', reject_reason = ?, updated_at = NOW() WHERE id = ?",
            [$reason, $executionId]
        );

        // Trust penalty برای executor
        $this->trust->penalizeRejection((int)$exec->executor_id, $executionId);

        return ['success' => true, 'message' => 'اجرا رد شد'];
    }

    // ─────────────────────────────────────────────────────────────
    // آمار و گزارش
    // ─────────────────────────────────────────────────────────────

    public function getExecutorStats(int $userId): object
    {
        return $this->db->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(decision = 'approved') AS approved,
                SUM(decision = 'soft_approved') AS soft_approved,
                SUM(decision = 'rejected') AS rejected,
                AVG(task_score) AS avg_score,
                SUM(CASE WHEN decision IN ('approved','soft_approved') THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*),0) AS success_rate
             FROM social_task_executions
             WHERE executor_id = ?",
            [$userId]
        ) ?: (object)['total' => 0, 'approved' => 0, 'soft_approved' => 0, 'rejected' => 0, 'avg_score' => 0, 'success_rate' => 0];
    }

    public function getAdvertiserAdStats(int $advertiserId, int $adId): ?object
    {
        return $this->db->fetch(
            "SELECT
                sa.*,
                COUNT(ste.id) AS total_executions,
                SUM(ste.decision = 'approved') AS approved,
                SUM(ste.decision = 'soft_approved') AS soft_approved,
                SUM(ste.decision = 'rejected') AS rejected,
                AVG(ste.task_score) AS avg_score,
                AVG(ste.active_time) AS avg_time
             FROM social_ads sa
             LEFT JOIN social_task_executions ste ON ste.ad_id = sa.id
             WHERE sa.id = ? AND sa.advertiser_id = ?
             GROUP BY sa.id
             LIMIT 1",
            [$adId, $advertiserId]
        );
    }

    public function getExecutorHistory(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT ste.*, sa.title, sa.platform, sa.task_type, sa.reward
             FROM social_task_executions ste
             JOIN social_ads sa ON sa.id = ste.ad_id
             WHERE ste.executor_id = ?
             ORDER BY ste.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        ) ?: [];
    }

    // ─────────────────────────────────────────────────────────────
    // Cron — Web/Mobile Split (فراخوانی شبانه از Scheduler)
    // ─────────────────────────────────────────────────────────────

    /**
     * median reward را محاسبه و ذخیره می‌کند.
     * تسک‌های reward > median فقط در موبایل نمایش داده می‌شوند.
     */
    public function updateMedianReward(): float
    {
        $result = $this->db->fetch(
            "SELECT AVG(reward) AS median_reward
             FROM (
                 SELECT reward
                 FROM social_ads
                 WHERE status = 'active'
                 ORDER BY reward
                 LIMIT 2 OFFSET (SELECT FLOOR(COUNT(*)/2) FROM social_ads WHERE status = 'active')
             ) t"
        );
        $median = (float)($result ? $result->median_reward : 0);

        // ذخیره در cache/settings
        $this->db->query(
            "INSERT INTO social_task_settings (key_name, value, updated_at)
             VALUES ('median_reward', ?, NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()",
            [(string)$median]
        );

        return $median;
    }

    // ─────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────

    private function getMedianReward(): float
    {
        $row = $this->db->fetch(
            "SELECT value FROM social_task_settings WHERE key_name = 'median_reward' LIMIT 1"
        );
        return $row ? (float)$row->value : 100.0;
    }

    private function getExecutionForAdvertiser(int $advertiserId, int $executionId): ?object
    {
        return $this->db->fetch(
            "SELECT ste.*
             FROM social_task_executions ste
             JOIN social_ads sa ON sa.id = ste.ad_id
             WHERE ste.id = ? AND sa.advertiser_id = ?
             LIMIT 1",
            [$executionId, $advertiserId]
        ) ?: null;
    }

    private function mergeBehaviorSignals(array $prev, array $new): array
    {
        // فیلدهای عددی additive (مثل tap_count)
        $additive = ['tap_count', 'swipe_count', 'scroll_count', 'touch_pauses',
                     'scroll_pauses', 'reconnect_count', 'hesitation_count',
                     'natural_delay_count', 'app_blur_count'];

        foreach ($additive as $key) {
            if (isset($new[$key])) {
                $prev[$key] = ((int)($prev[$key] ?? 0)) + (int)$new[$key];
            }
        }

        // فیلدهای override (مثل variance که آخرین مقدار معتبر است)
        $override = ['touch_timing_variance', 'scroll_speed_variance',
                     'session_duration', 'active_time',
                     'max_blur_duration', 'avg_action_delay_ms'];
        foreach ($override as $key) {
            if (isset($new[$key])) {
                $prev[$key] = $new[$key];
            }
        }

        return $prev;
    }

    private function sendDecisionNotification(int $userId, array $decision, string $taskType): void
    {
        $titleMap = [
            'approved'      => 'تسک تأیید شد',
            'soft_approved' => 'تسک در انتظار بررسی',
            'rejected'      => 'تسک رد شد',
        ];

        $this->notification->send(
            $userId,
            'task_result',
            $titleMap[$decision['decision']] ?? 'نتیجه تسک',
            $this->decisionMessage($decision['decision']),
            ['decision' => $decision['decision'], 'task_type' => $taskType]
        );
    }

    private function decisionMessage(string $decision): string
    {
        return match ($decision) {
            'approved'      => 'تسک با موفقیت تأیید شد و پاداش واریز گردید.',
            'soft_approved' => 'تسک دریافت شد. پاداش واریز شده ولی در انتظار تأیید نهایی است.',
            'rejected'      => 'تسک تأیید نشد. لطفاً دستورالعمل‌ها را با دقت بیشتری دنبال کنید.',
            default         => 'نتیجه تسک پردازش شد.',
        };
    }

    private function sanitizeSearch(string $q): string
    {
        return preg_replace('/[%_\\\\]/', '\\\\$0', mb_substr(trim($q), 0, 100));
    }
}
