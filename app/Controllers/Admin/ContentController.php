<?php
// app/Controllers/Admin/ContentController.php

namespace App\Controllers\Admin;

use App\Models\ContentSubmission;
use App\Models\ContentRevenue;
use App\Models\ContentAgreement;
use App\Services\ContentService;
use Core\Validator;
use App\Controllers\Admin\BaseAdminController;

class ContentController extends BaseAdminController
{
    private \App\Models\ContentSubmission $contentSubmissionModel;
    private \App\Models\ContentRevenue $contentRevenueModel;
    private \App\Models\ContentAgreement $contentAgreementModel;
    private ContentService $contentService;

    public function __construct(
        \App\Models\ContentAgreement $contentAgreementModel,
        \App\Models\ContentRevenue $contentRevenueModel,
        \App\Models\ContentSubmission $contentSubmissionModel,
        \App\Services\ContentService $contentService)
    {
        parent::__construct();
        $this->contentService = $contentService;
        $this->contentAgreementModel = $contentAgreementModel;
        $this->contentRevenueModel = $contentRevenueModel;
        $this->contentSubmissionModel = $contentSubmissionModel;
    }

    /**
     * لیست تمام محتواها
     */
    public function index()
    {
        $model = $this->contentSubmissionModel;

        $filters = [
            'status' => $_GET['status'] ?? null,
            'platform' => $_GET['platform'] ?? null,
            'search' => $_GET['search'] ?? null,
        ];

        $page = \max(1, (int)($_GET['page'] ?? 1));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $submissions = $model->getAll($filters, $perPage, $offset);
        $total = $model->countAll($filters);
        $totalPages = \ceil($total / $perPage);
        $stats = $model->getStats();

        $user = auth()->user();

        return view('admin.content.index', [
            'user' => $user,
            'submissions' => $submissions,
            'stats' => $stats,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'filters' => $filters,
        ]);
    }

    /**
     * مشاهده جزئیات محتوا
     */
    public function show()
    {
                $id = (int)$this->request->param('id');

        $model = $this->contentSubmissionModel;
        $submission = $model->findWithUser($id);

        if (!$submission) {
            return view('errors.404');
        }

        // درآمدها
        $revenueModel = $this->contentRevenueModel;
        $revenues = $revenueModel->getBySubmission($id);

        // تعهدنامه
        $agreementModel = $this->contentAgreementModel;
        $agreement = $agreementModel->findBySubmission($id);

        $user = auth()->user();

        return view('admin.content.show', [
            'user' => $user,
            'submission' => $submission,
            'revenues' => $revenues,
            'agreement' => $agreement,
        ]);
    }

    /**
     * تأیید محتوا (AJAX)
     */
    public function approve()
    {
                        $id = (int)$this->request->param('id');

        $result = $this->contentService->approveSubmission($id, user_id());

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * رد محتوا (AJAX)
     */
    public function reject()
    {
                        $id = (int)$this->request->param('id');

        $input = \json_decode(\file_get_contents('php://input'), true) ?? $_POST;

        $validator = new Validator($input, [
            'reason' => 'required|min:10|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false,
                'message' => 'لطفاً دلیل رد را وارد کنید (حداقل ۱۰ کاراکتر).',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->data();
        $result = $this->contentService->rejectSubmission($id, user_id(), $data->reason);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * ثبت انتشار (AJAX)
     */
    public function publish()
    {
                        $id = (int)$this->request->param('id');

        $input = \json_decode(\file_get_contents('php://input'), true) ?? $_POST;

        $validator = new Validator($input, [
            'published_url' => 'url|max:500',
            'channel_name' => 'max:255',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->data();
        $result = $this->contentService->markAsPublished($id, user_id(), [
            'published_url' => $data->published_url ?? null,
            'channel_name' => $data->channel_name ?? null,
        ]);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * تعلیق محتوا (AJAX)
     */
    public function suspend()
    {
                        $id = (int)$this->request->param('id');

        $input = \json_decode(\file_get_contents('php://input'), true) ?? $_POST;

        $validator = new Validator($input, [
            'reason' => 'required|min:10|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false,
                'message' => 'لطفاً دلیل تعلیق را وارد کنید.',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->data();
        $result = $this->contentService->suspendSubmission($id, user_id(), $data->reason);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * صفحه ثبت درآمد
     */
    public function revenueCreate()
    {
                $id = (int)$this->request->param('id');

        $model = $this->contentSubmissionModel;
        $submission = $model->findWithUser($id);

        if (!$submission) {
            return view('errors.404');
        }

        $user = auth()->user();

        return view('admin.content.revenue-create', [
            'user' => $user,
            'submission' => $submission,
            'settings' => $this->contentService->getSettings(),
        ]);
    }

    /**
     * ثبت درآمد (POST)
     */
    public function revenueStore()
    {
                        $id = (int)$this->request->param('id');

        $input = \json_decode(\file_get_contents('php://input'), true) ?? $_POST;

        $validator = new Validator($input, [
            'period' => 'required|max:7',
            'views' => 'required|numeric|min:0',
            'total_revenue' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false,
                'message' => 'اطلاعات ورودی نامعتبر است.',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->data();
        $result = $this->contentService->addRevenue($id, user_id(), [
            'period' => $data->period,
            'views' => $data->views,
            'total_revenue' => $data->total_revenue,
        ]);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * تأیید درآمد برای پرداخت (AJAX)
     */
    public function revenueApprove()
    {
                        $revenueId = (int)$this->request->param('revenue_id');

        $model = $this->contentRevenueModel;
        $revenue = $model->find($revenueId);

        if (!$revenue) {
            return $this->response->json(['success' => false, 'message' => 'رکورد یافت نشد.'], 404);
        }

        if ($revenue->status !== ContentRevenue::STATUS_PENDING) {
            return $this->response->json(['success' => false, 'message' => 'فقط درآمدهای در انتظار قابل تأیید هستند.'], 422);
        }

        $model->update($revenueId, ['status' => ContentRevenue::STATUS_APPROVED]);

        logger('content_revenue_approved', "Admin " . user_id() . " approved revenue #{$revenueId}", 'info');

        return $this->response->json(['success' => true, 'message' => 'درآمد تأیید شد و آماده پرداخت است.']);
    }

    /**
     * پرداخت درآمد (AJAX)
     */
    public function revenuePay()
    {
                        $revenueId = (int)$this->request->param('revenue_id');

        $result = $this->contentService->payRevenue($revenueId, user_id());

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * لیست تمام درآمدها (ادمین)
     */
    public function revenues()
    {
        $revenueModel = $this->contentRevenueModel;

        $filters = [
            'status' => $_GET['status'] ?? null,
            'user_id' => $_GET['user_id'] ?? null,
            'period' => $_GET['period'] ?? null,
        ];

        $page = \max(1, (int)($_GET['page'] ?? 1));
        $perPage = 15;
        $offset = ($page - 1) * $perPage;

        $revenues = $revenueModel->getAll($filters, $perPage, $offset);
        $total = $revenueModel->countAll($filters);
        $totalPages = \ceil($total / $perPage);
        $financialStats = $revenueModel->getFinancialStats();

        $user = auth()->user();

        return view('admin.content.revenues', [
            'user' => $user,
            'revenues' => $revenues,
            'financialStats' => $financialStats,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'filters' => $filters,
        ]);
    }
}