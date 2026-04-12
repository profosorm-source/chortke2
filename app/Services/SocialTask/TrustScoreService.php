<?php

namespace App\Services\SocialTask;

use App\Services\UserScoreService;
use Core\Database;

/**
 * TrustScoreService
 *
 * مدیریت Trust Score در ماژول SocialTask.
 * - مقدار اولیه: 50
 * - بازه: 0–100
 * - هیچ reset کامل وجود ندارد
 * - تغییرات بر اساس «روند» است، نه یک رویداد
 *
 * از UserScoreService موجود برای ثبت event استفاده می‌کند.
 */
class TrustScoreService
{
    private const DOMAIN       = 'social_trust';
    private const INITIAL      = 50;
    private const MIN          = 0;
    private const MAX          = 100;

    // تغییرات مثبت
    private const INC_GOOD_TASK        = 2;   // تسک با score ≥ 70
    private const INC_NATURAL_BEHAVIOR = 1;   // رفتار طبیعی پایدار
    private const INC_HEALTHY_WEEK     = 2;   // 7 روز سالم (حداقل 5 تسک ≥70، بدون reject)

    // جریمه‌ها
    private const DEC_REJECTED         = -5;
    private const DEC_SUSPICIOUS       = -3;
    private const DEC_SOFT_EXCESS      = -2;  // soft_approved زیاد در 7 روز (3+)
    private const DEC_CONFIRMED_FRAUD  = -10;

    private Database       $db;
    private UserScoreService $scoreService;

    public function __construct(Database $db, UserScoreService $scoreService)
    {
        $this->db           = $db;
        $this->scoreService = $scoreService;
    }

    // ─────────────────────────────────────────────────────────────
    // خواندن Trust Score
    // ─────────────────────────────────────────────────────────────

    /**
     * Trust Score فعلی کاربر (0–100)
     * اگر هنوز رکورد ندارد، مقدار اولیه 50 برمی‌گردد و ذخیره می‌شود.
     */
    public function get(int $userId): float
    {
        $row = $this->db->fetch(
            'SELECT trust_score FROM social_user_trust WHERE user_id = ? LIMIT 1',
            [$userId]
        );

        if (!$row) {
            $this->initialize($userId);
            return self::INITIAL;
        }

        return $this->clamp((float)$row->trust_score);
    }

    /**
     * Trust Modifier برای محاسبه Task Score (محدوده -10 تا +10)
     */
    public function getModifier(int $userId): float
    {
        $trust = $this->get($userId);

        if ($trust >= 80) return 10.0;
        if ($trust >= 60) return 5.0;
        if ($trust >= 40) return 0.0;
        if ($trust >= 20) return -5.0;
        return -10.0;
    }

    // ─────────────────────────────────────────────────────────────
    // تغییرات Trust Score
    // ─────────────────────────────────────────────────────────────

    /**
     * پس از تأیید تسک با امتیاز خوب (score ≥ 70)
     */
    public function rewardGoodTask(int $userId, int $executionId): void
    {
        $this->apply($userId, self::INC_GOOD_TASK, 'good_task', [
            'execution_id' => $executionId,
        ]);
    }

    /**
     * جریمه بعد از reject
     */
    public function penalizeRejection(int $userId, int $executionId): void
    {
        $this->apply($userId, self::DEC_REJECTED, 'rejection', [
            'execution_id' => $executionId,
        ]);
    }

    /**
     * جریمه رفتار مشکوک (از SilentAntiFraud فراخوانی می‌شود)
     */
    public function penalizeSuspicious(int $userId, string $reason): void
    {
        $this->apply($userId, self::DEC_SUSPICIOUS, 'suspicious_behavior', [
            'reason' => $reason,
        ]);
    }

    /**
     * جریمه soft_approved زیاد
     */
    public function penalizeSoftExcess(int $userId): void
    {
        $this->apply($userId, self::DEC_SOFT_EXCESS, 'soft_approved_excess', []);
    }

