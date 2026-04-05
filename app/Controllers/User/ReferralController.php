<?php

namespace App\Controllers\User;

use App\Models\ReferralCommission;
use App\Models\User;
use App\Services\ReferralCommissionService;
use App\Controllers\User\BaseUserController;

class ReferralController extends BaseUserController
{
    private \App\Services\ReferralCommissionService $referralCommissionService;
    // $userModel از BaseUserController ارث‌بری شده (protected) — تعریف مجدد حذف شد
    private \App\Models\ReferralCommission $referralCommissionModel;
    public function __construct(
        \App\Models\ReferralCommission $referralCommissionModel,
        \App\Services\ReferralCommissionService $referralCommissionService)
    {
        parent::__construct();
        $this->referralCommissionModel = $referralCommissionModel;
        $this->referralCommissionService = $referralCommissionService;
    }

    /**
     * صفحه اصلی زیرمجموعه‌گیری
     */
    public function index()
    {
                $userId = $this->userId();

        $userModel = $this->userModel;
        $user = $userModel->find($userId);

        $commissionModel = $this->referralCommissionModel;

        // آمار کمیسیون
        $stats = $commissionModel->getReferrerStats($userId);

        // تعداد زیرمجموعه
        $referredCount = $commissionModel->countReferredUsers($userId);

        // لیست زیرمجموعه‌ها
        $referredUsers = $commissionModel->getReferredUsers($userId, 10, 0);

        // آخرین کمیسیون‌ها
        $recentCommissions = $commissionModel->getByReferrer($userId, [], 10, 0);

        // لینک دعوت
        $referralLink = url('/register?ref=' . ($user->referral_code ?? ''));

        // درصدهای فعال
        $percents = [
            'task_reward'  => setting('referral_commission_task_percent', 10),
            'investment'   => setting('referral_commission_investment_percent', 5),
            'vip_purchase' => setting('referral_commission_vip_percent', 8),
            'story_order'  => setting('referral_commission_story_percent', 5),
        ];

        return view('user.referral.index', [
            'user'              => $user,
            'stats'             => $stats,
            'referredCount'     => $referredCount,
            'referredUsers'     => $referredUsers,
            'recentCommissions' => $recentCommissions,
            'referralLink'      => $referralLink,
            'percents'          => $percents,
            'sourceTypes'       => ReferralCommissionService::sourceTypes(),
        ]);
    }

    /**
     * لیست کمیسیون‌ها (Ajax/JSON)
     */
    public function commissions()
    {
                                $userId = $this->userId();

        $filters = [
            'status'      => $this->request->get('status'),
            'source_type' => $this->request->get('source_type'),
            'currency'    => $this->request->get('currency'),
        ];

        $page = \max(1, (int) $this->request->get('page', 1));
        $limit = 15;
        $offset = ($page - 1) * $limit;

        $commissionModel = $this->referralCommissionModel;
        $commissions = $commissionModel->getByReferrer($userId, $filters, $limit, $offset);
        $total = $commissionModel->countByReferrer($userId, $filters);

        // تبدیل تاریخ به شمسی
        foreach ($commissions as &$c) {
            $c->created_at_jalali = to_jalali($c->created_at ?? '');
            $c->paid_at_jalali = $c->paid_at ? to_jalali($c->paid_at) : null;
            $c->source_label = ($this->referralCommissionService)->getSourceLabel($c->source_type);
            $c->status_label = self::statusLabel($c->status);
            $c->status_class = self::statusClass($c->status);
        }
        unset($c);

        $this->response->json([
            'success'     => true,
            'commissions' => $commissions,
            'total'       => $total,
            'page'        => $page,
            'pages'       => \ceil($total / $limit),
        ]);
    }

    /**
     * لیست زیرمجموعه‌ها (Ajax/JSON)
     */
    public function referredUsers()
    {
                                $userId = $this->userId();

        $page = \max(1, (int) $this->request->get('page', 1));
        $limit = 15;
        $offset = ($page - 1) * $limit;

        $commissionModel = $this->referralCommissionModel;
        $users = $commissionModel->getReferredUsers($userId, $limit, $offset);
        $total = $commissionModel->countReferredUsers($userId);

        foreach ($users as &$u) {
            $u->joined_at_jalali = to_jalali($u->joined_at ?? '');
        }
        unset($u);

        $this->response->json([
            'success' => true,
            'users'   => $users,
            'total'   => $total,
            'page'    => $page,
            'pages'   => \ceil($total / $limit),
        ]);
    }

    /**
     * برچسب وضعیت
     */
    private static function statusLabel(string $status): string
    {
        $labels = [
            'pending'   => 'در انتظار',
            'paid'      => 'پرداخت شده',
            'cancelled' => 'لغو شده',
            'failed'    => 'ناموفق',
        ];
        return $labels[$status] ?? $status;
    }

    /**
     * کلاس CSS وضعیت
     */
    private static function statusClass(string $status): string
    {
        $classes = [
            'pending'   => 'badge-warning',
            'paid'      => 'badge-success',
            'cancelled' => 'badge-danger',
            'failed'    => 'badge-danger',
        ];
        return $classes[$status] ?? 'badge-secondary';
    }
}