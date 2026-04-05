<?php
namespace App\Controllers\User;
use App\Models\OnlineListing;
use App\Services\WalletService;
use App\Services\UploadService;
use Core\Database;

class OnlineStoreController extends BaseUserController
{
    private OnlineListing $model;
    private WalletService $wallet;
    private UploadService $upload;
    private Database $db;

    public function __construct(OnlineListing $m, WalletService $w, UploadService $u, Database $db) {
        parent::__construct();
        $this->model  = $m;
        $this->wallet = $w;
        $this->upload = $u;
        $this->db     = $db;
    }

    public function index(): void {
        $filters = ['platform'=>$this->request->get('platform')??'','search'=>$this->request->get('search')??''];
        $page = max(1,(int)($this->request->get('page')??1));
        $listings = $this->model->getActive($filters, 20, ($page-1)*20);
        view('user.online-store.index', ['title'=>'Online Store — بازار پیج/کانال','listings'=>$listings,'filters'=>$filters,'page'=>$page,'platforms'=>$this->model->platforms()]);
    }

    public function mySales(): void {
        view('user.online-store.my-sales', ['title'=>'آگهی‌های فروش من','listings'=>$this->model->getBySeller((int)user_id()),'statuses'=>$this->model->statuses()]);
    }

    public function create(): void {
        view('user.online-store.create', ['title'=>'ثبت آگهی فروش','platforms'=>$this->model->platforms(),'siteUrl'=>setting('site_url',url('/'))]);
    }

    public function store(): void {
        $userId = (int)user_id();
        $data = $this->request->body();
        $screenshots = [];
        for ($i=1;$i<=3;$i++) {
            if (!empty($_FILES["screenshot_$i"]['name'])) { $up=$this->upload->upload($_FILES["screenshot_$i"],'store'); if($up['success']) $screenshots[]=$up['path']; }
        }
        $data = array_merge($data, ['seller_id'=>$userId,'screenshots'=>json_encode($screenshots)]);
        $r = $this->model->create($data);
        if ($r) { $this->session->setFlash('success','آگهی ثبت شد. لطفاً آدرس سایت را در بیو قرار دهید تا مدیر تایید کند.'); redirect(url('/online-store/sell')); }
        else { $this->session->setFlash('error','خطا در ثبت آگهی.'); redirect(url('/online-store/sell/create')); }
    }

    public function show(): void {
        $id = (int)$this->request->param('id');
        $listing = $this->model->find($id);
        if (!$listing||in_array($listing->status,['rejected','cancelled'])) { redirect(url('/online-store')); return; }
        $userId = (int)user_id();
        view('user.online-store.show', ['title'=>'جزئیات آگهی','listing'=>$listing,'isSeller'=>(int)$listing->seller_id===$userId,'isBuyer'=>(int)($listing->buyer_id??0)===$userId,'statuses'=>$this->model->statuses()]);
    }

    public function buy(): void {
        $userId = (int)user_id();
        $id = (int)$this->request->param('id');
        $listing = $this->model->find($id);
        if (!$listing || $listing->status !== 'active') {
            $this->response->json(['success'=>false,'message'=>'آگهی فعال نیست.']); return;
        }
        if ((int)$listing->seller_id === $userId) {
            $this->response->json(['success'=>false,'message'=>'شما فروشنده این آگهی هستید.']); return;
        }

        // FIX B-11: debit و updateStatus در یک تراکنش atomik
        try {
            $this->db->beginTransaction();

            $debit = $this->wallet->debit($userId, $listing->price_usdt, 'usdt', 'store_escrow', "خرید آگهی #{$id}");
            if (!$debit['success']) {
                $this->db->rollBack();
                $this->response->json(['success'=>false,'message'=>$debit['message'] ?? 'موجودی کافی نیست.']); return;
            }

            $updated = $this->model->updateStatus($id, 'in_escrow', ['buyer_id'=>$userId]);
            if (!$updated) {
                $this->db->rollBack();
                $this->response->json(['success'=>false,'message'=>'خطا در آپدیت وضعیت.']); return;
            }

            $this->db->commit();
            $this->response->json(['success'=>true,'message'=>'پرداخت انجام شد. فروشنده اطلاعات ورود را ارسال می‌کند.']);

        } catch (\Exception $e) {
            $this->db->rollBack();
            logger()->error('online_store.buy.failed', ['id'=>$id,'err'=>$e->getMessage()]);
            $this->response->json(['success'=>false,'message'=>'خطای سیستمی.']);
        }
    }

    public function confirmReceived(): void {
        $userId = (int)user_id();
        $id = (int)$this->request->param('id');
        $listing = $this->model->find($id);
        if (!$listing || (int)$listing->buyer_id !== $userId || $listing->status !== 'in_escrow') {
            $this->response->json(['success'=>false,'message'=>'عملیات غیرمجاز.']); return;
        }

        // FIX B-11: کل عملیات پرداخت + آپدیت وضعیت در یک تراکنش atomik
        try {
            $this->db->beginTransaction();

            $comm = (float)setting('online_store_commission_percent', 5) / 100;
            $net  = round($listing->price_usdt * (1 - $comm), 6);

            $credit = $this->wallet->credit((int)$listing->seller_id, $net, 'usdt', 'store_sale', "فروش آگهی #{$id}");
            if (!$credit['success']) {
                $this->db->rollBack();
                $this->response->json(['success'=>false,'message'=>'خطا در پرداخت به فروشنده.']); return;
            }

            $updated = $this->model->updateStatus($id, 'sold');
            if (!$updated) {
                $this->db->rollBack();
                $this->response->json(['success'=>false,'message'=>'خطا در آپدیت وضعیت.']); return;
            }

            $this->db->commit();
            $this->response->json(['success'=>true,'message'=>'تراکنش تکمیل شد.']);

        } catch (\Exception $e) {
            $this->db->rollBack();
            logger()->error('online_store.confirmReceived.failed', ['id'=>$id,'err'=>$e->getMessage()]);
            $this->response->json(['success'=>false,'message'=>'خطای سیستمی. لطفاً با پشتیبانی تماس بگیرید.']);
        }
    }

    public function dispute(): void {
        $userId = (int)user_id();
        $id = (int)$this->request->param('id');
        $listing = $this->model->find($id);
        $reason = trim($this->request->post('reason')??'');
        if (!$listing||(int)($listing->buyer_id??0)!==$userId||!$reason) { $this->response->json(['success'=>false,'message'=>'اطلاعات ناقص.']); return; }
        $this->model->updateStatus($id, 'disputed', ['admin_note'=>'درخواست خریدار: '.$reason]);
        $this->response->json(['success'=>true,'message'=>'اختلاف ثبت شد. مدیریت بررسی خواهد کرد.']);
    }

    public function myPurchases(): void {
        view('user.online-store.my-purchases', ['title'=>'خریدهای من','listings'=>$this->model->getByBuyer((int)user_id()),'statuses'=>$this->model->statuses()]);
    }
}
