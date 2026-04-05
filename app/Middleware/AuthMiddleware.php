<?php

namespace App\Middleware;

use Core\Request;
use Core\Response;

class AuthMiddleware
{
    public function handle(Request $request, Response $response): bool
{
    $session = app()->session;

    // ✅ Idle Timeout
    $timeout = (int) setting('session_idle_timeout_seconds', 900);
    $now = \time();
    $last = (int) ($session->get('last_activity_at') ?? 0);

    if ($last > 0 && ($now - $last) > $timeout) {

        // اگر destroy() دارید:
        if (\method_exists($session, 'destroy')) {
            $session->destroy();
        } else {
            // fallback امن
            $_SESSION = [];
            if (\session_status() === PHP_SESSION_ACTIVE) {
                \session_regenerate_id(true);
                \session_destroy();
            }
        }

        if (is_ajax()) {
            return $response->error('نشست شما منقضی شد. لطفاً دوباره وارد شوید.', [], 401);
        }

        // اگر Flash دارید:
        if (\method_exists($session, 'setFlash')) {
            $session->setFlash('error', 'نشست شما منقضی شد. لطفاً دوباره وارد شوید.');
        }

        $response->redirect(url('login'));
        return false;
    }

    // تمدید فعالیت در هر درخواست معتبر
    $session->set('last_activity_at', $now);

    // --- کد فعلی شما ---
    if (!$session->has('user_id')) {
        if (is_ajax()) {
            return $response->error('احراز هویت لازم است', [], 401);
        }
        $response->redirect(url('login'));
        return false;
    }

    return true;
}
}
