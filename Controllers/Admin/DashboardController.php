<?php

namespace App\Controllers\Admin;

use App\Services\AdminDashboardService;
use App\Controllers\Admin\BaseAdminController;

class DashboardController extends BaseAdminController
{
    private AdminDashboardService $dashboardService;

    public function __construct(AdminDashboardService $dashboardService)
    {
        parent::__construct();
        $this->dashboardService = $dashboardService;
    }

    // ══════════════════════════════════════════════════════════
    // صفحه اصلی داشبورد
    // ══════════════════════════════════════════════════════════

    public function index(): void
    {
        $userId = $this->userId();
        if (!$userId) {
            $this->session->setFlash('error', 'لطفاً وارد شوید.');
            redirect('/admin/login');
            return;
        }

        try {
            $data = $this->dashboardService->getDashboardData($userId);
        } catch (\Throwable $e) {
            logger()->error('Admin dashboard index failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در دریافت اطلاعات داشبورد');
            redirect('/admin/login');
            return;
        }

        view('admin/dashboard', [
            'title'                  => 'داشبورد مدیریت',
            'stats'                  => $data['stats'],
            'chartData'              => $data['chartData'],
            'recentUsers'            => $data['recentUsers'],
            'pendingWithdrawalsList' => $data['pendingWithdrawalsList'],
            'recentActivities'       => $data['recentActivities'],
            'adminAccessLog'         => $data['adminAccessLog'] ?? [],
            'user'                   => $data['currentUser'],
            'fullName'               => $data['currentUser']->full_name ?? 'مدیر',
        ]);
    }

    // ══════════════════════════════════════════════════════════
    // GET /admin/dashboard/recent-activity
    // پارامترها: ?type=all&limit=20&page=1
    // ══════════════════════════════════════════════════════════

    public function recentActivity(): void
    {
        $type  = $_GET['type']  ?? 'all';
        $limit = min((int)($_GET['limit'] ?? 20), 100);
        $page  = max((int)($_GET['page']  ?? 1), 1);

        try {
            $result = $this->dashboardService->getRecentActivity($type, $limit, $page);

            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'data'    => $result['items'],
                'stats'   => $result['stats'],
                'page'    => $page,
                'limit'   => $limit,
            ], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            logger()->error('Dashboard recent-activity failed', ['error' => $e->getMessage()]);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'خطا در دریافت فعالیت‌ها'], JSON_UNESCAPED_UNICODE);
        }
    }

    // ══════════════════════════════════════════════════════════
    // GET /admin/dashboard/system-status
    // ══════════════════════════════════════════════════════════

    public function systemStatus(): void
    {
        try {
            $data = $this->dashboardService->getSystemStatus();

            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);

        } catch (\Throwable $e) {
            logger()->error('Dashboard system-status failed', ['error' => $e->getMessage()]);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'خطا در دریافت وضعیت سیستم'], JSON_UNESCAPED_UNICODE);
        }
    }

    // ══════════════════════════════════════════════════════════
    // متدهای Auth (دست نخورده از نسخه اصلی)
    // ══════════════════════════════════════════════════════════

    public function loginForm(): void
    {
        if ($this->userId() && $this->session->get('role') === 'admin') {
            redirect('/admin/dashboard');
            return;
        }
        view('admin/login', ['title' => 'ورود ادمین']);
    }

    public function login(): void
    {
        header('Content-Type: application/json');

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$email || !$password) {
            echo json_encode(['status' => 'error', 'message' => 'تمام فیلدها باید پر شوند.']);
            return;
        }

        try {
            $credentials = $this->dashboardService->attemptLogin($email, $password);

            if (!$credentials) {
                echo json_encode(['status' => 'error', 'message' => 'ایمیل یا رمز عبور اشتباه است.']);
                return;
            }

            $this->session->set('user_id', $credentials['id']);
            $this->session->set('role', $credentials['role']);

            echo json_encode([
                'status'   => 'success',
                'message'  => 'ورود موفقیت‌آمیز بود.',
                'redirect' => '/admin/dashboard',
            ]);

        } catch (\Throwable $e) {
            logger()->error('Admin login failed', ['error' => $e->getMessage()]);
            echo json_encode(['status' => 'error', 'message' => 'خطای سرور، لطفاً دوباره تلاش کنید.']);
        }
    }

    public function logout(): void
    {
        $this->session->destroy();
        redirect('/admin/login');
    }
}