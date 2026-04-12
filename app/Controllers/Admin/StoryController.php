<?php

namespace App\Controllers\Admin;

use App\Models\InfluencerProfile;
use App\Models\StoryOrder;
use App\Services\StoryPromotionService;
use App\Middleware\PermissionMiddleware;
use App\Controllers\Admin\BaseAdminController;

class StoryController extends BaseAdminController
{
    private \App\Services\StoryPromotionService $storyPromotionService;
    private \App\Models\StoryOrder $storyOrderModel;
    private \App\Models\InfluencerProfile $influencerProfileModel;
    public function __construct(
        \App\Models\InfluencerProfile $influencerProfileModel,
        \App\Models\StoryOrder $storyOrderModel,
        \App\Services\StoryPromotionService $storyPromotionService)
    {
        parent::__construct();
        $this->influencerProfileModel = $influencerProfileModel;
        $this->storyOrderModel = $storyOrderModel;
        $this->storyPromotionService = $storyPromotionService;
    }

    /** لیست سفارش‌ها */
    public function index()
    {
        PermissionMiddleware::require('stories.view');
                $orderModel = $this->storyOrderModel;
        $filters = [
            'status' => $this->request->get('status'),
            'order_type' => $this->request->get('order_type'),
            'search' => $this->request->get('search'),
        ];
        $page = \max(1, (int) $this->request->get('page', 1));
        $limit = 30; $offset = ($page - 1) * $limit;
        $orders = $orderModel->adminList($filters, $limit, $offset);
        $total = $orderModel->adminCount($filters);
        $stats = $orderModel->globalStats();

        return view('admin.stories.index', [
            'orders' => $orders, 'total' => $total,
            'page' => $page, 'pages' => \ceil($total / $limit),
            'filters' => $filters, 'stats' => $stats,
        ]);
    }

    /** مدیریت اینفلوئنسرها */
    public function influencers()
    {
        PermissionMiddleware::require('stories.manage');
                $profileModel = $this->influencerProfileModel;
        $filters = [
            'status' => $this->request->get('status'),
            'search' => $this->request->get('search'),
        ];
        $page = \max(1, (int) $this->request->get('page', 1));
        $limit = 30; $offset = ($page - 1) * $limit;
        $profiles = $profileModel->adminList($filters, $limit, $offset);
        $total = $profileModel->adminCount($filters);

        return view('admin.stories.influencers', [
            'profiles' => $profiles, 'total' => $total,
            'page' => $page, 'pages' => \ceil($total / $limit),
            'filters' => $filters,
        ]);
    }

    /** تأیید/رد اینفلوئنسر (Ajax) */
    public function approveInfluencer()
    {
        PermissionMiddleware::require('stories.manage');
                        
        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $profileId = (int) ($body['profile_id'] ?? 0);
        $decision = $body['decision'] ?? '';
        $reason = $body['reason'] ?? null;

        $profileModel = $this->influencerProfileModel;
        $profile = $profileModel->find($profileId);
        if (!$profile) { $this->response->json(['success' => false, 'message' => 'یافت نشد.'], 404); return; }

        if ($decision === 'approve') {
            $profileModel->update($profileId, [
                'status' => 'verified',
                'verified_by' => $this->userId(),
                'verified_at' => \date('Y-m-d H:i:s'),
            ]);
            log_activity('story.influencer_approved', 'تأیید پیج اینفلوئنسر', ['profile_id' => $profileId]);
            $this->response->json(['success' => true, 'message' => 'پیج تأیید شد.']);
        } elseif ($decision === 'reject') {
            $profileModel->update($profileId, [
                'status' => 'rejected',
                'rejection_reason' => $reason ?? 'مطابق شرایط نیست',
            ]);
            log_activity('story.influencer_rejected', 'رد پیج اینفلوئنسر', ['profile_id' => $profileId, 'reason' => $reason]);
            $this->response->json(['success' => true, 'message' => 'پیج رد شد.']);
        } else {
            $this->response->json(['success' => false, 'message' => 'تصمیم نامعتبر.'], 422);
        }
    }

    /** تأیید/رد مدرک سفارش (Ajax) */
    public function verifyProof()
    {
        PermissionMiddleware::require('stories.manage');
                        
        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $orderId = (int) ($body['order_id'] ?? 0);
        $decision = $body['decision'] ?? '';
        $reason = $body['reason'] ?? null;

        $service = $this->storyPromotionService;
        $result = $service->verifyProof($orderId, $this->userId(), $decision, $reason);

        log_activity('story.proof_verified', 'بررسی مدرک سفارش', [
            'order_id' => $orderId, 'decision' => $decision,
        ]);

        $this->response->json($result, $result['success'] ? 200 : 422);
    }
}