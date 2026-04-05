<?php

namespace App\Controllers\User;

use App\Models\User;
use App\Models\PasswordReset;
use App\Services\AuthService;
use Core\Validator;
use PDOException;
use App\Controllers\BaseController;
use App\Services\LoginRiskService;

class AuthController extends BaseController
{
    private \App\Services\CaptchaService $captchaService;
    private AuthService $authService;
    private User $userModel;
    private PasswordReset $passwordResetModel;
    private LoginRiskService $loginRiskService;
   
    

    public function __construct(
        \App\Models\User $userModel,
        \App\Models\PasswordReset $passwordResetModel,
        \App\Services\CaptchaService $captchaService,
        \App\Services\AuthService $authService,
        LoginRiskService $loginRiskService)
    {
        parent::__construct();
        $this->authService = $authService;
        $this->userModel = $userModel;
        $this->passwordResetModel = $passwordResetModel;
        $this->captchaService = $captchaService;
        $this->loginRiskService = $loginRiskService;
    }

    /**
     * نمایش فرم ورود
     */
    public function showLogin(): void
    {
        $captchaType = $this->loginRiskService->getCaptchaType('login');
        view('user/login', [
            'title'       => 'ورود به سیستم',
            'captchaType' => $captchaType,
        ]);
    }

    /**
     * پردازش ورود - از طریق AuthService
     */
    public function login(): void
    {
        // Rate Limiting - محدودیت تلاش برای ورود
        try {
            rate_limit('auth', 'login');
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->session->setFlash('error', $e->getMessage());
                redirect('login');
                return;
            }
        }

        // بررسی CAPTCHA (فقط اگر نوع کپچا تعیین شده باشد)
        $captchaType  = $this->loginRiskService->getCaptchaType('login');
        $captchaToken = trim((string)($_POST['captcha_token'] ?? ''));
        $captchaResp  = trim((string)($_POST['captcha_response'] ?? ''));
        $recaptchaResp = trim((string)($_POST['g-recaptcha-response'] ?? ''));

        if ($captchaType !== null) {
            if ($captchaType === 'recaptcha_v2') {
                if ($recaptchaResp === '') {
                    $this->session->setFlash('error', 'لطفاً کپچا را تکمیل کنید.');
                    redirect('login');
                    return;
                }
                if (!$this->captchaService->verify('', '', $recaptchaResp)) {
                    $this->loginRiskService->recordFailure('login');
                    $this->session->setFlash('error', 'کپچا اشتباه است. لطفاً دوباره تلاش کنید.');
                    redirect('login');
                    return;
                }
            } else {
                if ($captchaToken === '' || $captchaResp === '') {
                    $this->session->setFlash('error', 'لطفاً کپچا را تکمیل کنید.');
                    redirect('login');
                    return;
                }
                if (!$this->captchaService->verify($captchaToken, $captchaResp)) {
                    $this->loginRiskService->recordFailure('login');
                    $this->session->setFlash('error', 'کپچا اشتباه است. لطفاً دوباره تلاش کنید.');
                    redirect('login');
                    return;
                }
            }
        }

        // اعتبارسنجی ورودی
        $data = $this->request->all();
        $validator = new Validator($data, [
            'email'    => 'required|email',
            'password' => 'required|min:6',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', 'لطفاً تمام فیلدها را به درستی پر کنید.');
            $this->session->setFlash('errors', $validator->errors());
            $this->session->setFlash('old', $data);
            $this->response->redirect(url('login'));
            return;
        }

        $remember = ($data['remember'] ?? '') === 'on';

        // ورود از طریق AuthService
        $result = $this->authService->login($data['email'], $data['password'], $remember);

        if (!$result['success']) {
            $this->loginRiskService->recordFailure('login');

            // اگر ایمیل تأیید نشده — ارسال ایمیل و redirect به صفحه تأیید
            if (!empty($result['email_unverified'])) {
                $email = $result['email'] ?? '';
                // ارسال ایمیل تأیید
                try {
                    $this->authService->resendVerificationEmail($email);
                } catch (\Throwable $e) {
                    logger()->error('Login unverified email send failed: ' . $e->getMessage());
                }
                $this->session->set('pending_verification_email', $email);
                $this->session->setFlash('success', 'ایمیل تأیید ارسال شد. کد را وارد کنید.');
                $this->response->redirect(url('email/verify-code'));
                return;
            }

            $this->session->setFlash('error', $result['message']);
            $this->session->setFlash('old', ['email' => $data['email']]);
            $this->response->redirect(url('login'));
            return;
        }

        // 2FA — کاربر تا تأیید کد به داشبورد دسترسی ندارد
        if (!empty($result['requires_2fa'])) {
            // session کامل را پاک می‌کنیم
            $this->session->set('logged_in', false);
            $this->session->remove('user_id');
            // userId را برای مرحله 2FA نگه می‌داریم
            $user2fa = $result['user'];
            $userId2fa = is_object($user2fa) ? $user2fa->id : ($user2fa['id'] ?? 0);
            $this->session->set('pending_2fa_user', (int)$userId2fa);
            $this->response->redirect(url('verify-2fa'));
            return;
        }

        $this->loginRiskService->clearFailures('login');
        $this->session->setFlash('success', 'خوش آمدید!');
        $this->response->redirect(url('dashboard'));
    }