    /**
     * جریمه تقلب تأیید شده
     */
    public function penalizeConfirmedFraud(int $userId, string $reason): void
    {
        $this->apply($userId, self::DEC_CONFIRMED_FRAUD, 'confirmed_fraud', [
            'reason' => $reason,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Cron — بهبود هفتگی (فراخوانی از cron.php)
    // ─────────────────────────────────────────────────────────────

    /**
     * اجرا می‌شود توسط Scheduler هر شب.
     * اگر کاربر در 7 روز اخیر:
     *   - هیچ reject نداشت
     *   - حداقل 5 تسک با score ≥ 70 داشت
     * → trust += INC_HEALTHY_WEEK
     *
     * @return array آمار کاربران پردازش‌شده
     */
    public function processWeeklyRecovery(): array
    {
        $updated = 0;
        $checked = 0;

        // کاربرانی که در 7 روز اخیر حداقل یک تسک داشتند
        $users = $this->db->fetchAll(
            "SELECT DISTINCT executor_id AS user_id
             FROM social_task_executions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        foreach ($users as $row) {
            $userId = (int)(is_array($row) ? $row['user_id'] : $row->user_id);
            $checked++;

            $stats = $this->getWeeklyStats($userId);

            // شرط بهبود: بدون reject + حداقل 5 تسک خوب
            if ($stats['rejected'] === 0 && $stats['good_tasks'] >= 5) {
                $this->apply($userId, self::INC_HEALTHY_WEEK, 'weekly_recovery', $stats);
                $updated++;
            }

            // شرط کاهش برای soft_approved زیاد
            if ($stats['soft_approved'] >= 3) {
                $this->penalizeSoftExcess($userId);
            }

            // snapshot ذخیره شود
            $this->saveSnapshot($userId);
        }

        return ['checked' => $checked, 'updated' => $updated];
    }

    /**
     * آمار 7 روز اخیر یک کاربر
     */
    public function getWeeklyStats(int $userId): array
    {
        $row = $this->db->fetch(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN decision = 'approved' AND task_score >= 70 THEN 1 ELSE 0 END) AS good_tasks,
                SUM(CASE WHEN decision = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                SUM(CASE WHEN decision = 'soft_approved' THEN 1 ELSE 0 END) AS soft_approved,
                AVG(task_score) AS avg_score
             FROM social_task_executions
             WHERE executor_id = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$userId]
        );

        if (!$row) {
            return ['total' => 0, 'good_tasks' => 0, 'rejected' => 0, 'soft_approved' => 0, 'avg_score' => 0];
        }

        return [
            'total'        => (int)$row->total,
            'good_tasks'   => (int)$row->good_tasks,
            'rejected'     => (int)$row->rejected,
            'soft_approved'=> (int)$row->soft_approved,
            'avg_score'    => round((float)$row->avg_score, 1),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * اعمال delta روی trust score و ثبت event
     */
    private function apply(int $userId, float $delta, string $source, array $meta): void
    {
        $current = $this->get($userId);
        $newVal  = $this->clamp($current + $delta);

        // ذخیره در جدول اختصاصی trust
        $this->db->query(
            "INSERT INTO social_user_trust (user_id, trust_score, updated_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               trust_score = ?,
               updated_at  = NOW()",
            [$userId, $newVal, $newVal]
        );

        // ثبت event از طریق UserScoreService برای audit
        $this->scoreService->applyEventDelta(
            $userId,
            self::DOMAIN,
            $delta,
            $source,
            array_merge($meta, [
                'old_trust' => $current,
                'new_trust' => $newVal,
            ])
        );
    }

    private function initialize(int $userId): void
    {
        $this->db->query(
            "INSERT IGNORE INTO social_user_trust (user_id, trust_score, created_at, updated_at)
             VALUES (?, ?, NOW(), NOW())",
            [$userId, self::INITIAL]
        );
    }

    private function saveSnapshot(int $userId): void
    {
        $trust = $this->get($userId);
        $stats = $this->getWeeklyStats($userId);

        $this->db->query(
            "INSERT INTO social_trust_snapshots
               (user_id, trust_score, week_good_tasks, week_rejected, week_soft, snapshot_date)
             VALUES (?, ?, ?, ?, ?, CURDATE())
             ON DUPLICATE KEY UPDATE
               trust_score     = VALUES(trust_score),
               week_good_tasks = VALUES(week_good_tasks),
               week_rejected   = VALUES(week_rejected),
               week_soft       = VALUES(week_soft)",
            [$userId, $trust, $stats['good_tasks'], $stats['rejected'], $stats['soft_approved']]
        );
    }

    private function clamp(float $val): float
    {
        return max(self::MIN, min(self::MAX, $val));
    }
}
