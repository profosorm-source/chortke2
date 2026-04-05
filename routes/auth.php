<?php

/**
 * مسیرهای احراز هویت
 * - Guest: ثبت‌نام، ورود، فراموشی رمز
 * - Auth: خروج، تأیید ایمیل، ۲FA
 */

use App\Controllers\User\AuthController as UserAuthController;
use App\Controllers\User\TwoFactorController;
use App\Middleware\GuestMiddleware;
use App\Middleware\AuthMiddleware;

$router = app()->router;

// ── فقط برای مهمان ───────────────────────────────────────────────────────
app()->router->group(['middleware' => GuestMiddleware::class], function ($router) {
    $router->get('/register',         [UserAuthController::class, 'showRegister']);
    $router->post('/register',        [UserAuthController::class, 'register']);
    $router->get('/login',            [UserAuthController::class, 'showLogin']);
    $router->post('/login',           [UserAuthController::class, 'login']);
    $router->get('/forgot-password',  [UserAuthController::class, 'showForgotPassword']);
    $router->post('/forgot-password', [UserAuthController::class, 'forgotPassword']);
    $router->get('/reset-password',   [UserAuthController::class, 'showResetPassword']);
    $router->post('/reset-password',  [UserAuthController::class, 'resetPassword']);
});

// ── تأیید دو مرحله‌ای (کاربر هنوز کاملاً لاگین نیست) ────────────────────
$router->get('/verify-2fa',  [TwoFactorController::class, 'showVerify']);
$router->post('/verify-2fa', [TwoFactorController::class, 'verify']);

// ── تأیید ایمیل ──────────────────────────────────────────────────────────
$router->get('/email/verify',              [UserAuthController::class, 'verifyEmail']);
$router->get('/email/verify-code',         [UserAuthController::class, 'showVerifyEmail']);
$router->post('/email/verify-code',        [UserAuthController::class, 'verifyEmailByCode']);
$router->post('/email/resend-verification',[UserAuthController::class, 'resendVerification']);

// ── خروج (فقط POST — حذف GET برای جلوگیری از CSRF) ──────────────────────
$router->post('/logout', [UserAuthController::class, 'logout'], [AuthMiddleware::class]);
