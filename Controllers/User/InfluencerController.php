<?php
namespace App\Controllers\User;

use App\Models\InfluencerProfile;
use App\Models\StoryOrder;
use App\Services\StoryPromotionService;
use App\Services\UploadService;

class InfluencerController extends BaseUserController
{
    private InfluencerProfile $profileModel;
    private StoryOrder $orderModel;
    private StoryPromotionService $promotionService;
    private UploadService $upload;

    public function __construct(InfluencerProfile $pm, StoryOrder $om, StoryPromotionService $ps, UploadService $up)
    {
        parent::__construct();
        $this->profileModel     = $pm;
        $this->orderModel       = $om;
        $this->promotionService = $ps;
        $this->upload           = $up;
    }

    public function myProfile(): void {
        $userId  = (int)user_id();
        $profile = $this->profileModel->findByUserId($userId);
        $orders  = $profile ? $this->orderModel->getByInfluencer((int)$profile->id, 10, 0) : [];
        view('user.influencer.my-profile', ['title'=>'پروفایل Influencer','profile'=>$profile,'orders'=>$orders,'platforms'=>$this->platforms()]);
    }

    public function register(): void {
        $userId = (int)user_id();
        view('user.influencer.register', ['title'=>'ثبت پیج اینفلوئنسر','existing'=>$this->profileModel->findByUserId($userId),'categories'=>$this->profileModel->categories(),'platforms'=>$this->platforms(),'priceFields'=>$this->priceFields()]);
    }

    public function storeProfile(): void {
        $userId = (int)user_id();
        $data   = $this->request->body();
        if (!empty($_FILES['profile_image']['name'])) {
            $up = $this->upload->upload($_FILES['profile_image'], 'influencer');
            if ($up['success']) $data['profile_image'] = $up['path'];
        }
        $existing = $this->profileModel->findByUserId($userId);
        $platform = $data['platform'] ?? 'instagram';
        $merged = array_merge($data, $this->extractPrices($data, $platform), ['user_id'=>$userId]);
        if ($existing) { $ok = $this->profileModel->update((int)$existing->id, $merged); $msg = $ok?'پروفایل بروزرسانی شد.':'خطا'; }
        else { $r = $this->profileModel->create($merged); $ok = (bool)$r; $msg = $ok?'پیج ثبت شد و در انتظار تایید است.':'خطا'; }
        $this->session->setFlash($ok?'success':'error', $msg);
        redirect(url('/influencer'));
    }

    public function myOrders(): void {
        $profile = $this->profileModel->findByUserId((int)user_id());
        if (!$profile) { $this->session->setFlash('error','ابتدا پیج خود را ثبت کنید.'); redirect(url('/influencer/register')); return; }
        $page = max(1,(int)($this->request->get('page')??1));
        $orders = $this->orderModel->getByInfluencer((int)$profile->id, 20, ($page-1)*20);
        view('user.influencer.my-orders', ['title'=>'سفارش‌های دریافتی','profile'=>$profile,'orders'=>$orders,'page'=>$page]);
    }

