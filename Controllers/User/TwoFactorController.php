<?php

namespace App\Controllers\User;

use App\Services\TwoFactorService;
use App\Models\User;
use App\Models\ActivityLog;
use App\Controllers\User\BaseUserController;

/**
 * Two Factor Authentication Controller
 */
class TwoFactorController extends BaseUserController
{
    private TwoFactorService $twoFactorService;
    private User $userModel;
    private ActivityLog $activityLog;

    public function __construct(
        User $userModel,
        ActivityLog $activityLog,
        TwoFactorService $twoFactorService
    ) {
        parent::__construct();
        $this->userModel        = $userModel;
        $this->activityLog      = $activityLog;
        $this->twoFactorService = $twoFactorService;
    }

    /**
     * نمایش صفحه تنظیمات 2FA (پروفایل)
     */
    public function index(): void
    {
        $user = auth_user();
        if (!$user) {
            redirect(url('login'));
            return;
        }

        $data = [
            'title'      => 'احراز هویت دو مرحله‌ای',
            'is_enabled' => ($user['two_factor_enabled'] ?? 0) == 1,
        ];

        if (!$data['is_enabled']) {
            if (empty($user['two_factor_secret'])) {
                $secret = $this->twoFactorService->generateSecret();
                $this->userModel->update($user['id'], ['two_factor_secret' => $secret]);
                $user['two_factor_secret'] = $secret;
            }

            $data['secret']       = $user['two_factor_secret'];
            $data['qr_code_url']  = $this->twoFactorService->getQRCodeUrl(
                $user['username'] ?? $user['email'],
                $user['two_factor_secret']
            );
        }

        view('user/security/two-factor', $data);
    }

    /**
     * نمایش صفحه تأیید کد در هنگام ورود
     * Session key: 'pending_2fa_user' (همان کلیدی که AuthController ست می‌کند)
     */
    public function showVerify(): void
    {
        $userId = $this->session->get('pending_2fa_user');
        if (!$userId) {
            redirect(url('login'));
            return;
        }

        view('user/security/verify-2fa', [
            'title' => 'تأیید هویت دو مرحله‌ای',
        ]);
    }

    /**
     * پردازش کد 2FA در هنگام ورود
     */
    public function verify(): void
    {
        $userId = $this->session->get('pending_2fa_user');
        if (!$userId) {
            if (is_ajax()) {
                $this->response->json(['success' => false, 'message' => 'نشست نامعتبر است.'], 401);
                return;
            }
            redirect(url('login'));
            return;
        }

        $code = trim((string)($this->request->input('code') ?? ''));
        if ($code === '') {
            $this->response->json(['success' => false, 'message' => 'لطفاً کد را وارد کنید.']);
            return;
        }

        $user = $this->userModel->find((int)$userId);
        if (!$user || empty($user->two_factor_secret)) {
            $this->response->json(['success' => false, 'message' => 'خطا در احراز هویت.']);
            return;
        }

        if ($this->twoFactorService->verifyCode($user->two_factor_secret, $code)) {
            // حذف pending session و ایجاد session کامل
            $this->session->remove('pending_2fa_user');
            $this->session->set('user_id',   $user->id);
            $this->session->set('username',  $user->username  ?? '');
            $this->session->set('email',     $user->email);
            $this->session->set('role',      $user->role);
            $this->session->set('is_admin',  in_array($user->role, ['admin', 'super_admin'], true));
            $this->session->set('logged_in', true);
            $this->session->regenerate();

            $this->activityLog->log('2fa_verified', 'تأیید موفق احراز هویت دو مرحله‌ای', $user->id);

            $this->response->json([
                'success'  => true,
                'message'  => 'ورود موفقیت‌آمیز بود.',
                'redirect' => url('dashboard'),
            ]);
            return;
        }

        $this->userModel->incrementFraudScore($userId, 5);
        $this->response->json(['success' => false, 'message' => 'کد وارد شده نامعتبر است.']);
    }

    /**
     * فعال‌سازی 2FA
     */
    public function enable(): void
    {
        $user = auth_user();
        if (!$user) {
            $this->response->json(['success' => false, 'message' => 'لطفاً وارد شوید.'], 401);
            return;
        }

        $code = trim((string)($this->request->input('code') ?? ''));
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            $this->response->json(['success' => false, 'message' => 'لطفاً کد ۶ رقمی را وارد کنید.']);
            return;
        }

        $result = $this->twoFactorService->enable($user['id'], $code);

        if ($result['success']) {
            $this->activityLog->log('2fa_enabled', 'فعال‌سازی احراز هویت دو مرحله‌ای', $user['id']);
        }

        $this->response->json($result);
    }

    /**
     * غیرفعال‌سازی 2FA
     */
    public function disable(): void
    {
        $user = auth_user();
        if (!$user) {
            $this->response->json(['success' => false, 'message' => 'لطفاً وارد شوید.'], 401);
            return;
        }

        $password = (string)($this->request->input('password') ?? '');
        if ($password === '') {
            $this->response->json(['success' => false, 'message' => 'لطفاً رمز عبور خود را وارد کنید.']);
            return;
        }

        $result = $this->twoFactorService->disable($user['id'], $password);

        if ($result['success']) {
            $this->activityLog->log('2fa_disabled', 'غیرفعال‌سازی احراز هویت دو مرحله‌ای', $user['id']);
        }

        $this->response->json($result);
    }
}
