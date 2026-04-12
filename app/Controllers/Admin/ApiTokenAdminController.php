<?php

namespace App\Controllers\Admin;

use Core\Database;

class ApiTokenAdminController extends BaseAdminController
{
    private Database $db;

    public function __construct(Database $db)
    {
        parent::__construct();
        $this->db = $db;
    }

    public function index(): void
    {
        $page    = max(1, (int)($this->request->get('page') ?? 1));
        $perPage = 30;
        $offset  = ($page - 1) * $perPage;
        $where   = 'WHERE 1=1';
        $params  = [];

        if ($search = $this->request->get('search')) {
            $where   .= ' AND (at.name LIKE ? OR u.email LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $statusFilter = $this->request->get('status');
        if ($statusFilter === 'active') {
            $where .= ' AND at.revoked = 0 AND (at.expires_at IS NULL OR at.expires_at > NOW())';
        } elseif ($statusFilter === 'revoked') {
            $where .= ' AND at.revoked = 1';
        } elseif ($statusFilter === 'expired') {
            $where .= ' AND at.revoked = 0 AND at.expires_at < NOW()';
        }

        $tokens = $this->db->fetchAll(
            "SELECT at.*, u.full_name, u.email FROM api_tokens at
             LEFT JOIN users u ON u.id = at.user_id $where
             ORDER BY at.created_at DESC LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );
        $total = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM api_tokens at LEFT JOIN users u ON u.id = at.user_id $where",
            $params
        );
        $stats = [
            'active'     => (int)$this->db->fetchColumn("SELECT COUNT(*) FROM api_tokens WHERE revoked = 0 AND (expires_at IS NULL OR expires_at > NOW())"),
            'revoked'    => (int)$this->db->fetchColumn("SELECT COUNT(*) FROM api_tokens WHERE revoked = 1"),
            'expired'    => (int)$this->db->fetchColumn("SELECT COUNT(*) FROM api_tokens WHERE revoked = 0 AND expires_at < NOW()"),
            'used_today' => (int)$this->db->fetchColumn("SELECT COUNT(*) FROM api_tokens WHERE DATE(last_used_at) = CURDATE()"),
        ];

        view('admin/api-tokens/index', [
            'title'  => 'توکن‌های API',
            'tokens' => $tokens,
            'total'  => $total,
            'stats'  => $stats,
        ]);
    }

    public function revoke(): void
    {
        $id   = (int)$this->request->param('id');
        $stmt = $this->db->query(
            "UPDATE api_tokens SET revoked = 1, revoked_at = NOW() WHERE id = ?", [$id]
        );
        $ok = $stmt instanceof \PDOStatement && $stmt->rowCount() > 0;
        $this->response->json(['success' => $ok, 'message' => $ok ? 'باطل شد' : 'یافت نشد']);
    }

    public function revokeExpired(): void
    {
        $stmt  = $this->db->query(
            "UPDATE api_tokens SET revoked = 1, revoked_at = NOW() WHERE revoked = 0 AND expires_at < NOW()"
        );
        $count = $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
        $this->response->json(['success' => true, 'count' => $count]);
    }
}