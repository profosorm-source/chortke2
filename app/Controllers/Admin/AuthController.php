<?php

namespace App\Controllers\Admin;

use Core\Database;
use Core\Validator;
use App\Controllers\BaseController;

class AuthController extends BaseController
{
    private Database $db;

    public function __construct(Database $db)
    {
        parent::__construct();
        $this->db = $db;
    }

    public function showLogin(): void
    {
        view('admin/login', ['title' => 'ورود به پنل مدیریت']);
    }

    public function login(): void
    {
        try {
            rate_limit('admin', 'login');
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->session->setFlash('error', $e->getMessage());
                redirect('/admin/login');
                return;
            }
        }

        $data = $this->request->all();

        $validator = new Validator($data, [
            'email'    => 'required|email',
            'password' => 'required|min:6',
        ]);

        if ($validator->fails()) {
            log_activity('auth.login.invalid_input', 'ورود ناموفق (ورودی نامعتبر)', null, ['email' => $data['email'] ?? null]);
            $this->session->setFlash('errors', $validator->errors());
            $this->session->setFlash('old', $data);
            redirect('/admin/login');
            return;
        }

        $user = $this->db->query(
            "SELECT * FROM users WHERE email = ? LIMIT 1",
            [$data['email']]
        )->fetch();

        // FIX S-3: Timing Attack Prevention
        // password_verify همیشه اجرا می‌شود حتی اگر کاربر وجود نداشته باشد.
        // این از user enumeration از طریق اختلاف زمان پاسخ جلوگیری می‌کند.
        $dummyHash  = '$2y$14$usesomesillystringfore2uDLvp1Ii2e./U9C8sBjqp8I90dH6hi';
        $inputPass  = (string)($data['password'] ?? '');
        $storedHash = $user ? (string)($user->password ?? '') : $dummyHash;
        $passOk     = password_verify($inputPass, $storedHash);

        if (!$user || !$passOk) {
            $this->session->setFlash('error', 'ایمیل یا رمز عبور اشتباه است');
            $this->session->setFlash('old', $data);
            log_activity('auth.login.failed', 'ورود ناموفق', null, ['email' => $data['email'] ?? null]);
            redirect('/admin/login');
            return;
        }

        // بررسی نقش بعد از تایید رمز عبور
        if ($user->role !== 'admin' && $user->role !== 'support') {
            $this->session->setFlash('error', 'شما دسترسی به پنل مدیریت ندارید');
            log_activity('auth.login.failed', 'نقش نامجاز', null, ['email' => $data['email'] ?? null]);
            redirect('/admin/login');
            return;
        }

        if ($user->status !== 'active') {
            $this->session->setFlash('error', 'حساب کاربری شما غیرفعال است');
            redirect('/admin/login');
            return;
        }

        // FIX S-2: Session Fixation Prevention
        // قبل از ذخیره هر داده حساس، session ID جدید تولید می‌شود.
        $this->session->regenerate();

        $this->session->set('user_id',    $user->id);
        $this->session->set('user_email', $user->email);
        $this->session->set('user_role',  $user->role);
        $this->session->set('is_admin',   $user->role === 'admin');

        $this->db->query("UPDATE users SET last_login = NOW() WHERE id = ?", [$user->id]);

        log_activity('auth.login.success', 'ورود موفق به پنل مدیریت', (int)$user->id, ['email' => $user->email]);

        $this->session->setFlash('success', 'خوش آمدید به پنل مدیریت');
        redirect('/admin/dashboard');
    }

    public function logout(): void
    {
        $this->session->setFlash('success', 'با موفقیت خارج شدید');
        $this->session->destroy();
        redirect('/admin/login');
    }
}
