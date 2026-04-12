<?php
namespace App\Middleware;

use Core\Request;
use Core\Response;

/**
 * Guest Middleware
 * 
 * کاربر نباید لاگین باشد (برای صفحات Login/Register)
 */
class GuestMiddleware
{
    public function handle(Request $request, Response $response): bool
    {
        $session = app()->session;

        if ($session->has('user_id')) {

            if (is_ajax()) {
                return $response->error('شما قبلاً وارد شده‌اید.', [], 400);
            }

            // ریدایرکت هوشمند
            if ($session->get('user_role') === 'admin') {
                return $response->redirect(url('admin'));
            }

            return $response->redirect(url('dashboard'));
        }

        return true;
    }
}
