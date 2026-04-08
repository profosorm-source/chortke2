<?php
namespace App\Controllers\Admin;
use App\Models\SeoAd;

/**
 * Admin — مدیریت تبلیغات SEO Ad
 */
class SeoAdController extends BaseAdminController
{
    private SeoAd $model;

    public function __construct(SeoAd $m)
    {
        parent::__construct();
        $this->model = $m;
    }

    public function index(): void
    {
        $status = $this->request->get('status') ?? '';
        $items  = $this->model->adminList($status, 30, 0);
        view('admin.seo-ad.index', [
            'title'  => 'مدیریت SEO Ad',
            'items'  => $items,
            'status' => $status,
        ]);
    }

    public function approve(): void
    {
        $ok = $this->model->setStatus((int)$this->request->param('id'), 'active');
        if (is_ajax()) { $this->response->json(['success' => $ok]); return; }
        redirect(url('/admin/seo-ad'));
    }

    public function reject(): void
    {
        $reason = trim($this->request->post('reason') ?? '');
        $ok = $this->model->setStatus(
            (int)$this->request->param('id'), 'rejected',
            $reason ?: 'مدیر رد کرد'
        );
        if (is_ajax()) { $this->response->json(['success' => $ok]); return; }
        redirect(url('/admin/seo-ad'));
    }

    public function pause(): void
    {
        $ok = $this->model->setStatus((int)$this->request->param('id'), 'paused');
        if (is_ajax()) { $this->response->json(['success' => $ok]); return; }
        redirect(url('/admin/seo-ad'));
    }
}
