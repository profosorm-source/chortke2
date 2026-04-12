<?php

namespace App\Controllers\Admin;

use App\Models\CustomTask;
use App\Models\CustomTaskSubmission;
use App\Services\CustomTaskService;
use App\Services\WalletService;
use App\Middleware\PermissionMiddleware;
use App\Controllers\Admin\BaseAdminController;

class CustomTaskController extends BaseAdminController
{
    private CustomTaskService $customTaskService;
    private WalletService $walletService;
    private CustomTask $customTaskModel;
    private CustomTaskSubmission $submissionModel;

    public function __construct(
        CustomTaskService $customTaskService,
        WalletService $walletService,
        CustomTask $customTaskModel,
        CustomTaskSubmission $submissionModel
    ) {
        parent::__construct();
        $this->customTaskService = $customTaskService;
        $this->walletService = $walletService;
        $this->customTaskModel = $customTaskModel;
        $this->submissionModel = $submissionModel;
    }

    /**
     * لیست وظایف
     */
    public function index()
    {
        PermissionMiddleware::require('tasks.view');

        $filters = [
            'status' => $this->request->get('status'),
            'task_type' => $this->request->get('task_type'),
            'search' => $this->request->get('search'),
        ];

        $page = \max(1, (int) $this->request->get('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $tasks = $this->customTaskModel->adminList($filters, $limit, $offset);
        $total = $this->customTaskModel->adminCount($filters);

        return view('admin.custom-tasks.index', [
            'tasks' => $tasks,
            'total' => $total,
            'page' => $page,
            'pages' => \ceil($total / $limit),
            'filters' => $filters,
            'statusLabels' => $this->customTaskModel->statusLabels(),
            'statusClasses' => $this->customTaskModel->statusClasses(),
            'taskTypes' => $this->customTaskModel->taskTypes(),
        ]);
    }

    /**
     * جزئیات وظیفه
     */
    public function show()
    {
        PermissionMiddleware::require('tasks.view');

        $taskId = (int) $this->request->param('id');
        $task = $this->customTaskModel->find($taskId);

        if (!$task) {
            \http_response_code(404);
            include __DIR__ . '/../../../views/errors/404.php';
            exit;
        }

        // لیست submission ها
        $submissions = $this->submissionModel->getByTask($taskId, null, 50, 0);

        return view('admin.custom-tasks.show', [
            'task' => $task,
            'submissions' => $submissions,
            'statusLabels' => $this->customTaskModel->statusLabels(),
            'submissionStatusLabels' => $this->submissionModel->statusLabels(),
        ]);
    }

    /**
     * تأیید/رد وظیفه (Ajax)
     */
    public function approve(): void
    {
        PermissionMiddleware::require('tasks.approve');

        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $taskId = (int) ($body['task_id'] ?? 0);
        $decision = $body['decision'] ?? '';
        $reason = $body['reason'] ?? null;

        $task = $this->customTaskModel->find($taskId);
        if (!$task) {
            $this->response->json(['success' => false, 'message' => 'یافت نشد.'], 404);
            return;
        }

        if ($decision === 'approve') {
            $this->customTaskModel->update($taskId, [
                'status' => 'active',
                'approved_by' => $this->userId(),
                'approved_at' => \date('Y-m-d H:i:s'),
            ]);

            log_activity('custom_task.approve', 'تأیید وظیفه', ['task_id' => $taskId]);
            $this->response->json(['success' => true, 'message' => 'وظیفه فعال شد.']);

        } elseif ($decision === 'reject') {
            $this->customTaskModel->update($taskId, [
                'status' => 'rejected',
                'rejection_reason' => $reason ?? 'عدم رعایت قوانین',
            ]);

            // بازگشت بودجه
            $totalReturn = (float) $task->total_budget + (float) $task->site_fee_amount;
            if ($totalReturn > 0) {
                $this->walletService->deposit(
                    (int) $task->creator_id,
                    $totalReturn,
                    $task->currency,
                    [
                        'type' => 'task_refund',
                        'description' => "بازگشت بودجه وظیفه #{$taskId}",
                        'idempotency_key' => "ctask_refund_{$taskId}",
                    ]
                );
            }

            log_activity('custom_task.reject', 'رد وظیفه', ['task_id' => $taskId, 'reason' => $reason]);
            $this->response->json(['success' => true, 'message' => 'وظیفه رد شد و بودجه بازگردانده شد.']);

        } else {
            $this->response->json(['success' => false, 'message' => 'تصمیم نامعتبر.'], 422);
        }
    }

    /**
     * تایید اجباری submission توسط ادمین
     */
    public function forceApproveSubmission(): void
    {
        PermissionMiddleware::require('tasks.manage');

        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $submissionId = (int) ($body['submission_id'] ?? 0);

        $submission = $this->submissionModel->find($submissionId);
        if (!$submission) {
            $this->response->json(['success' => false, 'message' => 'یافت نشد.'], 404);
            return;
        }

        // استفاده از همان متد approve موجود
        $result = $this->customTaskService->reviewSubmission(
            $submissionId,
            $submission->creator_id, // به‌جای reviewer از creator استفاده می‌کنیم
            'approve',
            'تایید توسط ادمین'
        );

        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * رد اجباری submission توسط ادمین
     */
    public function forceRejectSubmission(): void
    {
        PermissionMiddleware::require('tasks.manage');

        $body = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $submissionId = (int) ($body['submission_id'] ?? 0);
        $reason = $body['reason'] ?? 'رد توسط ادمین';

        $submission = $this->submissionModel->find($submissionId);
        if (!$submission) {
            $this->response->json(['success' => false, 'message' => 'یافت نشد.'], 404);
            return;
        }

        $result = $this->customTaskService->reviewSubmission(
            $submissionId,
            $submission->creator_id,
            'reject',
            $reason
        );

        $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * آمار و گزارش
     */
    public function stats(): void
    {
        PermissionMiddleware::require('tasks.view');

        // آمار کلی
        $stats = [
            'total_tasks' => $this->customTaskModel->adminCount([]),
            'active_tasks' => $this->customTaskModel->adminCount(['status' => 'active']),
            'pending_tasks' => $this->customTaskModel->adminCount(['status' => 'pending_review']),
            'completed_tasks' => $this->customTaskModel->adminCount(['status' => 'completed']),
        ];

        // آمار submission ها
        $stmt = $this->db->getConnection()->prepare("
            SELECT 
                status,
                COUNT(*) as count,
                SUM(reward_amount) as total_reward
            FROM custom_task_submissions
            GROUP BY status
        ");
        $stmt->execute();
        $submissionStats = $stmt->fetchAll(\PDO::FETCH_OBJ);

        $this->response->json([
            'success' => true,
            'stats' => $stats,
            'submissions' => $submissionStats,
        ]);
    }
}
