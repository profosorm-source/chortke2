<?php

namespace App\Controllers\Admin;

use Core\Database;
use App\Services\EmailService;
use App\Models\EmailQueue;

class EmailQueueController extends BaseAdminController
{
    private Database $db;
    private EmailQueue $model;
    private EmailService $emailService;

    public function __construct(
        Database     $db,
        EmailQueue   $model,
        EmailService $emailService
    ) {
        parent::__construct();
        $this->db           = $db;
        $this->model        = $model;
        $this->emailService = $emailService;
    }

    public function index(): void
    {
        $page    = max(1, (int)($this->request->get('page') ?? 1));
        $perPage = 30;
        $offset  = ($page - 1) * $perPage;
        $where   = 'WHERE 1=1';
        $params  = [];

        if ($status = $this->request->get('status')) {
            $where   .= ' AND status = ?';
            $params[] = $status;
        }
        if ($search = $this->request->get('search')) {
            $where   .= ' AND (to_email LIKE ? OR subject LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $emails = $this->db->fetchAll(
            "SELECT * FROM email_queue $where ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );
        $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM email_queue $where", $params);
        $stats = $this->model->getStats();

        view('admin/email-queue/index', [
            'title'      => 'صف ایمیل',
            'emails'     => $emails,
            'stats'      => $stats,
            'total'      => $total,
            'page'       => $page,
            'totalPages' => (int)ceil($total / $perPage),
        ]);
    }

    public function process(): void
    {
        $result = $this->emailService->processQueue(20);
        $this->response->json($result);
    }

    public function retryFailed(): void
    {
        $count = $this->db->execute(
            "UPDATE email_queue SET status = 'pending', attempts = 0, error_message = NULL WHERE status = 'failed'"
        );
        $this->response->json(['success' => true, 'count' => $count]);
    }

    public function retry(): void
    {
        $id  = (int)$this->request->param('id');
        $ok  = $this->db->execute(
            "UPDATE email_queue SET status = 'pending', attempts = 0 WHERE id = ? AND status IN ('failed','pending')",
            [$id]
        ) > 0;
        $this->response->json(['success' => $ok, 'message' => $ok ? 'آماده تلاش مجدد' : 'یافت نشد']);
    }
}