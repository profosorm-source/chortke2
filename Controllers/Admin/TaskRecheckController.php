<?php

namespace App\Controllers\Admin;

use App\Services\TaskRecheckService;

class TaskRecheckController extends BaseAdminController
{
    private \App\Services\TaskRecheckService $taskRecheckService;
    private TaskRecheckService $service;

    public function __construct(
        
        \App\Services\TaskRecheckService $taskRecheckService
    )
    {
parent::__construct();
        $this->service = $this->taskRecheckService;
        $this->taskRecheckService = $taskRecheckService;
    }

    public function index()
    {
        $page    = (int)($_GET['page'] ?? 1);
        $limit   = 30;
        $offset  = ($page - 1) * $limit;
        $filters = [];
        if (!empty($_GET['status'])) $filters['status'] = $_GET['status'];

        $rechecks = $this->service->getAll($filters, $limit, $offset);

        return view('admin.task-rechecks.index', [
            'rechecks' => $rechecks,
            'filters'  => $filters,
            'page'     => $page,
        ]);
    }

    public function pass(): void
    {
        $id     = (int)$this->request->param('id');
        $result = $this->service->pass($id);
        log_activity('recheck_pass', 'تایید بررسی مجدد #' . $id, $id, 'task_recheck');
        $this->response->json($result);
    }

    public function fail(): void
    {
        $id     = (int)$this->request->param('id');
        $result = $this->service->fail($id);
        log_activity('recheck_fail', 'شکست بررسی مجدد #' . $id, $id, 'task_recheck');
        $this->response->json($result);
    }
}
