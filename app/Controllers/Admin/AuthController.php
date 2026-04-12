<?php

namespace App\Controllers\Admin;

use App\Services\Logger;
use App\Services\ActivityLogger;
use App\Services\AuditLogger;
use App\Controllers\Admin\BaseAdminController;

/**
 * AuthController - احراز هویت ادمین
 */
class AuthController extends BaseAdminController
{
    private Logger $logger;
    private ActivityLogger $activityLogger;
    private AuditLogger $auditLogger;

    public function __construct(
        Logger $logger,
        ActivityLogger $activityLogger,
        AuditLogger $auditLogger
    ) {
        parent::__construct();
        $this->logger = $logger;
        $this->activityLogger = $activityLogger;
        $this->auditLogger = $auditLogger;
    }

    /**
     * صفحه لاگین
     */
    public function showLogin()
    {
        if (auth()->check() && auth()->user()->isAdmin()) {
            return redirect('/admin/dashboard');
        }
        
        return view('admin.auth.login');
    }

    /**
     * پردازش لاگین
     */
    public function login()
    {
        try {
            $email = $this->request->post('email');
            $password = $this->request->post('password');
            $remember = (bool)$this->request->post('remember');

            // تلاش برای لاگین
            $user = auth()->attempt($email, $password, $remember);

            if (!$user) {
                // لاگین ناموفق
                $this->activityLogger->log(
                    'admin.login.failed',
                    "تلاش ناموفق برای ورود به پنل با ایمیل {$email}",
                    null,
                    ['email' => $email, 'ip' => get_client_ip()]
                );

                $this->auditLogger->record(
                    AuditLogger::AUTH_FAILED,
                    null,
                    ['email' => $email, 'type' => 'admin']
                );

                $this->logger->warning('admin.login.failed', [
                    'email' => $email,
                    'ip' => get_client_ip()
                ]);

                $this->session->setFlash('error', 'ایمیل یا رمز عبور اشتباه است');
                return redirect('/admin/login');
            }

            // بررسی دسترسی ادمین
            if (!$user->isAdmin()) {
                auth()->logout();
                
                $this->logger->warning('admin.unauthorized_access', [
                    'user_id' => $user->id,
                    'email' => $email
                ]);

                $this->session->setFlash('error', 'شما دسترسی به پنل ادمین ندارید');
                return redirect('/admin/login');
            }

            // لاگین موفق
            $this->activityLogger->log(
                'admin.login',
                'ورود موفق به پنل مدیریت',
                $user->id,
                [
                    'ip' => get_client_ip(),
                    'user_agent' => get_user_agent(),
                    'remember' => $remember
                ]
            );

            $this->auditLogger->record(
                AuditLogger::AUTH_LOGIN,
                $user->id,
                [
                    'type' => 'admin',
                    'ip' => get_client_ip(),
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            );

            $this->logger->info('admin.login.success', [
                'user_id' => $user->id,
                'email' => $email
            ]);

            return redirect('/admin/dashboard');

        } catch (\Exception $e) {
            $this->logger->error('admin.login.exception', [
                'email' => $email ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->session->setFlash('error', 'خطای سیستمی. لطفاً دوباره تلاش کنید');
            return redirect('/admin/login');
        }
    }

    /**
     * خروج
     */
    public function logout()
    {
        try {
            $userId = user_id();

            if ($userId) {
                $this->activityLogger->log(
                    'admin.logout',
                    'خروج از پنل مدیریت',
                    $userId
                );

                $this->auditLogger->record(
                    AuditLogger::AUTH_LOGOUT,
                    $userId,
                    ['type' => 'admin']
                );
            }

            auth()->logout();
            
            return redirect('/admin/login');

        } catch (\Exception $e) {
            $this->logger->error('admin.logout.failed', [
                'user_id' => $userId ?? null,
                'error' => $e->getMessage()
            ]);

            return redirect('/admin/login');
        }
    }
}
