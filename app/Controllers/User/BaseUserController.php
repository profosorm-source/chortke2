<?php

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Services\CaptchaService;
use App\Services\AuthService;
use App\Models\User;
use Core\Container;

/**
 * BaseUserController — پایه تمام کنترلرهای پنل کاربر
 *
 * ─── سلسله مراتب ────────────────────────────────────────────────
 *
 *   Container::make(SomeUserController)
 *       └─→ SomeController::__construct(...services)
 *               └─→ parent::__construct()   ← بدون پارامتر
 *                       └─→ BaseController::__construct()
 *                               └─→ از Container: Request, Response, Session
 *
 * ─── قرارداد ───────────────────────────────────────────────────
 *   AuthService / User / CaptchaService از Container گرفته می‌شوند
 *   (نه از پارامتر constructor — چون همه فرزندها parent() بدون آرگومان صدا می‌زنند)
 */
abstract class BaseUserController extends BaseController
{
    protected AuthService    $authService;
    protected User           $userModel;
    protected CaptchaService $captchaService;

    /**
     * بدون پارامتر — همه وابستگی‌ها از Container گرفته می‌شوند
     * سازگار با تمام فرزندهایی که parent::__construct() بدون آرگومان صدا می‌زنند
     */
    public function __construct()
    {
        parent::__construct();

        $container = Container::getInstance();
        $this->authService    = $container->make(AuthService::class);
        $this->userModel      = $container->make(User::class);
        $this->captchaService = $container->make(CaptchaService::class);
    }

    /** user_id کاربر لاگین‌شده یا null */
    protected function userId(): ?int
    {
        $id = $this->session->get('user_id');
        return $id ? (int) $id : null;
    }

    /** اگر لاگین نباشد → redirect به login */
    protected function requireAuth(): void
    {
        if (!$this->userId()) {
            if (function_exists('is_ajax') && is_ajax()) {
                $this->response->error('احراز هویت لازم است', [], 401);
                exit;
            }
            $this->session->setFlash('error', 'ابتدا وارد حساب کاربری خود شوید.');
            $this->response->redirect(url('login'));
            exit;
        }
    }
}
