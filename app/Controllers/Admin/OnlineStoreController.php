<?php
namespace App\Controllers\Admin;
use App\Models\OnlineListing;
use App\Services\WalletService;

class OnlineStoreController extends BaseAdminController
{
    private OnlineListing $model;
    private WalletService $wallet;
    public function __construct(OnlineListing $m, WalletService $w) { parent::__construct(); $this->model=$m; $this->wallet=$w; }

    public function index(): void {
        $filters = ['status'=>$this->request->get('status')??''];
        $page = max(1,(int)($this->request->get('page')??1));
        $listings = $this->model->adminList($filters,30,($page-1)*30);
        $total = $this->model->adminCount($filters);
        view('admin.online-store.index', ['title'=>'مدیریت Online Store','listings'=>$listings,'total'=>$total,'page'=>$page,'pages'=>ceil($total/30),'filters'=>$filters,'statuses'=>$this->model->statuses()]);
    }

    public function approve(): void {
        $ok = $this->model->updateStatus((int)$this->request->param('id'), 'active', ['bio_verified'=>1]);
        $this->jsonOrFlash($ok, $ok?'آگهی تایید شد.':'خطا', url('/admin/online-store'));
    }

    public function reject(): void {
        $id = (int)$this->request->param('id');
        $reason = $this->request->post('reason')??'';
        $listing = $this->model->find($id);
        $ok = $this->model->updateStatus($id, 'rejected', ['rejection_reason'=>$reason]);
        if ($ok && $listing && $listing->status==='in_escrow' && $listing->buyer_id) {
            $this->wallet->credit((int)$listing->buyer_id, $listing->price_usdt, 'usdt', 'store_refund', "رد آگهی #{$id}");
        }
        $this->jsonOrFlash($ok, $ok?'آگهی رد شد.':'خطا', url('/admin/online-store'));
    }

    public function showDispute(): void {
        $listing = $this->model->find((int)$this->request->param('id'));
        view('admin.online-store.dispute', ['title'=>'رسیدگی اختلاف','listing'=>$listing]);
    }

    public function resolve(): void {
        $id = (int)$this->request->param('id');
        $winner = $this->request->post('winner')??'buyer';
        $listing = $this->model->find($id);
        if (!$listing) { $this->response->json(['success'=>false]); return; }
        if ($winner==='seller') {
            $net = round($listing->price_usdt*(1-(float)setting('online_store_commission_percent',5)/100),6);
            $this->wallet->credit((int)$listing->seller_id,$net,'usdt','store_sale',"اختلاف #{$id}—فروشنده");
            $this->model->updateStatus($id,'sold');
        } else {
            $this->wallet->credit((int)$listing->buyer_id,$listing->price_usdt,'usdt','store_refund',"اختلاف #{$id}—خریدار");
            $this->model->updateStatus($id,'active');
        }
        $this->response->json(['success'=>true]);
    }

    public function releaseFunds(): void {
        $id = (int)$this->request->param('id');
        $listing = $this->model->find($id);
        if (!$listing) { $this->response->json(['success'=>false]); return; }
        $net = round($listing->price_usdt*(1-(float)setting('online_store_commission_percent',5)/100),6);
        $this->wallet->credit((int)$listing->seller_id,$net,'usdt','store_sale',"آزاد #{$id}");
        $this->model->updateStatus($id,'sold');
        $this->response->json(['success'=>true]);
    }

    public function refund(): void {
        $id = (int)$this->request->param('id');
        $listing = $this->model->find($id);
        if (!$listing) { $this->response->json(['success'=>false]); return; }
        $this->wallet->credit((int)$listing->buyer_id,$listing->price_usdt,'usdt','store_refund',"بازگشت #{$id}");
        $this->model->updateStatus($id,'cancelled');
        $this->response->json(['success'=>true]);
    }

    private function jsonOrFlash(bool $ok, string $msg, string $redirect): void {
        if (is_ajax()) { $this->response->json(['success'=>$ok,'message'=>$msg]); return; }
        $this->session->setFlash($ok?'success':'error', $msg);
        redirect($redirect);
    }
}
