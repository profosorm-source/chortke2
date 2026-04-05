<?php

namespace App\Controllers\Admin;

use App\Services\TaskExecutionService;
use App\Services\TaskDisputeService;

class TaskExecutionController extends BaseAdminController
{
    private \App\Services\WalletService $walletService;
    private \App\Services\TaskDisputeService $taskDisputeService;
    private \App\Services\TaskExecutionService $taskExecutionService;
    private TaskExecutionService $service;
    private TaskDisputeService   $disputeService;

    public function __construct(
        
        \App\Services\TaskExecutionService $taskExecutionService,
        \App\Services\TaskDisputeService $taskDisputeService,
        \App\Services\WalletService $walletService)
    {
parent::__construct();
        $this->service        = $this->taskExecutionService;
        $this->disputeService = $this->taskDisputeService;
        $this->taskExecutionService = $taskExecutionService;
        $this->taskDisputeService = $taskDisputeService;
        $this->walletService = $walletService;
    }

    public function index()
    {
        $page    = (int)($_GET['page'] ?? 1);
        $limit   = 30;
        $offset  = ($page - 1) * $limit;
        $filters = [];
        if (!empty($_GET['status']))  $filters['status']           = $_GET['status'];
        if (!empty($_GET['search']))  $filters['search']           = $_GET['search'];
        if (!empty($_GET['ad_id']))   $filters['advertisement_id'] = (int)$_GET['ad_id'];

        $total      = $this->service->countAll($filters);
        $executions = $this->service->getAll($filters, $limit, $offset);
        $totalPages = (int)ceil($total / $limit);

        return view('admin.task-executions.index', [
            'executions' => $executions,
            'filters'    => $filters,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
        ]);
    }

    public function show()
    {
        $id        = (int)$this->request->param('id');
        $execution = $this->service->find($id);
        if (!$execution) {
            $this->session->setFlash('error', 'رکورد یافت نشد.');
            return redirect(url('/admin/task-executions'));
        }

        $task    = $this->service->findAd($execution->advertisement_id);
        $dispute = $this->disputeService->find($id); // findByExecution در service

        return view('admin.task-executions.show', [
            'execution' => $execution,
            'task'      => $task,
            'dispute'   => $dispute,
        ]);
    }

    public function approve(): void
    {
        $id     = (int)$this->request->param('id');
        $result = $this->service->approveByAdmin($id, user_id());
        log_activity('task_exec_approve', 'تایید اجرای تسک #' . $id, $id, 'task_execution');
        $this->response->json($result);
    }

    public function reject(): void
    {
        $id     = (int)$this->request->param('id');
        $body   = $this->request->body();
        $reason = $body['reason'] ?? '';

        if (empty($reason)) {
            $this->response->json(['success' => false, 'message' => 'دلیل رد الزامی است.']);
            return;
        }

        $result = $this->service->rejectByAdmin($id, user_id(), $reason);
        log_activity('task_exec_reject', 'رد اجرای تسک #' . $id . ': ' . $reason, $id, 'task_execution');
        $this->response->json($result);
    }
}
