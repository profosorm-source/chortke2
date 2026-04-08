<?php

namespace App\Controllers\User;

use App\Models\ReferralCommission;
use App\Services\ReferralCommissionService;
use App\Controllers\User\BaseUserController;

class ReferralController extends BaseUserController
{
    private ReferralCommission          $referralCommissionModel;
    private ReferralCommissionService   $referralCommissionService;

    public function __construct(
        ReferralCommission        $referralCommissionModel,
        ReferralCommissionService $referralCommissionService
    ) {
        parent::__construct();
        $this->referralCommissionModel   = $referralCommissionModel;
        $this->referralCommissionService = $referralCommissionService;
    }

    /**
     * صفحه اصلی زیرمجموعه‌گیری
     */
    public function index()
    {
        $userId = $this->userId();
        $user   = $this->userModel->find($userId);

        // آمار کلی کمیسیون‌ها
        $stats = $this->referralCommissionModel->getReferrerStats($userId);

        // تعداد و لیست زیرمجموعه‌ها
        $referredCount = $this->referralCommissionModel->countReferredUsers($userId);
        $referredUsers = $this->referralCommissionModel->getReferredUsers($userId, 10, 0);

        // آخرین ۱۰ کمیسیون
        $recentCommissions = $this->referralCommissionModel->getByReferrer($userId, [], 10, 0);

        // برچسب‌گذاری کمیسیون‌ها
        foreach ($recentCommissions as $c) {
            $c->source_label = $this->referralCommissionService->getSourceLabel($c->source_type);
            $c->status_label = self::statusLabel($c->status);
            $c->status_class = self::statusClass($c->status);
        }

        // لینک و درصدهای فعال
        $referralLink = url('/register?ref=' . ($user->referral_code ?? ''));

        $percents = [
            'task_reward'  => setting('referral_commission_task_percent',       10),
            'investment'   => setting('referral_commission_investment_percent',   5),
            'vip_purchase' => setting('referral_commission_vip_percent',          8),
            'story_order'  => setting('referral_commission_story_percent',        5),
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
     * لیست کمیسیون‌ها (AJAX/JSON)
     */
    public function commissions()
    {
        $userId = $this->userId();

        $filters = array_filter([
            'status'      => $this->request->get('status'),
            'source_type' => $this->request->get('source_type'),
            'currency'    => $this->request->get('currency'),
        ]);

        $page   = max(1, (int) $this->request->get('page', 1));
        $limit  = 15;
        $offset = ($page - 1) * $limit;

        $commissions = $this->referralCommissionModel->getByReferrer($userId, $filters, $limit, $offset);
        $total       = $this->referralCommissionModel->countByReferrer($userId, $filters);

        foreach ($commissions as $c) {
            $c->created_at_jalali = to_jalali($c->created_at ?? '');
            $c->paid_at_jalali    = $c->paid_at ? to_jalali($c->paid_at) : null;
            $c->source_label      = $this->referralCommissionService->getSourceLabel($c->source_type);
            $c->status_label      = self::statusLabel($c->status);
            $c->status_class      = self::statusClass($c->status);
        }

        $this->response->json([
            'success'     => true,
            'commissions' => $commissions,
            'total'       => $total,
            'page'        => $page,
            'pages'       => (int) ceil($total / $limit),
        ]);
    }

    /**
     * لیست زیرمجموعه‌ها (AJAX/JSON)
     */
    public function referredUsers()
    {
        $userId = $this->userId();

        $page   = max(1, (int) $this->request->get('page', 1));
        $limit  = 15;
        $offset = ($page - 1) * $limit;

        $users = $this->referralCommissionModel->getReferredUsers($userId, $limit, $offset);
        $total = $this->referralCommissionModel->countReferredUsers($userId);

        foreach ($users as $u) {
            $u->joined_at_jalali = to_jalali($u->joined_at ?? '');
        }

        $this->response->json([
            'success' => true,
            'users'   => $users,
            'total'   => $total,
            'page'    => $page,
            'pages'   => (int) ceil($total / $limit),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────

    private static function statusLabel(string $status): string
    {
        return [
            'pending'   => 'در انتظار',
            'paid'      => 'پرداخت شده',
            'cancelled' => 'لغو شده',
            'failed'    => 'ناموفق',
        ][$status] ?? $status;
    }

    private static function statusClass(string $status): string
    {
        return [
            'pending'   => 'ref-badge--pending',
            'paid'      => 'ref-badge--paid',
            'cancelled' => 'ref-badge--danger',
            'failed'    => 'ref-badge--danger',
        ][$status] ?? 'ref-badge--muted';
    }
}
