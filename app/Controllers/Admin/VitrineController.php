<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\VitrineListing;
use App\Models\VitrineRequest;
use App\Services\VitrineService;
use App\Services\WalletService;

/**
 * Admin\VitrineController — پنل مدیریت سرویس ویترین
 */
class VitrineController extends BaseAdminController
{
    private VitrineListing $listing;
    private VitrineRequest $requestModel;
    private VitrineService $service;
    private WalletService  $wallet;

    public function __construct(
        VitrineListing $listing,
        VitrineRequest $requestModel,
        VitrineService $service,
        WalletService  $wallet
    ) {
        parent::__construct();
        $this->listing      = $listing;
        $this->requestModel = $requestModel;
        $this->service      = $service;
        $this->wallet       = $wallet;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // لیست آگهی‌ها
    // ─────────────────────────────────────────────────────────────────────────

    public function index(): void
    {
        $filters = [
            'status'   => $this->request->get('status')   ?? '',
            'category' => $this->request->get('category') ?? '',
            'type'     => $this->request->get('type')     ?? '',
            'search'   => $this->request->get('search')   ?? '',
        ];

        $page     = max(1, (int) ($this->request->get('page') ?? 1));
        $perPage  = 30;
        $listings = $this->listing->adminList($filters, $perPage, ($page - 1) * $perPage);
        $total    = $this->listing->adminCount($filters);
        $stats    = $this->listing->adminStats();

        view('admin.vitrine.index', [
            'title'      => 'مدیریت ویترین',
            'listings'   => $listings,
            'total'      => $total,
            'page'       => $page,
            'pages'      => (int) ceil($total / $perPage),
            'filters'    => $filters,
            'stats'      => $stats,
            'statuses'   => $this->listing->statuses(),
            'categories' => $this->listing->categories(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تایید / رد آگهی
    // ─────────────────────────────────────────────────────────────────────────

    public function approve(): void
    {
        $id      = (int) $this->request->param('id');
        $listing = $this->listing->find($id);

        if (!$listing || $listing->status !== VitrineListing::STATUS_PENDING) {
            $this->jsonOrRedirect(false, 'آگهی یافت نشد یا وضعیت آن مناسب نیست.', url('/admin/vitrine'));
            return;
        }

        $ok = $this->listing->updateStatus($id, VitrineListing::STATUS_ACTIVE);

        if ($ok) {
            // اعلان به فروشنده
            $this->service->notifyListingApproved((int) $listing->seller_id, $listing);
            // اعلان دسته به علاقه‌مندان
            $this->service->notifySimilarListing($listing);
        }

        $this->jsonOrRedirect($ok, $ok ? 'آگهی تایید و منتشر شد.' : 'خطا در تایید.', url('/admin/vitrine'));
    }

    public function reject(): void
    {
        $id     = (int) $this->request->param('id');
        $reason = trim($this->request->post('reason') ?? '');

        $ok = $this->listing->updateStatus($id, VitrineListing::STATUS_REJECTED, [
            'rejection_reason' => $reason,
        ]);

        $this->jsonOrRedirect($ok, $ok ? 'آگهی رد شد.' : 'خطا.', url('/admin/vitrine'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // رسیدگی اختلاف
    // ─────────────────────────────────────────────────────────────────────────

    public function showDispute(): void
    {
        $id      = (int) $this->request->param('id');
        $listing = $this->listing->find($id);

        if (!$listing) {
            $this->session->setFlash('error', 'آگهی یافت نشد.');
            redirect(url('/admin/vitrine'));
            exit;
        }

        view('admin.vitrine.dispute', [
            'title'      => 'رسیدگی به اختلاف — ویترین',
            'listing'    => $listing,
            'categories' => $this->listing->categories(),
            'statuses'   => $this->listing->statuses(),
        ]);
    }

    public function resolve(): void
    {
        $id     = (int) $this->request->param('id');
        $winner = $this->request->post('winner') ?? 'buyer';
        $adminId= (int) ($this->session->get('admin_id') ?? $this->session->get('user_id') ?? 0);

        if (!in_array($winner, ['buyer', 'seller'])) {
            $this->response->json(['success' => false, 'message' => 'مقدار نامعتبر.']);
            return;
        }

        $result = $this->service->resolveDispute($id, $winner, $adminId);
        $this->response->json($result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // آزادسازی دستی اسکرو
    // ─────────────────────────────────────────────────────────────────────────

    public function releaseFunds(): void
    {
        $id      = (int) $this->request->param('id');
        $listing = $this->listing->find($id);

        if (!$listing || $listing->status !== VitrineListing::STATUS_IN_ESCROW) {
            $this->response->json(['success' => false, 'message' => 'آگهی یافت نشد یا در escrow نیست.']);
            return;
        }

        $adminId = (int) ($this->session->get('admin_id') ?? $this->session->get('user_id') ?? 0);
        $result  = $this->service->releaseFundsToSeller($listing, 'admin_manual');

        if ($result['success']) {
            \App\Services\AuditTrail::record('vitrine.admin_release', $adminId, [
                'listing_id' => $id,
                'net'        => $result['net'],
            ]);
        }

        $this->response->json($result);
    }

    public function refund(): void
    {
        $id      = (int) $this->request->param('id');
        $listing = $this->listing->find($id);

        if (!$listing || !in_array($listing->status, [
            VitrineListing::STATUS_IN_ESCROW,
            VitrineListing::STATUS_DISPUTED,
        ])) {
            $this->response->json(['success' => false, 'message' => 'وضعیت آگهی برای استرداد مناسب نیست.']);
            return;
        }

        $amount = $listing->offer_price_usdt ?? $listing->price_usdt;
        $credit = $this->wallet->credit(
            (int) $listing->buyer_id,
            $amount,
            'usdt',
            'vitrine_refund',
            "استرداد ویترین #{$id}"
        );

        if ($credit['success']) {
            $this->listing->updateStatus($id, VitrineListing::STATUS_CANCELLED);
        }

        $this->response->json(['success' => $credit['success'], 'message' => $credit['success'] ? 'وجه به خریدار بازگشت.' : 'خطا.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // تنظیمات ویترین
    // ─────────────────────────────────────────────────────────────────────────

    public function settings(): void
    {
        view('admin.vitrine.settings', [
            'title'           => 'تنظیمات ویترین',
            'commission'      => setting('vitrine_commission_percent', '5'),
            'escrowDays'      => setting('vitrine_escrow_days', '3'),
            'kycRequired'     => setting('vitrine_kyc_required', '1'),
            'minPrice'        => setting('vitrine_min_price_usdt', '1'),
            'maxPrice'        => setting('vitrine_max_price_usdt', '100000'),
            'maxPerUser'      => setting('vitrine_max_active_per_user', '5'),
            'vitrineEnabled'  => (new \App\Models\FeatureFlag())->isEnabled('vitrine_enabled'),
        ]);
    }

    public function saveSettings(): void
    {
        $fields = [
            'vitrine_commission_percent',
            'vitrine_escrow_days',
            'vitrine_kyc_required',
            'vitrine_min_price_usdt',
            'vitrine_max_price_usdt',
            'vitrine_max_active_per_user',
        ];

        $db = \Core\Container::getInstance()->make(\Core\Database::class);
        foreach ($fields as $key) {
            $value = $this->request->post($key);
            if ($value !== null) {
                $db->prepare("UPDATE system_settings SET value = ? WHERE `key` = ?")->execute([$value, $key]);
            }
        }

        // Feature Flag ویترین
        $enabled = $this->request->post('vitrine_enabled') === '1' ? 1 : 0;
        $db->prepare("UPDATE feature_flags SET enabled = ? WHERE name = 'vitrine_enabled'")->execute([$enabled]);

        $this->jsonOrRedirect(true, 'تنظیمات ذخیره شد.', url('/admin/vitrine/settings'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────────────────

    private function jsonOrRedirect(bool $ok, string $msg, string $redirect): void
    {
        if (is_ajax()) {
            $this->response->json(['success' => $ok, 'message' => $msg]);
            return;
        }
        $this->session->setFlash($ok ? 'success' : 'error', $msg);
        redirect($redirect);
    }
}
