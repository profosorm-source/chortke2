<?php

namespace App\Controllers\Admin;

use App\Services\AdTaskService;
use App\Services\TaskExecutionService;

class AdTaskController extends BaseAdminController
{
    private \App\Services\WalletService $walletService;
    private \App\Services\TaskExecutionService $taskExecutionService;
    private \App\Services\AdTaskService $adTaskService;
    private AdTaskService        $service;
    private TaskExecutionService $executionService;

    public function __construct(
        \App\Services\AdTaskService $adTaskService,
        \App\Services\TaskExecutionService $taskExecutionService,
        \App\Services\WalletService $walletService,
        \App\Services\TaskExecutionService $executionService)
    {
        parent::__construct();
        $this->service = $adTaskService;
        $this->executionService = $executionService;
        $this->adTaskService = $adTaskService;
        $this->taskExecutionService = $taskExecutionService;
        $this->walletService = $walletService;
    }

    public function index()
    {
        $page    = (int)($_GET['page'] ?? 1);
        $limit   = 30;
        $offset  = ($page - 1) * $limit;
        $filters = [];
        if (!empty($_GET['status']))    $filters['status']    = $_GET['status'];
        if (!empty($_GET['platform']))  $filters['platform']  = $_GET['platform'];
        if (!empty($_GET['task_type'])) $filters['task_type'] = $_GET['task_type'];
        if (!empty($_GET['search']))    $filters['search']    = $_GET['search'];

        $total      = $this->service->countAll($filters);
        $tasks      = $this->service->getAll($filters, $limit, $offset);
        $stats      = $this->service->getStats();
        $totalPages = (int)ceil($total / $limit);

        return view('admin.ad-tasks.index', [
            'tasks'      => $tasks,
            'filters'    => $filters,
            'stats'      => $stats,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
        ]);
    }

    public function show()
    {
        $id   = (int)$this->request->param('id');
        $task = $this->service->find($id);
        if (!$task) {
            $this->session->setFlash('error', 'تسک یافت نشد.');
            return redirect(url('/admin/ad-tasks'));
        }

        $executions = $this->executionService->getAll(['advertisement_id' => $id], 50, 0);

        return view('admin.ad-tasks.show', [
            'task'       => $task,
            'executions' => $executions,
        ]);
    }

    public function approve(): void
    {
        $id     = (int)$this->request->param('id');
        $result = $this->service->approve($id, user_id());
        log_activity('ad_task_approve', 'تایید تسک #' . $id, $id, 'ad_task');
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

        $result = $this->service->reject($id, user_id(), $reason);
        log_activity('ad_task_reject', 'رد تسک #' . $id . ': ' . $reason, $id, 'ad_task');
        $this->response->json($result);
    }
}