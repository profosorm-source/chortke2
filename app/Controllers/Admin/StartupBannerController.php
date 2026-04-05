<?php
namespace App\Controllers\Admin;
use App\Models\StartupBanner;

class StartupBannerController extends BaseAdminController
{
    private StartupBanner $model;
    public function __construct(StartupBanner $m) { parent::__construct(); $this->model=$m; }

    public function index(): void {
        $filters = ['status'=>$this->request->get('status')??''];
        $banners = $this->model->adminList($filters,30,0);
        view('admin.startup-banner.index', ['title'=>'مدیریت بنر کسب‌وکار نوپا','banners'=>$banners,'filters'=>$filters]);
    }

    public function approve(): void {
        $ok = $this->model->approve((int)$this->request->param('id'));
        if (is_ajax()) { $this->response->json(['success'=>$ok]); return; }
        $this->session->setFlash($ok?'success':'error', $ok?'بنر تایید و فعال شد.':'خطا');
        redirect(url('/admin/startup-banner'));
    }

    public function reject(): void {
        $ok = $this->model->reject((int)$this->request->param('id'), $this->request->post('reason')??'');
        if (is_ajax()) { $this->response->json(['success'=>$ok]); return; }
        $this->session->setFlash($ok?'success':'error', $ok?'بنر رد شد.':'خطا');
        redirect(url('/admin/startup-banner'));
    }

    public function toggle(): void {
        $ok = $this->model->toggle((int)$this->request->param('id'));
        if (is_ajax()) { $this->response->json(['success'=>$ok]); return; }
        redirect(url('/admin/startup-banner'));
    }
}
