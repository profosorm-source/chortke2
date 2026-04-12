<?php

namespace App\Controllers\Admin;
use Core\Database;

use App\Models\ReferralCommission;
use App\Middleware\PermissionMiddleware;
use App\Services\ReferralCommissionService;
use Core\Validator;
use App\Controllers\Admin\BaseAdminController;

class ReferralController extends BaseAdminController
{
    private \App\Services\WalletService $walletService;
    private \App\Services\ReferralCommissionService $referralCommissionService;
    private \App\Models\ReferralCommission $referralCommissionModel;
    private \App\Models\User $userModel;
    private Database $db;
    public function __construct(Database $db,
        \App\Models\ReferralCommission $referralCommissionModel,
        \App\Models\User $userModel,
        \App\Services\ReferralCommissionService $referralCommissionService,
        \App\Services\WalletService $walletService){
        parent::__construct();
        $this->db = $db;
        $this->referralCommissionModel = $referralCommissionModel;
        $this->userModel = $userModel;
        $this->referralCommissionService = $referralCommissionService;
        $this->walletService = $walletService;
    }

    /**
     * لیست کمیسیون‌ها
     */
    public function index()
    {
        PermissionMiddleware::require('referrals.view');

                $commissionModel = $this->referralCommissionModel;

        $filters = [
            'status'      => $this->request->get('status'),
            'source_type' => $this->request->get('source_type'),
            'currency'    => $this->request->get('currency'),
            'search'      => $this->request->get('search'),
        ];

        $page = \max(1, (int) $this->request->get('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $commissions = $commissionModel->adminList($filters, $limit, $offset);
        $total = $commissionModel->adminCount($filters);
        $stats = $commissionModel->globalStats();
        $topReferrers = $commissionModel->topReferrers('irt', 5);

        log_activity('referrals.view', 'مشاهده لیست کمیسیون‌ها');

        return view('admin.referral.index', [
            'commissions'   => $commissions,
            'total'         => $total,
            'page'          => $page,
            'pages'         => \ceil($total / $limit),
            'filters'       => $filters,
            'stats'         => $stats,
            'topReferrers'  => $topReferrers,
            'sourceTypes'   => ReferralCommissionService::sourceTypes(),
        ]);
    }

    /**
     * تنظیمات کمیسیون
     */
    public function settings()
    {
        PermissionMiddleware::require('referrals.manage');

        return view('admin.referral.settings', [
            'sourceTypes' => ReferralCommissionService::sourceTypes(),
        ]);
    }

    /**
     * ذخیره تنظیمات کمیسیون
     */
    public function saveSettings()
    {
        PermissionMiddleware::require('referrals.manage');

                        
        if (!verify_csrf_token($this->request->post('csrf_token'))) {
            $this->session->setFlash('error', 'توکن امنیتی نامعتبر است.');
            return redirect(url('/admin/referral/settings'));
        }

        $settingsKeys = [
            'referral_commission_task_percent',
            'referral_commission_investment_percent',
            'referral_commission_vip_percent',
            'referral_commission_story_percent',
            'referral_commission_enabled',
            'referral_commission_min_payout',
            'referral_commission_min_payout_usdt',
            'referral_commission_auto_pay',
            'referral_max_daily_signups',
            'referral_farming_threshold',
            'referral_farming_action',
            'referral_signup_bonus',
            'referral_signup_bonus_usdt',
        ];

        $stmt = $db->prepare("
            UPDATE system_settings SET setting_value = ? WHERE setting_key = ?
        ");

        $updated = [];
        foreach ($settingsKeys as $key) {
            $value = $this->request->post($key);
            if ($value !== null) {
                $stmt->execute([(string) $value, $key]);
                $updated[$key] = $value;
            }
        }

        log_activity('referrals.settings_updated', 'بروزرسانی تنظیمات کمیسیون', $updated);

        $this->session->setFlash('success', 'تنظیمات کمیسیون با موفقیت ذخیره شد.');
        return redirect(url('/admin/referral/settings'));
    }

    /**
     * جزئیات کمیسیون‌های یک کاربر
     */
    public function userDetail()
    {
        PermissionMiddleware::require('referrals.view');

                $userId = (int) $this->request->param('id');

        $userModel = $this->userModel;
        $user = $userModel->find($userId);

        if (!$user) {
            \http_response_code(404);
            include __DIR__ . '/../../../views/errors/404.php';
            exit;
        }

        $commissionModel = $this->referralCommissionModel;
        $stats = $commissionModel->getReferrerStats($userId);
        $referredUsers = $commissionModel->getReferredUsers($userId, 50, 0);
        $commissions = $commissionModel->getByReferrer($userId, [], 50, 0);
        $referredCount = $commissionModel->countReferredUsers($userId);

        return view('admin.referral.user-detail', [
            'user'          => $user,
            'stats'         => $stats,
            'referredUsers' => $referredUsers,
            'commissions'   => $commissions,
            'referredCount' => $referredCount,
        ]);
    }

    /**
     * لغو کمیسیون (Ajax)
     */
    public function cancel()
    {
        PermissionMiddleware::require('referrals.manage');

                
        $id = (int) $this->request->param('id');
        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $reason = $body['reason'] ?? 'لغو توسط مدیر';

        $service = $this->referralCommissionService;
        $cancelled = $service->cancelCommission($id, $reason);

        if (!$cancelled) {
            $this->response->json([
                'success' => false,
                'message' => 'امکان لغو این کمیسیون وجود ندارد. فقط کمیسیون‌های در انتظار قابل لغو هستند.',
            ], 422);
            return;
        }

        log_activity('referrals.commission_cancelled', 'لغو کمیسیون', [
            'commission_id' => $id,
            'reason'        => $reason,
        ]);

        $this->response->json([
            'success' => true,
            'message' => 'کمیسیون با موفقیت لغو شد.',
        ]);
    }

    /**
     * پرداخت دسته‌ای (Ajax)
     */
    public function batchPay()
    {
        PermissionMiddleware::require('referrals.manage');

                
        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $currency = $body['currency'] ?? 'irt';

        if (!\in_array($currency, ['irt', 'usdt'])) {
            $this->response->json(['success' => false, 'message' => 'ارز نامعتبر'], 422);
            return;
        }

        $service = $this->referralCommissionService;
        $results = $service->batchPay($currency);

        log_activity('referrals.batch_pay', 'پرداخت دسته‌ای کمیسیون', $results);

        $this->response->json([
            'success' => true,
            'message' => "پرداخت دسته‌ای انجام شد: {$results['success']} موفق، {$results['failed']} ناموفق، {$results['skipped']} رد شده",
            'results' => $results,
        ]);
    }
}