    /**
     * نمایش فرم ثبت‌نام
     */
    public function showRegister()
    {
        $ref = app()->request->query('ref', '');
        $ref = is_string($ref) ? trim($ref) : '';
        $ref = preg_replace('/\s+/', '', $ref);

        if ($ref !== '' && preg_match('/^[A-Za-z0-9_]{4,32}$/', $ref)) {
            $this->session->set('register_referral_code', $ref);
        }

        $referralCode = $this->session->get('register_referral_code');
        $captchaType  = $this->loginRiskService->getCaptchaType('register');

        return view('user/register', [
            'referralCode' => $referralCode,
            'captchaType'  => $captchaType,
        ]);
    }

    /**
     * پردازش ثبت‌نام
     */
    public function register(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            redirect('register');
            return;
        }

        // Rate Limiting - محدودیت ثبت‌نام
        try {
            rate_limit('auth', 'register');
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->session->setFlash('error', $e->getMessage());
                redirect('register');
                return;
            }
        }

        // بررسی CAPTCHA در ثبت‌نام
        $regCaptchaType = $this->loginRiskService->getCaptchaType('register');
        if ($regCaptchaType !== null) {
            $captchaToken  = trim((string)($_POST['captcha_token'] ?? ''));
            $captchaResp   = trim((string)($_POST['captcha_response'] ?? ''));
            $recaptchaResp = trim((string)($_POST['g-recaptcha-response'] ?? ''));
            if ($regCaptchaType === 'recaptcha_v2') {
                if ($recaptchaResp === '' || !$this->captchaService->verify('', '', $recaptchaResp)) {
                    $this->loginRiskService->recordFailure('register');
                    $this->session->setFlash('error', 'لطفاً کپچا را تکمیل کنید.');
                    redirect('register');
                    return;
                }
            } else {
                if ($captchaToken === '' || $captchaResp === '' || !$this->captchaService->verify($captchaToken, $captchaResp)) {
                    $this->loginRiskService->recordFailure('register');
                    $this->session->setFlash('error', 'کپچا اشتباه است.');
                    redirect('register');
                    return;
                }
            }
        }

        $fullName = $this->request->input('full_name');
        $email    = $this->request->input('email');
        $mobile   = $this->request->input('mobile');
        $password = $this->request->input('password');
        $passwordConfirmation = $this->request->input('password_confirmation');
        $terms    = $this->request->input('terms');

        $fullName = is_string($fullName) ? trim($fullName) : '';
        $email    = is_string($email) ? trim($email) : '';
        $mobile   = is_string($mobile) ? trim($mobile) : '';
        $password = is_string($password) ? $password : '';
        $passwordConfirmation = is_string($passwordConfirmation) ? $passwordConfirmation : '';

        if (function_exists('convert_persian_numbers')) {
            $mobile = convert_persian_numbers($mobile);
        }
        $mobile = preg_replace('/\s+/', '', $mobile);

        // اعتبارسنجی
        $errors = [];

        if ($fullName === '' || mb_strlen($fullName) < 3 || mb_strlen($fullName) > 100) {
            $errors[] = 'نام و نام خانوادگی باید بین ۳ تا ۱۰۰ کاراکتر باشد.';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'ایمیل معتبر نیست.';
        }

        if ($mobile === '') {
            $errors[] = 'شماره موبایل الزامی است.';
        } elseif (!preg_match('/^09[0-9]{9}$/', $mobile)) {
            $errors[] = 'شماره موبایل نامعتبر است. مثال: 09123456789';
        }

        if ($password === '' || strlen($password) < 8) {
            $errors[] = 'رمز عبور باید حداقل ۸ کاراکتر باشد.';
        } elseif ($password !== $passwordConfirmation) {
            $errors[] = 'رمز عبور و تکرار آن یکسان نیستند.';
        }

        if ($terms !== 'on') {
            $this->session->setFlash('error', 'برای ثبت‌نام باید قوانین سایت را بپذیرید.');
            redirect('register');
            return;
        }

        if (!empty($errors)) {
            $this->session->setFlash('error', implode('<br>', $errors));
            redirect('register');
            return;
        }

        // بررسی تکراری بودن ایمیل/موبایل
        if ($this->userModel->emailExists($email)) {
            $this->session->setFlash('error', 'این ایمیل قبلاً ثبت شده است.');
            redirect('register');
            return;
        }

        if ($this->userModel->mobileExists($mobile)) {
            $this->session->setFlash('error', 'این شماره موبایل قبلاً ثبت شده است.');
            redirect('register');
            return;
        }

        // بررسی رفرال
        $refInput = $this->request->input('referral_code');
        $refInput = is_string($refInput) ? trim($refInput) : '';

        if ($refInput === '') {
            $refInput = (string)($this->session->get('register_referral_code') ?? '');
        }

        $refInput = preg_replace('/\s+/', '', $refInput);
        $referredBy = null;

        if ($refInput !== '') {
            if (!preg_match('/^[A-Za-z0-9_]{4,32}$/', $refInput)) {
                $this->session->setFlash('error', 'کد معرف نامعتبر است.');
                redirect('register');
                return;
            }

            $referrer = $this->userModel->findByReferralCode($refInput);
            if (!$referrer) {
                $this->session->setFlash('error', 'کد معرف یافت نشد.');
                redirect('register');
                return;
            }

            if (!empty($referrer->deleted_at) || in_array((string)($referrer->status ?? ''), ['banned', 'suspended'], true)) {
                $this->session->setFlash('error', 'کد معرف معتبر نیست.');
                redirect('register');
                return;
            }

            $referredBy = (int)$referrer->id;
        }

        // آماده‌سازی داده
        $data = [
            'full_name'  => $fullName,
            'email'      => $email,
            'mobile'     => $mobile,
            'password'   => $password,
            'role'       => 'user',
            'status'     => 'active',
        ];

        if ($referredBy) {
            $data['referred_by'] = $referredBy;
        }

        // ثبت‌نام از طریق AuthService
        try {
            $result = $this->authService->register($data);
        } catch (PDOException $e) {
            $driverCode = (int)($e->errorInfo[1] ?? 0);
            if ($driverCode === 1062) {
                $msg = $e->getMessage();
                if (strpos($msg, 'email') !== false) {
                    $this->session->setFlash('error', 'این ایمیل قبلاً ثبت شده است.');
                } elseif (strpos($msg, 'mobile') !== false) {
                    $this->session->setFlash('error', 'این شماره موبایل قبلاً ثبت شده است.');
                } else {
                    $this->session->setFlash('error', 'اطلاعات تکراری است. لطفاً ورودی‌ها را بررسی کنید.');
                }
                redirect('register');
                return;
            }
            throw $e;
        }

        if (!$result['success']) {
            $this->session->setFlash('error', $result['message']);
            redirect('register');
            return;
        }

        $this->session->set('register_referral_code', null);
        // ذخیره ایمیل برای صفحه تأیید
        $this->session->set('pending_verification_email', $email);
        $this->session->setFlash('success', 'ثبت‌نام موفق! یک ایمیل تأیید برای شما ارسال شد.');
        redirect('email/verify-code');
    }

    /**
     * نمایش فرم فراموشی رمز عبور
     */
    public function showForgotPassword(): void
    {
        view('auth/forgot-password', ['title' => 'فراموشی رمز عبور']);
    }

    /**
     * پردازش فراموشی رمز عبور
     */
    public function forgotPassword(): void
    {
        // Rate Limiting - محدودیت درخواست بازیابی رمز
        try {
            rate_limit('auth', 'forgot_password');
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->session->setFlash('error', $e->getMessage());
                $this->response->redirect(url('forgot-password'));
                return;
            }
        }

        $data = $this->request->all();

        $validator = new Validator($data, ['email' => 'required|email']);

        if ($validator->fails()) {
            $this->session->setFlash('error', 'لطفاً ایمیل معتبر وارد کنید.');
            $this->response->redirect(url('forgot-password'));
            return;
        }

        $result = $this->authService->requestPasswordReset($data['email']);

        $this->session->setFlash('success', $result['message']);
        $this->response->redirect(url('login'));
    }

    /**
     * نمایش فرم تنظیم مجدد رمز عبور
     */
    public function showResetPassword(): void
    {
        $token = $this->request->get('token');
        if (!$token) {
            $this->session->setFlash('error', 'توکن نامعتبر است.');
            $this->response->redirect(url('login'));
            return;
        }

        view('auth/reset-password', ['title' => 'تنظیم مجدد رمز عبور', 'token' => $token]);
    }

    /**
     * پردازش تنظیم مجدد رمز عبور
     */
    public function resetPassword(): void
    {
        $data = $this->request->all();

        $validator = new Validator($data, [
            'token'            => 'required',
            'password'         => 'required|min:8',
            'password_confirm' => 'required|same:password',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', 'لطفاً رمز عبور معتبر وارد کنید.');
            $this->response->redirect(url('reset-password?token=' . ($data['token'] ?? '')));
            return;
        }

        $result = $this->authService->resetPassword($data['token'], $data['password']);

        if (!$result['success']) {
            $this->session->setFlash('error', $result['message']);
            $this->response->redirect(url('forgot-password'));
            return;
        }

        $this->session->setFlash('success', 'رمز عبور با موفقیت تغییر یافت. اکنون وارد شوید.');
        $this->response->redirect(url('login'));
    }

    /**
     * نمایش صفحه تأیید ایمیل (با کد یا لینک)
     */
    public function showVerifyEmail(): void
    {
        // از session یا query string
        $email = $this->session->get('pending_verification_email', '')
              ?: ($this->request->get('email') ?? '');

        if ($email) {
            $this->session->set('pending_verification_email', $email);
        }

        view('user/verify-email', ['email' => $email]);
    }

    /**
     * تأیید ایمیل با لینک (GET ?token=...)
     */
    public function verifyEmail(): void
    {
        $token = $this->request->get('token') ?? '';

        if (!$token) {
            $this->session->setFlash('error', 'لینک تأیید نامعتبر است.');
            $this->response->redirect(url('login'));
            return;
        }

        $result = $this->authService->verifyEmail($token);

        if ($result['success']) {
            $this->session->remove('pending_verification_email');
            $this->session->setFlash('success', 'ایمیل شما تأیید شد. اکنون وارد شوید.');
        } else {
            $this->session->setFlash('error', $result['message']);
        }

        $this->response->redirect(url('login'));
    }

    /**
     * تأیید ایمیل با کد ۶ رقمی (POST از صفحه verify-email)
     */
    public function verifyEmailByCode(): void
    {
        $email = trim((string)($this->request->input('email') ?? ''));
        $code  = strtolower(trim((string)($this->request->input('code') ?? '')));

        if (!$email || !$code) {
            $this->session->setFlash('error', 'لطفاً ایمیل و کد را وارد کنید.');
            $this->response->redirect(url('email/verify-code'));
            return;
        }

        $result = $this->authService->verifyEmailByCode($email, $code);

        if ($result['success']) {
            $this->session->remove('pending_verification_email');
            $this->session->setFlash('success', 'ایمیل شما تأیید شد. اکنون وارد شوید.');
            $this->response->redirect(url('login'));
        } else {
            $this->session->setFlash('error', $result['message']);
            $this->response->redirect(url('email/verify-code'));
        }
    }

    /**
     * ارسال مجدد ایمیل تأیید
     */
    public function resendVerification(): void
    {
        try {
            rate_limit('auth', 'resend_verification');
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->session->setFlash('error', $e->getMessage());
                $this->response->redirect(url('email/verify-code'));
                return;
            }
        }

        $email = trim((string)($this->request->input('email') ?? ''));

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->session->setFlash('error', 'ایمیل معتبر وارد کنید.');
            $this->response->redirect(url('email/verify-code'));
            return;
        }

        $user = $this->userModel->findByEmail($email);

        if ($user && empty($user->email_verified_at)) {
            try {
                $this->authService->resendVerificationEmail($email);
            } catch (\Throwable $e) {
                logger()->error('Resend verification email failed: ' . $e->getMessage());
            }
        }

        // همیشه در صفحه verify-code بمون
        $this->session->set('pending_verification_email', $email);
        $this->session->setFlash('success', 'ایمیل تأیید مجدداً ارسال شد. کد جدید را وارد کنید.');
        $this->response->redirect(url('email/verify-code'));
    }

    /**
     * خروج از سیستم
     */
    public function logout(): void
    {
        $this->authService->logout();
        $this->session->setFlash('success', 'با موفقیت خارج شدید.');
        $this->response->redirect(url('login'));
    }
}