    public function respondOrder(): void {
        try {
            $r = $this->promotionService->respondToOrder((int)($this->request->body()['order_id'] ?? 0), (int)user_id(), $this->request->body()['action'] ?? '');
            if (is_ajax()) { $this->response->json($r); return; }
            $this->session->setFlash($r['success'] ? 'success' : 'error', $r['message'] ?? '');
        } catch (\Exception $e) {
            logger()->error('influencer.respondOrder.failed', ['err' => $e->getMessage()]);
            if (is_ajax()) { $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']); return; }
            $this->session->setFlash('error', 'خطای سیستمی.');
        }
        redirect(url('/influencer/orders'));
    }

    public function submitProof(): void {
        try {
            $file = null;
            if (!empty($_FILES['proof']['name'])) {
                $up = $this->upload->upload($_FILES['proof'], 'inf-proof');
                if ($up['success']) $file = $up['path'];
            }
            $r = $this->promotionService->submitProof((int)$this->request->param('id'), (int)user_id(), $this->request->post('notes') ?? '', $file);
            if (is_ajax()) { $this->response->json($r); return; }
            $this->session->setFlash($r['success'] ? 'success' : 'error', $r['message'] ?? '');
        } catch (\Exception $e) {
            logger()->error('influencer.submitProof.failed', ['err' => $e->getMessage()]);
            if (is_ajax()) { $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']); return; }
            $this->session->setFlash('error', 'خطای سیستمی.');
        }
        redirect(url('/influencer/orders'));
    }

    /** لیست اینفلوئنسرها برای تبلیغ‌دهندگان — alias به advertise() */
    public function influencers(): void {
        $this->advertise();
    }

    /** سفارش‌های دریافتی پیج من — alias به myOrders() */
    public function myPageOrders(): void {
        $this->myOrders();
    }

    // تبلیغات
    public function advertise(): void {
        $filters = ['platform'=>$this->request->get('platform')??'','category'=>$this->request->get('category')??'','search'=>$this->request->get('search')??''];
        $page = max(1,(int)($this->request->get('page',1)));
        $limit = 12;
        $profiles = $this->profileModel->getVerified($filters, $this->request->get('sort','priority'), $limit, ($page-1)*$limit);
        $total = $this->profileModel->countVerified($filters);
        view('user.influencer.advertise', ['title'=>'اینفلوئنسرها','profiles'=>$profiles,'total'=>$total,'page'=>$page,'pages'=>ceil($total/$limit),'filters'=>$filters,'categories'=>$this->profileModel->categories(),'platforms'=>$this->platforms()]);
    }

    public function createOrder(): void {
        $influencerId = (int)($this->request->get('influencer_id')??0);
        view('user.influencer.create-order', ['title'=>'ثبت سفارش تبلیغ','influencer'=>$influencerId?$this->profileModel->find($influencerId):null,'platforms'=>$this->platforms()]);
    }

    public function storeOrder(): void {
        try {
            $data = array_merge($this->request->body(), ['customer_id' => (int)user_id()]);
            if (!empty($_FILES['brief_file']['name'])) {
                $up = $this->upload->upload($_FILES['brief_file'], 'inf-brief');
                if ($up['success']) $data['brief_file'] = $up['path'];
            }
            $r = $this->promotionService->createOrder($data);
            $this->session->setFlash($r['success'] ? 'success' : 'error', $r['success'] ? 'سفارش ثبت شد.' : ($r['message'] ?? 'خطا'));
            redirect($r['success'] ? url('/influencer/advertise/my-orders') : url('/influencer/advertise'));
        } catch (\Exception $e) {
            logger()->error('influencer.storeOrder.failed', ['err' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطای سیستمی در ثبت سفارش.');
            redirect(url('/influencer/advertise'));
        }
    }

    public function myPlacedOrders(): void {
        $page = max(1,(int)($this->request->get('page')??1));
        $orders = $this->orderModel->getByCustomer((int)user_id(), 20, ($page-1)*20);
        view('user.influencer.my-placed-orders', ['title'=>'سفارش‌های تبلیغ من','orders'=>$orders,'page'=>$page]);
    }

    private function platforms(): array { return ['instagram'=>'اینستاگرام','telegram'=>'تلگرام']; }
    private function priceFields(): array {
        return ['instagram'=>['story_price_24h'=>'استوری ۲۴ ساعته','post_price_24h'=>'پست ۲۴ ساعته','post_price_48h'=>'پست ۴۸ ساعته','post_price_72h'=>'پست ۷۲ ساعته'],
                'telegram'=>['sponsored_post_price'=>'پست اسپانسری','pin_price'=>'پین پیام','forward_price'=>'فوروارد پیام']];
    }
    private function extractPrices(array $d, string $p): array {
        $out = ['story_price_24h'=>0,'post_price_24h'=>0,'post_price_48h'=>0,'post_price_72h'=>0,'sponsored_post_price'=>0,'pin_price'=>0,'forward_price'=>0];
        foreach ($this->priceFields()[$p]??[] as $k=>$_) $out[$k]=(float)($d[$k]??0);
        return $out;
    }
}
