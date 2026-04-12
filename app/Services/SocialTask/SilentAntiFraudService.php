<?php

namespace App\Services\SocialTask;

use App\Services\AntiFraud\IPQualityService;
use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AntiFraud\SessionAnomalyService;
use App\Services\AuditTrail;
use App\Services\NotificationService;
use Core\Database;

/**
 * SilentAntiFraudService
 *
 * تصمیم‌گیری نامحسوس (Silent Anti-Fraud):
 *   - کاربر مشکوک ban نمی‌شود
 *   - به صورت خاموش محدود می‌شود
 *
 * سه سطح:
 *   HIGH   → 10% tasks, 50% reward, سخت‌ترین scoring
 *   MEDIUM → 30% tasks, 70% reward
 *   LOW    → 60% tasks, 90% reward
 *   CLEAN  → 100% tasks, 100% reward
 *
 * Decision Engine (تنها مرجع تصمیم):
 *   score ≥70 + trust ≥60 + risk <30              → approved
 *   score ≥70 + (low_trust OR high_risk)           → soft_approved
 *   score 40–69                                    → soft_approved
 *   score <40                                      → rejected
 */
class SilentAntiFraudService
{
    private const RESTRICTION_LEVELS = [
        'high'   => ['task_ratio' => 0.10, 'reward_ratio' => 0.50],
        'medium' => ['task_ratio' => 0.30, 'reward_ratio' => 0.70],
        'low'    => ['task_ratio' => 0.60, 'reward_ratio' => 0.90],
        'clean'  => ['task_ratio' => 1.00, 'reward_ratio' => 1.00],
    ];

    private Database                 $db;
    private IPQualityService         $ipService;
    private BrowserFingerprintService $fingerprintService;
    private SessionAnomalyService    $sessionService;
    private TrustScoreService        $trustService;
    private SocialTaskScoringService $scoringService;
    private AuditTrail               $audit;
    private NotificationService      $notificationService;

    public function __construct(
        Database                  $db,
        IPQualityService          $ipService,
        BrowserFingerprintService $fingerprintService,
        SessionAnomalyService     $sessionService,
        TrustScoreService         $trustService,
        SocialTaskScoringService  $scoringService,
        AuditTrail                $audit,
        NotificationService       $notificationService
    ) {
        $this->db                  = $db;
        $this->ipService           = $ipService;
        $this->fingerprintService  = $fingerprintService;
        $this->sessionService      = $sessionService;
        $this->trustService        = $trustService;
        $this->scoringService      = $scoringService;
        $this->audit               = $audit;
        $this->notificationService = $notificationService;
    }

    // ─────────────────────────────────────────────────────────────
    // محاسبه Risk Score (0–100)
    // ─────────────────────────────────────────────────────────────

