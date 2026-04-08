<?php

namespace App\Controllers\User;

use App\Models\SeoAd;
use App\Services\WalletService;

/**
 * SEO Ad — تبلیغ SEO توسط کاربر
 * مسیر پایه: /seo-ad
 */
class SeoAdController extends BaseUserController
{
    private SeoAd         $model;
    private WalletService $wallet;

    public function __construct(SeoAd $m, WalletService $w)
    {
        parent::__construct();
        $this->model  = $m;
        $this->wallet = $w;
    }

    /** لیست آگهی‌های من */
    public function index(): void
    {
        view('user.seo-ad.index', [
            'title' => 'SEO Ad — تبلیغات من',
            'ads'   => $this->model->getByUser((int)user_id()),
        ]);
    }

    /** فرم ثبت آگهی جدید */
    public function create(): void
    {
        view('user.seo-ad.create', [
            'title'         => 'ثبت تبلیغ SEO Ad',
            'pricePerClick' => (float)setting('seo_ad_price_per_click', 500),
            'minBudget'     => (float)setting('seo_ad_min_budget', 10000),
        ]);
    }

    /** ذخیره آگهی جدید */
    public function store(): void
    {
        $uid  = (int)user_id();
        $data = $this->request->body();

        // اعتبارسنجی
        $budget = (float)($data['budget'] ?? 0);
        $minBudget = (float)setting('seo_ad_min_budget', 10000);

        if (empty($data['keyword'])) {
            $this->session->setFlash('error', 'کلمه کلیدی الزامی است.');
            redirect(url('/seo-ad/create')); return;
        }
        if (empty($data['site_url']) || !filter_var($data['site_url'], FILTER_VALIDATE_URL)) {
            $this->session->setFlash('error', 'آدرس سایت معتبر نیست.');
            redirect(url('/seo-ad/create')); return;
        }
        if ($budget < $minBudget) {
            $this->session->setFlash('error', 'حداقل بودجه ' . number_format($minBudget) . ' تومان است.');
            redirect(url('/seo-ad/create')); return;
        }

        // کسر از کیف پول
        $debit = $this->wallet->debit(
            $uid, $budget, 'irt', 'seo_ad',
            'SEO Ad: ' . $data['keyword']
        );
        if (!$debit['success']) {
            $this->session->setFlash('error', $debit['message'] ?? 'موجودی کافی نیست.');
            redirect(url('/seo-ad/create')); return;
        }

        $ad = $this->model->create([
            'user_id'         => $uid,
            'site_url'        => $data['site_url'],
            'title'           => $data['title'] ?? $data['keyword'],
            'keyword'         => $data['keyword'],
            'description'     => $data['description'] ?? null,
            'budget'          => $budget,
            'price_per_click' => (float)setting('seo_ad_price_per_click', 500),
            'deadline'        => !empty($data['deadline']) ? $data['deadline'] : null,
        ]);

        if ($ad) {
            $this->session->setFlash('success', 'تبلیغ SEO Ad ثبت شد و پس از تایید مدیر فعال می‌شود.');
            redirect(url('/seo-ad'));
        } else {
            // برگشت وجه
            $this->wallet->credit($uid, $budget, 'irt', 'seo_ad_refund', 'برگشت بودجه SEO Ad');
            $this->session->setFlash('error', 'خطا در ثبت. لطفاً دوباره تلاش کنید.');
            redirect(url('/seo-ad/create'));
        }
    }

    /** جزئیات یک آگهی */
    public function show(): void
    {
        $ad = $this->model->findByUser(
            (int)$this->request->param('id'),
            (int)user_id()
        );
        if (!$ad) { redirect(url('/seo-ad')); return; }

        view('user.seo-ad.show', ['title' => 'جزئیات SEO Ad', 'ad' => $ad]);
    }

    /** توقف موقت */
    public function pause(): void
    {
        $this->model->setStatusByUser(
            (int)$this->request->param('id'), (int)user_id(), 'paused'
        );
        if (is_ajax()) { $this->response->json(['success' => true]); return; }
        redirect(url('/seo-ad'));
    }

    /** ادامه */
    public function resume(): void
    {
        $this->model->setStatusByUser(
            (int)$this->request->param('id'), (int)user_id(), 'active'
        );
        if (is_ajax()) { $this->response->json(['success' => true]); return; }
        redirect(url('/seo-ad'));
    }
}
