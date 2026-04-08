<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class CaptchaController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * تعویض کپچا — GET /captcha/refresh?type=math|image
     */
    public function refresh(): void
    {
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!$isAjax) {
            http_response_code(400);
            exit;
        }

        $type = in_array($_GET['type'] ?? '', ['math', 'image']) ? $_GET['type'] : 'math';

        $container = \Core\Container::getInstance();
        $service   = $container->make(\App\Services\CaptchaService::class);
        $captcha   = $service->generate($type);

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');

        if ($type === 'math') {
            echo json_encode([
                'question' => $captcha['question'],
                'token'    => $captcha['token'],
            ], JSON_UNESCAPED_UNICODE);
        } else {
            $image    = (string)($captcha['image'] ?? '');
            $filename = basename(ltrim($image, '/'));
            echo json_encode([
                'image_url' => url('file/view/captcha/' . $filename),
                'token'     => $captcha['token'],
            ], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    /**
     * ثبت تعامل behavioral — POST /captcha/behavioral/ping
     * Stateless: state رو sign شده برمیگردونه، نیازی به session ندارد
     */
    public function behavioralPing(): void
    {
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if (!$isAjax) {
            $this->json(false, 'Bad request', [], 400);
            return;
        }

        $token = trim((string)($_POST['captcha_token'] ?? ''));
        if ($token === '') {
            $this->json(false, 'Token missing', [], 422);
            return;
        }

        // token باید فرمت payload.signature داشته باشد
        if (!str_contains($token, '.')) {
            $this->json(false, 'Invalid token format', [], 400);
            return;
        }

        // state فعلی از JS (ممکنه اولین ping باشه و خالی باشه)
        $currentState = trim((string)($_POST['behavioral_state'] ?? ''));

        $appKey = config('app.key', 'fallback-secret-key');

        // خواندن state قبلی
        $interactions      = 0;
        $lastInteractionAt = 0;

        if ($currentState !== '') {
            $parts = explode('.', $currentState);
            if (count($parts) === 2) {
                [$statePayload, $stateSig] = $parts;
                $expectedSig = hash_hmac('sha256', $statePayload, $appKey);
                if (hash_equals($expectedSig, $stateSig)) {
                    $stateData = json_decode(base64_decode($statePayload), true);
                    if (is_array($stateData)) {
                        $interactions      = (int)($stateData['interactions']       ?? 0);
                        $lastInteractionAt = (int)($stateData['last_interaction_at'] ?? 0);
                    }
                }
            }
        }

        // throttle: هر 800ms حداکثر یک ping معتبر
        $now = time();
        if ($lastInteractionAt > 0 && ($now - $lastInteractionAt) < 1) {
            // همان state رو برگردان بدون تغییر
            $this->json(true, 'ok', [
                'interactions'    => $interactions,
                'behavioral_state' => $currentState,
            ]);
            return;
        }

        // ساخت state جدید
        $newInteractions = $interactions + 1;
        $newPayload = base64_encode(json_encode([
            'interactions'       => $newInteractions,
            'last_interaction_at' => $now,
        ]));
        $newSig   = hash_hmac('sha256', $newPayload, $appKey);
        $newState = $newPayload . '.' . $newSig;

        $this->json(true, 'ok', [
            'interactions'     => $newInteractions,
            'behavioral_state' => $newState,
        ]);
    }
}