    /**
     * Risk Score ترکیبی از:
     *   - IP reputation (IPQualityService موجود)
     *   - Device fingerprint (BrowserFingerprintService موجود)
     *   - Session anomaly (SessionAnomalyService موجود)
     *   - Multi-account detection
     */
    public function calculateRiskScore(int $userId, array $context = []): array
    {
        $ip          = $context['ip'] ?? '';
        $sessionId   = $context['session_id'] ?? '';
        $fingerprint = $context['fingerprint'] ?? '';

        $components = [];
        $totalScore = 0;

        // ۱. IP Quality — از سرویس موجود
        if ($ip) {
            $ipResult      = $this->ipService->check($ip);
            $ipScore       = (int)$ipResult['score'];
            $components['ip'] = [
                'score'   => $ipScore,
                'reasons' => $ipResult['reasons'] ?? [],
            ];
            $totalScore += $ipScore * 0.35; // وزن IP: 35%
        }

        // ۲. Session Anomaly — از سرویس موجود
        if ($sessionId) {
            $sessionResult      = $this->sessionService->analyze($userId, $sessionId);
            $sessionScore       = (int)$sessionResult['score'];
            $components['session'] = [
                'score'    => $sessionScore,
                'anomalies'=> $sessionResult['anomalies'] ?? [],
            ];
            $totalScore += $sessionScore * 0.25; // وزن session: 25%
        }

        // ۳. Multi-Account Detection
        $multiResult      = $this->detectMultiAccount($userId, $ip, $fingerprint);
        $components['multi_account'] = $multiResult;
        $totalScore += $multiResult['score'] * 0.25; // وزن multi-account: 25%

        // ۴. Pattern Anomaly — تسک‌های سریع پشت‌سر‌هم
        $patternResult      = $this->detectPatternAnomaly($userId);
        $components['pattern'] = $patternResult;
        $totalScore += $patternResult['score'] * 0.15; // وزن pattern: 15%

        $finalScore = (int)min(100, $totalScore);

        return [
            'risk_score'  => $finalScore,
            'components'  => $components,
            'is_high_risk'=> $finalScore > 50,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Decision Engine — تصمیم نهایی (تنها مرجع)
    // ─────────────────────────────────────────────────────────────

    /**
     * تصمیم نهایی برای یک execution
     *
     * @return array [
     *   'decision'    => 'approved'|'soft_approved'|'rejected'
     *   'task_score'  => float
     *   'trust_score' => float
     *   'risk_score'  => int
     *   'reason'      => string
     *   'pay_reward'  => bool    (آیا پرداخت شود)
     *   'give_score'  => bool    (آیا امتیاز مثبت داده شود)
     *   'flag_review' => bool    (آیا برای بررسی فلگ شود)
     *   'audit_data'  => array
     * ]
     */
    public function decide(
        int   $userId,
        int   $executionId,
        float $taskScore,
        array $riskResult
    ): array {
        $trustScore = $this->trustService->get($userId);
        $riskScore  = $riskResult['risk_score'];

        // ── منطق تصمیم ──
        if ($taskScore >= 70 && $trustScore >= 60 && $riskScore < 30) {
            $decision   = 'approved';
            $payReward  = true;
            $giveScore  = true;
            $flagReview = false;
            $reason     = 'score_trust_risk_all_good';

        } elseif ($taskScore >= 70) {
            // امتیاز خوب ولی trust پایین یا risk بالا
            $decision   = 'soft_approved';
            $payReward  = true;
            $giveScore  = true; // امتیاز کمتر (توضیح در پایین)
            $flagReview = false;
            $reason     = $trustScore < 60 ? 'low_trust' : 'high_risk';

        } elseif ($taskScore >= 40) {
            $decision   = 'soft_approved';
            $payReward  = true;
            $giveScore  = false; // پول واریز، بدون امتیاز
            $flagReview = $taskScore < 50; // خیلی نزدیک به خط رد → فلگ
            $reason     = 'borderline_score';

        } else {
            // score < 40 → رد
            $decision   = 'rejected';
            $payReward  = false;
            $giveScore  = false;
            $flagReview = $taskScore < 20; // خیلی بد → حتماً فلگ
            $reason     = 'low_score';
        }

        // به‌روزرسانی Trust Score
        if ($decision === 'approved') {
            $this->trustService->rewardGoodTask($userId, $executionId);
        } elseif ($decision === 'rejected') {
            $this->trustService->penalizeRejection($userId, $executionId);
        }

        // ثبت در Audit (از AuditTrail موجود)
        AuditTrail::record(
            $decision === 'approved' ? AuditTrail::TASK_EXECUTION_APPROVED : AuditTrail::TASK_EXECUTION_REJECTED,
            $userId,
            [
                'execution_id' => $executionId,
                'task_score'   => $taskScore,
                'trust_score'  => $trustScore,
                'risk_score'   => $riskScore,
                'decision'     => $decision,
                'reason'       => $reason,
            ]
        );

        $auditData = [
            'task_score'  => $taskScore,
            'trust_score' => $trustScore,
            'risk_score'  => $riskScore,
            'decision'    => $decision,
            'reason'      => $reason,
            'risk_detail' => $riskResult['components'] ?? [],
        ];

        return [
            'decision'    => $decision,
            'task_score'  => $taskScore,
            'trust_score' => $trustScore,
            'risk_score'  => $riskScore,
            'reason'      => $reason,
            'pay_reward'  => $payReward,
            'give_score'  => $giveScore,
            'flag_review' => $flagReview,
            'audit_data'  => $auditData,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // سطح محدودیت نامحسوس کاربر
    // ─────────────────────────────────────────────────────────────

    /**
     * سطح محدودیت نامحسوس کاربر را برمی‌گرداند.
     * این اطلاعات به کاربر نشان داده نمی‌شود.
     */
    public function getRestrictionLevel(int $userId): array
    {
        $trustScore = $this->trustService->get($userId);

        // Trust Score مستقیماً تعیین‌کننده سطح است
        if ($trustScore < 20) {
            $level = 'high';
        } elseif ($trustScore < 40) {
            $level = 'medium';
        } elseif ($trustScore < 60) {
            $level = 'low';
        } else {
            $level = 'clean';
        }

        return array_merge(
            ['level' => $level, 'trust_score' => $trustScore],
            self::RESTRICTION_LEVELS[$level]
        );
    }

    /**
     * تعداد تسک‌های قابل نمایش بر اساس سطح محدودیت
     */
    public function filterTaskCount(int $userId, int $available): int
    {
        $restriction = $this->getRestrictionLevel($userId);
        return (int)ceil($available * $restriction['task_ratio']);
    }

    /**
     * پاداش واقعی پس از اعمال محدودیت نامحسوس
     */
    public function adjustedReward(int $userId, float $originalReward): float
    {
        $restriction = $this->getRestrictionLevel($userId);
        return round($originalReward * $restriction['reward_ratio'], 2);
    }

    // ─────────────────────────────────────────────────────────────
    // تشخیص چند حساب (Multi-Account)
    // ─────────────────────────────────────────────────────────────

    private function detectMultiAccount(int $userId, string $ip, string $fingerprint): array
    {
        $score   = 0;
        $reasons = [];

        // بررسی IP مشترک
        if ($ip) {
            $ipUsers = $this->db->fetch(
                "SELECT COUNT(DISTINCT executor_id) AS cnt
                 FROM social_task_executions
                 WHERE ip_address = ?
                   AND executor_id != ?
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                [$ip, $userId]
            );
            $count = (int)($ipUsers ? $ipUsers->cnt : 0);
            if ($count >= 5) {
                $score  += 60;
                $reasons[] = "IP مشترک با {$count} کاربر دیگر";
            } elseif ($count >= 2) {
                $score  += 30;
                $reasons[] = "IP مشترک با {$count} کاربر دیگر";
            }
        }

        // بررسی fingerprint مشترک
        if ($fingerprint) {
            $fpUsers = $this->db->fetch(
                "SELECT COUNT(DISTINCT user_id) AS cnt
                 FROM user_fingerprints
                 WHERE fingerprint = ?
                   AND user_id != ?",
                [$fingerprint, $userId]
            );
            $fpCount = (int)($fpUsers ? $fpUsers->cnt : 0);
            if ($fpCount >= 1) {
                $score  += 50;
                $reasons[] = "Device fingerprint با {$fpCount} حساب دیگر مشترک است";
            }
        }

        return [
            'score'   => min(100, $score),
            'reasons' => $reasons,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // تشخیص الگوی سریع (Pattern Anomaly)
    // ─────────────────────────────────────────────────────────────

    private function detectPatternAnomaly(int $userId): array
    {
        $score   = 0;
        $reasons = [];

        // 5 تسک سریع پشت‌سر‌هم در 10 دقیقه → pattern rule
        $recent = $this->db->fetch(
            "SELECT COUNT(*) AS cnt,
                    AVG(active_time) AS avg_time,
                    STDDEV(active_time) AS stddev_time
             FROM social_task_executions
             WHERE executor_id = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
            [$userId]
        );

        $cnt       = (int)($recent ? $recent->cnt : 0);
        $stddev    = (float)($recent ? $recent->stddev_time : 999);
        $avgTime   = (float)($recent ? $recent->avg_time : 0);

        if ($cnt >= 5) {
            $score  += 40;
            $reasons[] = "{$cnt} تسک در ۱۰ دقیقه اخیر";

            // زمان‌های ثابت ریاضی (stddev خیلی کم)
            if ($stddev < 2 && $avgTime > 0) {
                $score  += 30;
                $reasons[] = 'زمان‌های انجام یکسان (الگوی Bot)';
            }
        }

        // Trust penalty برای pattern rule
        if ($cnt >= 5) {
            $this->trustService->penalizeSuspicious($userId, 'rapid_task_pattern');
        }

        return [
            'score'   => min(100, $score),
            'reasons' => $reasons,
            'details' => ['tasks_in_10min' => $cnt],
        ];
    }
}
