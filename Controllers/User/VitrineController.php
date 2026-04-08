<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Models\VitrineListing;
use App\Models\VitrineRequest;
use App\Services\VitrineService;
use App\Services\FeatureFlagService;

/**
 * VitrineController — پنل کاربری سرویس ویترین
 *
 * تمام آگهی‌ها متن‌محور هستند — هیچ تصویری پذیرفته نمی‌شود
 */
class VitrineController extends BaseUserController
{
    private VitrineListing  $listing;
    private VitrineRequest  $requestModel;
    private VitrineService  $service;
    private FeatureFlagService $flags;

    public function __construct(
        VitrineListing     $listing,
        VitrineRequest     $requestModel,
        VitrineService     $service,
        FeatureFlagService $flags
    ) {
        parent::__construct();
        $this->listing      = $listing;
        $this->requestModel = $requestModel;
        $this->service      = $service;
        $this->flags        = $flags;

        if (!$this->service->isEnabled()) {
            $this->session->setFlash('error', 'سرویس ویترین در حال حاضر غیرفعال است.');
            redirect(url('/dashboard'));
            exit;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // لیست آگهی‌های فروش
    // ─────────────────────────────────────────────────────────────────────────

    public function index(): void
    {
        $filters = [
            'category'   => $this->request->get('category')   ?? '',
            'platform'   => $this->request->get('platform')   ?? '',
            'search'     => $this->request->get('search')      ?? '',
            'min_price'  => $this->request->get('min_price')   ?? '',
            'max_price'  => $this->request->get('max_price')   ?? '',
            'min_members'=> $this->request->get('min_members') ?? '',
            'sort'       => $this->request->get('sort')        ?? 'newest',
        ];

        $page     = max(1, (int) ($this->request->get('page') ?? 1));
        $perPage  = 20;
        $listings = $this->listing->getActive($filters, $perPage, ($page - 1) * $perPage);
        $total    = $this->listing->countActive($filters);

        view('user.vitrine.index', [
            'title'      => 'ویترین — بازار دیجیتال',
            'listings'   => $listings,
            'filters'    => $filters,
            'page'       => $page,
            'pages'      => (int) ceil($total / $perPage),
            'total'      => $total,
            'categories' => $this->listing->categories(),
            'platforms'  => $this->listing->platforms(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // لیست درخواست‌های خریداران
    // ─────────────────────────────────────────────────────────────────────────

    public function wantedIndex(): void
    {
        $filters = [
            'category' => $this->request->get('category') ?? '',
            'platform' => $this->request->get('platform') ?? '',
            'search'   => $this->request->get('search')   ?? '',
        ];

        $page     = max(1, (int) ($this->request->get('page') ?? 1));
        $listings = $this->listing->getWantedListings($filters, 20, ($page - 1) * 20);

        view('user.vitrine.wanted', [
            'title'      => 'ویترین — خریداران (متقاضیان)',
            'listings'   => $listings,
            'filters'    => $filters,
            'page'       => $page,
            'categories' => $this->listing->categories(),
            'platforms'  => $this->listing->platforms(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // فرم ثبت آگهی
    // ─────────────────────────────────────────────────────────────────────────

    public function create(): void
    {
        $userId = (int) user_id();
        $check  = $this->service->canTrade($userId);
        if (!$check['ok']) {
            $this->session->setFlash('error', $check['message']);
            redirect(url('/vitrine'));
            exit;
        }

        view('user.vitrine.create', [
            'title'       => 'ثبت آگهی فروش در ویترین',
            'listingType' => 'sell',
            'categories'  => $this->listing->categories(),
            'platforms'   => $this->listing->platforms(),
        ]);
    }

    public function createWanted(): void
    {
        $userId = (int) user_id();
        $check  = $this->service->canTrade($userId);
        if (!$check['ok']) {
            $this->session->setFlash('error', $check['message']);
            redirect(url('/vitrine'));
            exit;
        }

        view('user.vitrine.create', [
            'title'       => 'ثبت درخواست خرید در ویترین',
            'listingType' => 'buy',
            'categories'  => $this->listing->categories(),
            'platforms'   => $this->listing->platforms(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ذخیره آگهی جدید
    // ─────────────────────────────────────────────────────────────────────────

    public function store(): void
    {
        $userId = (int) user_id();
        $data   = $this->request->body();

        // اعتبارسنجی
        $required = ['title', 'description', 'category', 'price_usdt'];
        foreach ($required as $field) {
            if (empty(trim($data[$field] ?? ''))) {
                $this->session->setFlash('error', 'لطفاً همه فیلدهای ضروری را پر کنید.');
                redirect(url('/vitrine/' . ($data['listing_type'] === 'buy' ? 'wanted/create' : 'sell/create')));
                exit;
            }
        }

        if (mb_strlen(trim($data['title'])) < 5) {
            $this->session->setFlash('error', 'عنوان آگهی باید حداقل ۵ کاراکتر باشد.');
            redirect(url('/vitrine/sell/create'));
            exit;
        }

        if (mb_strlen(trim($data['description'])) < 20) {
            $this->session->setFlash('error', 'توضیحات آگهی باید حداقل ۲۰ کاراکتر باشد.');
            redirect(url('/vitrine/sell/create'));
            exit;
        }

        $result = $this->service->createListing($userId, [
            'listing_type'   => in_array($data['listing_type'] ?? 'sell', ['sell', 'buy']) ? $data['listing_type'] : 'sell',
            'category'       => $data['category'],
            'platform'       => $data['platform'] ?? '',
            'title'          => trim($data['title']),
            'description'    => trim($data['description']),
            'specs'          => !empty($data['specs'])    ? trim($data['specs'])    : null,
            'username'       => !empty($data['username']) ? trim($data['username']) : null,
            'member_count'   => !empty($data['member_count']) ? (int) $data['member_count'] : 0,
            'creation_date'  => !empty($data['creation_date']) ? $data['creation_date'] : null,
            'price_usdt'     => (float) ($data['price_usdt'] ?? 0),
            'min_price_usdt' => !empty($data['min_price_usdt']) ? (float) $data['min_price_usdt'] : null,
        ]);

        if ($result['success']) {
            $this->session->setFlash('success', 'آگهی شما ثبت شد و پس از بررسی توسط تیم ویترین منتشر می‌شود.');
            redirect(url('/vitrine/my-listings'));
        } else {
            $this->session->setFlash('error', $result['message']);
            redirect(url('/vitrine/sell/create'));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // صفحه آگهی
    // ─────────────────────────────────────────────────────────────────────────

    public function show(): void
    {
        $id      = (int) $this->request->param('id');
        $listing = $this->listing->find($id);
        $userId  = (int) user_id();

        if (!$listing || in_array($listing->status, [
            VitrineListing::STATUS_REJECTED,
            VitrineListing::STATUS_CANCELLED,
        ])) {
            $this->session->setFlash('error', 'آگهی یافت نشد.');
            redirect(url('/vitrine'));
            exit;
        }

        $isSeller  = (int) $listing->seller_id === $userId;
        $isBuyer   = (int) ($listing->buyer_id ?? 0) === $userId;
        $isWatched = $this->listing->isWatched($userId, $id);
        $watchCount= $this->listing->watchCount($id);
        $requests  = $isSeller ? $this->requestModel->getAllByListing($id) : [];
        $myRequest = !$isSeller ? $this->requestModel->getByRequester($userId, 1, 0) : [];

        view('user.vitrine.show', [
            'title'      => $listing->title . ' — ویترین',
            'listing'    => $listing,
            'isSeller'   => $isSeller,
            'isBuyer'    => $isBuyer,
            'isWatched'  => $isWatched,
            'watchCount' => $watchCount,
            'requests'   => $requests,
            'statuses'   => $this->listing->statuses(),
            'categories' => $this->listing->categories(),
            'platforms'  => $this->listing->platforms(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // آگهی‌های من
    // ─────────────────────────────────────────────────────────────────────────

    public function myListings(): void
    {
        $userId   = (int) user_id();
        $listings = $this->listing->getBySeller($userId);

        view('user.vitrine.my-listings', [
            'title'      => 'آگهی‌های من — ویترین',
            'listings'   => $listings,
            'statuses'   => $this->listing->statuses(),
            'categories' => $this->listing->categories(),
        ]);
    }

    public function myPurchases(): void
    {
        $userId   = (int) user_id();
        $listings = $this->listing->getByBuyer($userId);

        view('user.vitrine.my-purchases', [
            'title'      => 'خریدهای من — ویترین',
            'listings'   => $listings,
            'statuses'   => $this->listing->statuses(),
            'categories' => $this->listing->categories(),
        ]);
    }

    public function myRequests(): void
    {
        $userId   = (int) user_id();
        $requests = $this->requestModel->getByRequester($userId);

        view('user.vitrine.my-requests', [
            'title'    => 'درخواست‌های من — ویترین',
            'requests' => $requests,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // اقدامات AJAX
    // ─────────────────────────────────────────────────────────────────────────

    /** ثبت درخواست خرید با قیمت پیشنهادی */
    public function sendRequest(): void
    {
        $userId    = (int) user_id();
        $listingId = (int) $this->request->param('id');
        $data      = $this->request->body();

        $result = $this->service->sendRequest($userId, $listingId, [
            'offer_price' => !empty($data['offer_price']) ? (float) $data['offer_price'] : null,
            'message'     => trim($data['message'] ?? ''),
        ]);

        $this->response->json($result);
    }

    /** پذیرش درخواست توسط فروشنده */
    public function acceptRequest(): void
    {
        $userId    = (int) user_id();
        $requestId = (int) $this->request->param('rid');
        $result    = $this->service->acceptRequest($userId, $requestId);
        $this->response->json($result);
    }

    /** رد درخواست توسط فروشنده */
    public function rejectRequest(): void
    {
        $userId    = (int) user_id();
        $requestId = (int) $this->request->param('rid');
        $result    = $this->service->rejectRequest($userId, $requestId);
        $this->response->json($result);
    }

    /** قفل اسکرو — خریدار پرداخت می‌کند */
    public function buy(): void
    {
        $userId    = (int) user_id();
        $listingId = (int) $this->request->param('id');
        $result    = $this->service->lockEscrow($userId, $listingId);
        $this->response->json($result);
    }

    /** تایید دریافت توسط خریدار */
    public function confirmDelivery(): void
    {
        $userId    = (int) user_id();
        $listingId = (int) $this->request->param('id');
        $result    = $this->service->confirmDelivery($userId, $listingId);
        $this->response->json($result);
    }

    /** ثبت اختلاف */
    public function dispute(): void
    {
        $userId    = (int) user_id();
        $listingId = (int) $this->request->param('id');
        $reason    = trim($this->request->post('reason') ?? '');

        if (mb_strlen($reason) < 10) {
            $this->response->json(['success' => false, 'message' => 'لطفاً دلیل اختلاف را با جزئیات بنویسید (حداقل ۱۰ کاراکتر).']);
            return;
        }

        $result = $this->service->openDispute($userId, $listingId, $reason);
        $this->response->json($result);
    }

    /** علاقه‌مندی / نشانه‌گذاری */
    public function watch(): void
    {
        $userId    = (int) user_id();
        $listingId = (int) $this->request->param('id');
        $listing   = $this->listing->find($listingId);

        if (!$listing) {
            $this->response->json(['success' => false, 'message' => 'آگهی یافت نشد.']);
            return;
        }

        $alreadyWatched = $this->listing->isWatched($userId, $listingId);
        if ($alreadyWatched) {
            $this->listing->removeWatch($userId, $listingId);
            $this->response->json(['success' => true, 'watched' => false, 'message' => 'از لیست علاقه‌مندی‌ها حذف شد.']);
        } else {
            $this->listing->addWatch($userId, $listingId);
            $this->response->json(['success' => true, 'watched' => true, 'message' => 'به لیست علاقه‌مندی‌ها اضافه شد.']);
        }
    }
}
