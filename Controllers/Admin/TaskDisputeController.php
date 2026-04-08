<?php

namespace App\Controllers\Admin;

use App\Services\TaskDisputeService;
use App\Services\TaskExecutionService;

class TaskDisputeController extends BaseAdminController
{
    private \App\Services\WalletService $walletService;
    private \App\Services\TaskExecutionService $taskExecutionService;
    private \App\Services\TaskDisputeService $taskDisputeService;
    private TaskDisputeService $service;
    private TaskExecutionService $executionService;

    public function __construct(
        \App\Services\TaskDisputeService $taskDisputeService,
        \App\Services\TaskExecutionService $taskExecutionService,
        \App\Services\WalletService $walletService,
        \App\Services\SEOExecutionService $executionService)
    {
        parent::__construct();
        $this->service = $service;
        $this->executionService = $executionService;
        $this->taskDisputeService = $taskDisputeService;
        $this->taskExecutionService = $taskExecutionService;
        $this->walletService = $walletService;
    }

    public function index()
    {
        $page    = (int)($_GET['page'] ?? 1);
        $limit   = 30;
        $offset  = ($page - 1) * $limit;
        $filters = [];
        if (!empty($_GET['status'])) $filters['status'] = $_GET['status'];

        $total      = $this->service->countAll($filters);
        $disputes   = $this->service->getAll($filters, $limit, $offset);
        $totalPages = (int)ceil($total / $limit);

        return view('admin.task-disputes.index', [
            'disputes'   => $disputes,
            'filters'    => $filters,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
        ]);
    }

    public function show()
    {
        $id      = (int)$this->request->param('id');
        $dispute = $this->service->find($id);
        if (!$dispute) {
            $this->session->setFlash('error', 'اختلاف یافت نشد.');
            return redirect(url('/admin/task-disputes'));
        }

        $execution = $this->executionService->find($dispute->execution_id);
        $task      = $this->executionService->findAd($dispute->advertisement_id);

        return view('admin.task-disputes.show', [
            'dispute'   => $dispute,
            'execution' => $execution,
            'task'      => $task,
        ]);
    }

    public function resolveForExecutor(): void
    {
        $id            = (int)$this->request->param('id');
        $body          = $this->request->body();
        $decision      = $body['decision']      ?? '';
        $penaltyAmount = (float)($body['penalty_amount'] ?? 0);

        if (empty($decision)) {
            $this->response->json(['success' => false, 'message' => 'توضیح تصمیم الزامی است.']);
            return;
        }

        $result = $this->service->resolveForExecutor($id, user_id(), $decision, $penaltyAmount);
        log_activity('dispute_resolve', 'داوری اختلاف #' . $id . ' به نفع انجام‌دهنده', $id, 'task_dispute');
        $this->response->json($result);
    }

    public function resolveForAdvertiser(): void
    {
        $id            = (int)$this->request->param('id');
        $body          = $this->request->body();
        $decision      = $body['decision']      ?? '';
        $penaltyAmount = (float)($body['penalty_amount'] ?? 0);

        if (empty($decision)) {
            $this->response->json(['success' => false, 'message' => 'توضیح تصمیم الزامی است.']);
            return;
        }

        $result = $this->service->resolveForAdvertiser($id, user_id(), $decision, $penaltyAmount);
        log_activity('dispute_resolve', 'داوری اختلاف #' . $id . ' به نفع تبلیغ‌دهنده', $id, 'task_dispute');
        $this->response->json($result);
    }
}
