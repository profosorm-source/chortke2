<?php

namespace App\Controllers\Api;

use App\Services\SocialTask\SocialTaskService;
use App\Services\SocialTask\SilentAntiFraudService;
use App\Services\SocialTask\TrustScoreService;

/**
 * SocialTaskApiController
 *
 * API برای موبایل — دریافت behavior signals در حین انجام تسک.
 * همه endpoint ها نیاز به auth دارند.
 *
 * POST /api/social-tasks/behavior         → recordBehavior
 * POST /api/social-tasks/camera-verify    → cameraVerify (سیگنال Camera Verification)
 * GET  /api/social-tasks/trust-status     → trustStatus
 */
class SocialTaskApiController extends BaseApiController
{
    private SocialTaskService      $service;
    private SilentAntiFraudService $antiFraud;
    private TrustScoreService      $trust;

    public function __construct(
        SocialTaskService     $service,
        SilentAntiFraudService $antiFraud,
        TrustScoreService     $trust
    ) {
        parent::__construct();
        $this->service   = $service;
        $this->antiFraud = $antiFraud;
        $this->trust     = $trust;
    }

    // ─────────────────────────────────────────────────────────────
    // ثبت behavior signals (موبایل در حین انجام)
    // ─────────────────────────────────────────────────────────────

    /**
     * POST /api/social-tasks/behavior
     * Body: {
     *   execution_id: int,
     *   signals: {
     *     tap_count, swipe_count, scroll_count, touch_pauses,
     *     touch_timing_variance, scroll_speed_variance, scroll_pauses,
     *     session_duration, active_time, reconnect_count,
     *     app_blur_count, max_blur_duration,
     *     hesitation_count, avg_action_delay_ms, natural_delay_count
     *   }
     * }
     */
    public function recordBehavior(): void
    {
        $userId      = (int)$this->getAuthUserId();
        $body        = $this->request->body();
        $executionId = (int)($body['execution_id'] ?? 0);
        $signals     = (array)($body['signals'] ?? []);

        if (!$executionId) {
            $this->jsonError('execution_id الزامی است');
            return;
        }

        // فقط فیلدهای مجاز
        $allowedSignals = [
            'tap_count', 'swipe_count', 'scroll_count', 'touch_pauses',
            'touch_timing_variance', 'scroll_speed_variance', 'scroll_pauses',
            'session_duration', 'active_time', 'reconnect_count',
            'app_blur_count', 'max_blur_duration',
            'hesitation_count', 'avg_action_delay_ms', 'natural_delay_count',
        ];
        $filtered = array_intersect_key($signals, array_flip($allowedSignals));

        $success = $this->service->recordBehaviorSignals($executionId, $userId, $filtered);

        $this->response->json(['success' => $success]);
    }

    // ─────────────────────────────────────────────────────────────
    // Camera Verification Signal
    // ─────────────────────────────────────────────────────────────

    /**
     * POST /api/social-tasks/camera-verify
     *
     * عکس هرگز ذخیره یا ارسال نمی‌شود.
     * موبایل نتیجه پردازش ML محلی را به صورت امتیاز ارسال می‌کند.
     *
     * Body: {
     *   execution_id: int,
     *   camera_score: int (0–100 — نتیجه ML محلی),
     *   task_type: string,
     *   verified_signals: string[] (مثلاً ['follow_button_visible','username_match'])
     * }
     */
    public function cameraVerify(): void
    {
        $userId = (int)$this->getAuthUserId();
        $body   = $this->request->body();

        $executionId     = (int)($body['execution_id'] ?? 0);
        $cameraScore     = (int)($body['camera_score'] ?? 0);
        $verifiedSignals = (array)($body['verified_signals'] ?? []);

        if (!$executionId) {
            $this->jsonError('execution_id الزامی است');
            return;
        }

        // Camera score فقط یک سیگنال است — ذخیره در behavior_data
        $signals = [
            'camera_score'      => max(0, min(100, $cameraScore)),
            'camera_signals'    => $verifiedSignals,
            'camera_verified_at'=> time(),
        ];

        $this->service->recordBehaviorSignals($executionId, $userId, $signals);

        $this->response->json([
            'success'      => true,
            'camera_score' => $cameraScore,
            'message'      => 'سیگنال دوربین دریافت شد',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // وضعیت Trust کاربر
    // ─────────────────────────────────────────────────────────────

    /**
     * GET /api/social-tasks/trust-status
     */
    public function trustStatus(): void
    {
        $userId = (int)$this->getAuthUserId();

        $trust   = $this->trust->get($userId);
        $weekly  = $this->trust->getWeeklyStats($userId);

        $this->response->json([
            'success'      => true,
            'trust_score'  => $trust,
            'weekly'       => $weekly,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────

    private function jsonError(string $message, int $code = 400): void
    {
        http_response_code($code);
        $this->response->json(['success' => false, 'message' => $message]);
    }
}
