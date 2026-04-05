<?php
namespace App\Controllers\User;
use App\Models\StartupBanner;
use App\Services\UploadService;
use App\Services\WalletService;

class StartupBannerController extends BaseUserController
{
    private StartupBanner $model;
    private UploadService $upload;
    private WalletService $wallet;

    public function __construct(StartupBanner $m, UploadService $u, WalletService $w) { parent::__construct(); $this->model=$m; $this->upload=$u; $this->wallet=$w; }

    public function index(): void {
        view('user.startup-banner.index', ['title'=>'بنر کسب‌وکار نوپا','banners'=>$this->model->getByUser((int)user_id()),'price'=>(float)setting('startup_banner_price',0),'days'=>(int)setting('startup_banner_days',7)]);
    }

    public function create(): void {
        view('user.startup-banner.create', ['title'=>'ثبت بنر کسب‌وکار نوپا','price'=>(float)setting('startup_banner_price',0),'days'=>(int)setting('startup_banner_days',7)]);
    }

    public function store(): void {
        $userId = (int)user_id();
        $data = $this->request->body();
        $price = (float)setting('startup_banner_price',0);
        if (empty($_FILES['image']['name'])) { $this->session->setFlash('error','تصویر بنر الزامی است.'); redirect(url('/startup-banner/create')); return; }
        $up = $this->upload->uploadFile($_FILES['image'], 'startup-banner');
        if (!$up['success']) { $this->session->setFlash('error','خطا در آپلود تصویر.'); redirect(url('/startup-banner/create')); return; }
        $data['image_path'] = $up['path'];
        if ($price>0) {
            $debit = $this->wallet->debit($userId,$price,'irt','startup_banner','ثبت بنر نوپا');
            if (!$debit['success']) { $this->session->setFlash('error',$debit['message']??'موجودی کافی نیست.'); redirect(url('/startup-banner/create')); return; }
        }
        $r = $this->model->create(array_merge($data,['user_id'=>$userId,'duration_days'=>(int)setting('startup_banner_days',7),'price'=>$price]));
        if ($r) { $this->session->setFlash('success','بنر ثبت شد و پس از تایید مدیر نمایش داده می‌شود.'); redirect(url('/startup-banner')); }
        else { if ($price>0) $this->wallet->credit($userId,$price,'irt','startup_banner_refund','برگشت هزینه بنر'); $this->session->setFlash('error','خطا در ثبت.'); redirect(url('/startup-banner/create')); }
    }

    public function show(): void {
        $id = (int)$this->request->param('id');
        $banner = $this->model->find($id);
        if (!$banner||(int)$banner->user_id!==(int)user_id()) { redirect(url('/startup-banner')); return; }
        view('user.startup-banner.show', ['title'=>'جزئیات بنر','banner'=>$banner]);
    }
}
