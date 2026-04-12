<?php

namespace App\Services\SocialTask;

/**
 * SocialTaskScoringService
 *
 * تنها مرجع محاسبه امتیاز تسک. هیچ تصمیمی در Web/Mobile گرفته نمی‌شود.
 *
 * فرمول:
 *   Task Score = (time_score × 0.30) + (interaction_score × 0.25)
 *              + (behavior_score × 0.20) + trust_modifier
 *
 * trust_modifier: محدود بین -10 تا +10
 */
class SocialTaskScoringService
{
    // ─────────────────────────────────────────────────────────────
    // Task Score نهایی
    // ─────────────────────────────────────────────────────────────

    /**
     * محاسبه Task Score کامل
     *
     * @param array $data [
     *   'active_time'      => int (ثانیه — فقط زمان فعال)
     *   'expected_time'    => int (ثانیه — زمان انتظار برای تسک)
     *   'interactions'     => array ['scroll','click','tap',...]
     *   'behavior_signals' => array (از موبایل/وب)
     *   'trust_modifier'   => float (-10 تا +10)
     * ]
     * @return array [
     *   'task_score'        => float (0–100+)
     *   'time_score'        => int
     *   'interaction_score' => int
     *   'behavior_score'    => int
     *   'trust_modifier'    => float
     *   'penalties'         => array
     *   'breakdown'         => array
     * ]
     */
    public function calculate(array $data): array
    {
        $timeScore        = $this->calculateTimeScore(
            (int)($data['active_time'] ?? 0),
            (int)($data['expected_time'] ?? 60)
        );
        $interactionScore = $this->calculateInteractionScore(
            (array)($data['interactions'] ?? [])
        );
        $behaviorScore    = $this->calculateBehaviorScore(
            (array)($data['behavior_signals'] ?? [])
        );
        $trustModifier    = $this->clamp((float)($data['trust_modifier'] ?? 0), -10, 10);

        // جریمه‌های Anti-Fraud فوری
        $penalties = $this->calculatePenalties($data, $interactionScore);
        $penaltySum = array_sum(array_column($penalties, 'value'));

        $rawScore = ($timeScore * 0.30)
            + ($interactionScore * 0.25)
            + ($behaviorScore * 0.20)
            + $trustModifier
            + $penaltySum;

        $taskScore = $this->clamp($rawScore, 0, 100);

        return [
            'task_score'        => round($taskScore, 1),
            'time_score'        => $timeScore,
            'interaction_score' => $interactionScore,
            'behavior_score'    => $behaviorScore,
            'trust_modifier'    => $trustModifier,
            'penalties'         => $penalties,
            'breakdown'         => [
                'time_contribution'        => round($timeScore * 0.30, 1),
                'interaction_contribution' => round($interactionScore * 0.25, 1),
                'behavior_contribution'    => round($behaviorScore * 0.20, 1),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Time Score (0–100) — فقط زمان فعال
    // ─────────────────────────────────────────────────────────────

    public function calculateTimeScore(int $activeTime, int $expectedTime): int
    {
        if ($expectedTime <= 0) {
            return 0;
        }

        $ratio = $activeTime / $expectedTime;

        if ($ratio >= 1.0)  return 100;
        if ($ratio >= 0.70) return 70;
        if ($ratio >= 0.40) return 40;
        return 10;
    }

    // ─────────────────────────────────────────────────────────────
    // Interaction Score (0–25)
    // ─────────────────────────────────────────────────────────────

    public function calculateInteractionScore(array $interactions): int
    {
        $types = array_unique($interactions);
        $count = count($types);

        // scroll + click + tap ترکیبی → 25
        $hasScroll = in_array('scroll', $types, true);
        $hasClick  = in_array('click', $types, true);
        $hasTap    = in_array('tap', $types, true);

        if ($hasScroll && $hasClick && $hasTap) return 25;
        if ($count >= 2)                        return 20;
        if ($count === 1)                       return 10;
        return 0;
    }

    // ─────────────────────────────────────────────────────────────
    // Behavior Score (0–100) — 5 بخش، هر بخش 0–20
    // ─────────────────────────────────────────────────────────────

    public function calculateBehaviorScore(array $signals): int
    {
        $touch   = $this->scoreTouchBehavior($signals);
        $scroll  = $this->scoreScrollBehavior($signals);
        $session = $this->scoreSessionIntegrity($signals);
        $focus   = $this->scoreFocusBehavior($signals);
        $micro   = $this->scoreMicroBehavior($signals);

        return min(100, $touch + $scroll + $session + $focus + $micro);
    }

    /**
     * Touch Behavior (0–20)
     */
    public function scoreTouchBehavior(array $s): int
    {
        $tapCount   = (int)($s['tap_count'] ?? 0);
        $swipeCount = (int)($s['swipe_count'] ?? 0);
        $pauseCount = (int)($s['touch_pauses'] ?? 0);
        $variance   = (float)($s['touch_timing_variance'] ?? 0);

        // رباتی: الگوی تکراری دقیق
        if ($variance < 5 && $tapCount > 5) return 0;
        // یکنواخت
        if ($swipeCount === 0 && $pauseCount === 0) return 5;
        // ساده ولی قابل قبول
        if ($swipeCount > 0 && $pauseCount === 0)  return 10;
        // نسبتاً طبیعی
        if ($swipeCount > 0 && $pauseCount > 0 && $variance < 50)  return 15;
        // طبیعی: tap + swipe + مکث
        if ($swipeCount > 0 && $pauseCount > 2 && $variance >= 50) return 20;

        return 10;
    }

    /**
     * Scroll Behavior (0–20)
     */
    public function scoreScrollBehavior(array $s): int
    {
        $scrollCount    = (int)($s['scroll_count'] ?? 0);
        $scrollVariance = (float)($s['scroll_speed_variance'] ?? 0);
        $scrollPauses   = (int)($s['scroll_pauses'] ?? 0);

        if ($scrollCount === 0)                              return 0;
        if ($scrollVariance < 5)                             return 5;  // خطی
        if ($scrollVariance < 20 && $scrollPauses === 0)    return 10;
        if ($scrollVariance >= 20 && $scrollPauses === 0)   return 15;
        if ($scrollVariance >= 20 && $scrollPauses > 0)     return 20;

        return 10;
    }

    /**
     * Session Integrity (0–20)
     */
    public function scoreSessionIntegrity(array $s): int
    {
        $totalTime  = (int)($s['session_duration'] ?? 0);
        $activeTime = (int)($s['active_time'] ?? 0);
        $reconnects = (int)($s['reconnect_count'] ?? 0);

        if ($totalTime <= 0) return 0;

        $activeRatio = $activeTime / $totalTime;

        if ($reconnects > 3)       return 5;  // قطع و وصل
        if ($activeRatio < 0.40)   return 10; // idle زیاد
        if ($activeRatio < 0.70)   return 15; // کمی idle
        if ($activeRatio >= 0.70)  return 20; // session کامل

        return 10;
    }

    /**
     * Focus Behavior (0–20)
     */
    public function scoreFocusBehavior(array $s): int
    {
        $outFocusCount   = (int)($s['app_blur_count'] ?? 0);
        $maxOutFocusSecs = (int)($s['max_blur_duration'] ?? 0);

        if ($outFocusCount === 0)                            return 20; // کامل داخل تسک
        if ($maxOutFocusSecs < 3 && $outFocusCount <= 2)    return 15; // خروج کوتاه
        if ($outFocusCount <= 4)                             return 10; // چند بار خروج
        if ($maxOutFocusSecs > 10)                           return 5;  // خروج طولانی
        return 0;
    }

    /**
     * Micro Behavior (0–20)
     */
    public function scoreMicroBehavior(array $s): int
    {
        $hesitationCount = (int)($s['hesitation_count'] ?? 0);
        $avgActionDelay  = (float)($s['avg_action_delay_ms'] ?? 0); // میلی‌ثانیه
        $naturalDelays   = (int)($s['natural_delay_count'] ?? 0);

        if ($avgActionDelay < 50 && $hesitationCount === 0) return 0;  // فوری و رباتی
        if ($avgActionDelay < 150)                          return 5;  // خیلی سریع
        if ($avgActionDelay < 300 && $naturalDelays === 0)  return 10; // سریع
        if ($avgActionDelay >= 300 && $hesitationCount > 0) return 15;
        if ($avgActionDelay >= 500 && $hesitationCount > 2) return 20; // مکث طبیعی + hesitation

        return 10;
    }

    // ─────────────────────────────────────────────────────────────
    // Anti-Fraud Immediate Penalties
    // ─────────────────────────────────────────────────────────────

    /**
     * جریمه‌های فوری Anti-Fraud
     * @return array [[rule, value, reason], ...]
     */
    public function calculatePenalties(array $data, int $interactionScore): array
    {
        $penalties = [];

        // no_interaction: interaction = 0 → -40
        if ($interactionScore === 0) {
            $penalties[] = ['rule' => 'no_interaction', 'value' => -40, 'reason' => 'هیچ interaction ثبت نشد'];
        }

        // too_fast: duration خیلی کم → -30
        $activeTime   = (int)($data['active_time'] ?? 0);
        $expectedTime = (int)($data['expected_time'] ?? 60);
        if ($expectedTime > 0 && $activeTime < ($expectedTime * 0.15)) {
            $penalties[] = ['rule' => 'too_fast', 'value' => -30, 'reason' => 'زمان انجام خیلی کوتاه'];
        }

        // pattern: الگوی تکراری رباتی
        $touchVariance = (float)($data['behavior_signals']['touch_timing_variance'] ?? 999);
        $tapCount      = (int)($data['behavior_signals']['tap_count'] ?? 0);
        if ($touchVariance < 5 && $tapCount > 10) {
            $penalties[] = ['rule' => 'bot_pattern', 'value' => -20, 'reason' => 'الگوی حرکات رباتی'];
        }

        return $penalties;
    }

    // ─────────────────────────────────────────────────────────────
    // Risk Score modifier
    // ─────────────────────────────────────────────────────────────

    /**
     * تبدیل Risk Score به modifier برای task score
     * risk <20  → +5
     * risk 20–50 → 0
     * risk >50  → -10
     */
    public function riskModifier(int $riskScore): int
    {
        if ($riskScore < 20)  return 5;
        if ($riskScore <= 50) return 0;
        return -10;
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function clamp(float $val, float $min, float $max): float
    {
        return max($min, min($max, $val));
    }
}
