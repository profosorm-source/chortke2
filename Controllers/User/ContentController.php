<?php
// app/Controllers/User/ContentController.php

namespace App\Controllers\User;

use App\Models\ContentSubmission;
use App\Models\ContentRevenue;
use App\Services\ContentService;
use Core\Validator;
use App\Controllers\User\BaseUserController;

class ContentController extends BaseUserController
{
    private \App\Services\ContentService $contentService;
    private \App\Models\ContentSubmission $contentSubmissionModel;
    private \App\Models\ContentRevenue $contentRevenueModel;
    public function __construct(
        \App\Models\ContentRevenue $contentRevenueModel,
        \App\Models\ContentSubmission $contentSubmissionModel,
        \App\Services\ContentService $contentService)
    {
        parent::__construct();
        $this->contentRevenueModel = $contentRevenueModel;
        $this->contentSubmissionModel = $contentSubmissionModel;
        $this->contentService = $contentService;
    }

    /**
     * صفحه لیست محتواهای کاربر
     */
    public function index()
    {
        $userId = user_id();
        $model = $this->contentSubmissionModel;

        $status = $this->request->get('status');
        $page = \max(1, (int)($this->request->get('page') ?? 1));
        $perPage = 10;
        $offset = ($page - 1) * $perPage;

        $submissions = $model->getByUser($userId, $status, $perPage, $offset);
        $total = $model->countByUser($userId, $status);
        $totalPages = \ceil($total / $perPage);

        // آمار کلی
        $stats = [
            'total' => $model->countByUser($userId),
            'pending' => $model->countByUser($userId, ContentSubmission::STATUS_PENDING),
            'approved' => $model->countByUser($userId, ContentSubmission::STATUS_APPROVED),
            'published' => $model->countByUser($userId, ContentSubmission::STATUS_PUBLISHED),
            'rejected' => $model->countByUser($userId, ContentSubmission::STATUS_REJECTED),
        ];

        // مجموع درآمد
        $revenueModel = $this->contentRevenueModel;
        $totalRevenue = $revenueModel->getTotalUserRevenue($userId, ContentRevenue::STATUS_PAID);
        $pendingRevenue = $revenueModel->getTotalUserRevenue($userId, ContentRevenue::STATUS_PENDING);

        $user = $this->userModel->find($this->userId());

        return view('user.content.index', [
            'user' => $user,
            'submissions' => $submissions,
            'stats' => $stats,
            'totalRevenue' => $totalRevenue,
            'pendingRevenue' => $pendingRevenue,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'currentStatus' => $status,
        ]);
    }

    /**
     * صفحه ارسال محتوای جدید
     */
    public function create()
    {
        $service = $this->contentService;
        $user = $this->userModel->find($this->userId());

        return view('user.content.create', [
            'user' => $user,
            'agreementText' => $service->getAgreementText(),
            'settings' => $service->getSettings(),
        ]);
    }

    /**
     * ثبت محتوای جدید (POST)
     */
    public function store()
    {
                
        // خواندن داده‌ها
        $input = \json_decode(\file_get_contents('php://input'), true);
        if (!$input) {
            $input = $this->request->body();
        }

        // اعتبارسنجی
        $validator = new Validator($input, [
            'platform' => 'required|in:aparat,youtube',
            'video_url' => 'required|url|max:500',
            'title' => 'required|min:5|max:255',
            'description' => 'max:2000',
            'category' => 'max:100',
            'agreement_accepted' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false,
                'message' => 'اطلاعات ورودی نامعتبر است.',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->data();
        $service = $this->contentService;
        $result = $service->submitContent(user_id(), $data);

        $statusCode = $result['success'] ? 200 : 422;
        return $this->response->json($result, $statusCode);
    }

    /**
     * مشاهده جزئیات یک محتوا
     */
    public function show()
    {
                $id = (int)$this->request->param('id');
        $userId = user_id();

        $model = $this->contentSubmissionModel;
        $submission = $model->find($id);

        if (!$submission || $submission->user_id !== $userId) {
            return view('errors.404');
        }

        // درآمدهای این محتوا
        $revenueModel = $this->contentRevenueModel;
        $revenues = $revenueModel->getBySubmission($id);

        $user = $this->userModel->find($this->userId());

        return view('user.content.show', [
            'user' => $user,
            'submission' => $submission,
            'revenues' => $revenues,
        ]);
    }

    /**
     * لیست درآمدها
     */
    public function revenues()
    {
        $userId = user_id();
        $revenueModel = $this->contentRevenueModel;

        $page = \max(1, (int)($this->request->get('page') ?? 1));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $revenues = $revenueModel->getByUser($userId, $perPage, $offset);
        $total = $revenueModel->countByUser($userId);
        $totalPages = \ceil($total / $perPage);

        $totalPaid = $revenueModel->getTotalUserRevenue($userId, ContentRevenue::STATUS_PAID);
        $totalPending = $revenueModel->getTotalUserRevenue($userId, ContentRevenue::STATUS_PENDING);

        $user = $this->userModel->find($this->userId());

        return view('user.content.revenues', [
            'user' => $user,
            'revenues' => $revenues,
            'totalPaid' => $totalPaid,
            'totalPending' => $totalPending,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
        ]);
    }
}