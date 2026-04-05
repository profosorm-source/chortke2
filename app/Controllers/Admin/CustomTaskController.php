<?php

namespace App\Controllers\Admin;

use App\Models\CustomTask;
use App\Models\CustomTaskSubmission;
use App\Models\TaskDispute;
use App\Services\CustomTaskService;
use App\Middleware\PermissionMiddleware;
use App\Controllers\Admin\BaseAdminController;

class CustomTaskController extends BaseAdminController
{
    private \App\Services\WalletService $walletService;
    private \App\Services\CustomTaskService $customTaskService;
    private \App\Models\TaskDispute $taskDisputeModel;
    private \App\Models\CustomTask $customTaskModel;
    public function __construct(
        \App\Models\CustomTask $customTaskModel,
        \App\Models\TaskDispute $taskDisputeModel,
        \App\Services\CustomTaskService $customTaskService,
        \App\Services\WalletService $walletService)
    {
        parent::__construct();
        $this->customTaskModel = $customTaskModel;
        $this->taskDisputeModel = $taskDisputeModel;
        $this->customTaskService = $customTaskService;
        $this->walletService = $walletService;
    }

    /** لیست وظایف */
    public function index()
    {
        PermissionMiddleware::require('tasks.view');
                $taskModel = $this->customTaskModel;
        $filters = [
            'status' => $this->request->get('status'),
            'task_type' => $this->request->get('task_type'),
            'search' => $this->request->get('search'),
        ];
        $page = \max(1, (int) $this->request->get('page', 1));
        $limit = 30; $offset = ($page - 1) * $limit;
        $tasks = $taskModel->adminList($filters, $limit, $offset);
        $total = $taskModel->adminCount($filters);

        return view('admin.custom-tasks.index', [
            'tasks' => $tasks, 'total' => $total,
            'page' => $page, 'pages' => \ceil($total / $limit),
            'filters' => $filters,
        ]);
    }

    /** تأیید/رد وظیفه (Ajax) */
    public function approve(): void
    {
        PermissionMiddleware::require('tasks.approve');

        $body     = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $taskId   = (int) ($body['task_id'] ?? 0);
        $decision = $body['decision'] ?? '';
        $reason   = $body['reason'] ?? null;

        $taskModel = $this->customTaskModel;
        $task      = $taskModel->find($taskId);
        if (!$task) {
            $this->response->json(['success' => false, 'message' => 'یافت نشد.'], 404);
            return;
        }

        if ($decision === 'approve') {
            $taskModel->update($taskId, [
                'status'      => 'active',
                'approved_by' => $this->userId(),
                'approved_at' => \date('Y-m-d H:i:s'),
            ]);
            log_activity('custom_task.approve', 'تأیید وظیفه', ['task_id' => $taskId]);
            $this->response->json(['success' => true, 'message' => 'وظیفه فعال شد.']);
        } elseif ($decision === 'reject') {
            $taskModel->update($taskId, [
                'status'           => 'rejected',
                'rejection_reason' => $reason ?? 'عدم رعایت قوانین',
            ]);
            $totalReturn = (float) $task->total_budget + (float) $task->site_fee_amount;
            if ($totalReturn > 0) {
                $walletService = $this->walletService;
                $walletService->deposit(
                    (int) $task->creator_id, $totalReturn, $task->currency,
                    'refund', "بازگشت بودجه وظیفه #{$taskId}",
                    "ctask_refund_{$taskId}"
                );
            }
            log_activity('custom_task.reject', 'رد وظیفه', ['task_id' => $taskId, 'reason' => $reason]);
            $this->response->json(['success' => true, 'message' => 'وظیفه رد شد و بودجه بازگردانده شد.']);
        } else {
            $this->response->json(['success' => false, 'message' => 'تصمیم نامعتبر.'], 422);
        }
    }

    /** لیست اختلاف‌ها */
    public function disputes()
    {
        PermissionMiddleware::require('tasks.manage');
        $disputeModel = $this->taskDisputeModel;
        $filters      = ['status' => $this->request->get('status')];
        $page         = \max(1, (int) $this->request->get('page', 1));
        $limit        = 30;
        $offset       = ($page - 1) * $limit;
        $disputes     = $disputeModel->adminList($filters, $limit, $offset);
        $total        = $disputeModel->adminCount($filters);

        return view('admin.custom-tasks.disputes', [
            'disputes' => $disputes,
            'total'    => $total,
            'page'     => $page,
            'pages'    => \ceil($total / $limit),
            'filters'  => $filters,
        ]);
    }

    /** حل اختلاف (Ajax) */
    public function resolveDispute(): void
    {
        PermissionMiddleware::require('tasks.manage');

        $body      = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $disputeId = (int) ($body['dispute_id'] ?? 0);
        $decision  = $body['decision'] ?? '';
        $note      = $body['note'] ?? '';
        $penalty   = (float) ($body['penalty_amount'] ?? 0);

        $service = $this->customTaskService;
        $result  = $service->resolveDispute($disputeId, $this->userId(), $decision, $note, $penalty);

        log_activity('custom_task.dispute_resolved', 'حل اختلاف', [
            'dispute_id' => $disputeId,
            'decision'   => $decision,
        ]);

        $this->response->json($result, $result['success'] ? 200 : 422);
    }
}