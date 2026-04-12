<?php

namespace App\Models;

use Core\Model;
use Core\Database;

/**
 * ActivityLog Model
 */
class ActivityLog extends Model
{
    protected static string $table = 'activity_logs';

    /**
     * ایجاد لاگ فعالیت
     */
    public function create(array $data): bool
    {
        try {
            $userId = $data['user_id'] ?? $this->getUserId();

            $stmt = $this->db->query(
                "INSERT INTO activity_logs
                (user_id, action, description, model, model_id, ip_address, user_agent, metadata, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $userId,
                    $data['action'],
                    $data['description'] ?? null,
                    $data['model'] ?? null,
                    $data['model_id'] ?? null,
                    $this->getIpAddress(),
                    mb_substr($this->getUserAgent(), 0, 500),
                    isset($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : null,
                ]
            );

            return (bool)$stmt;
        } catch (\Throwable $e) {
            error_log('ActivityLog::create failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * لاگ‌های اخیر
     */
    public function getRecent(int $limit = 50, ?int $userId = null, ?string $action = null): array
    {
        $limit = max(1, min(500, $limit));
        
        $sql = "SELECT al.*, u.full_name, u.email
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.deleted_at IS NULL";
        $params = [];

        if ($userId) {
            $sql .= " AND al.user_id = ?";
            $params[] = (int)$userId;
        }
        if ($action) {
            $sql .= " AND al.action = ?";
            $params[] = $action;
        }

        $sql .= " ORDER BY al.created_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->query($sql, $params);
        
        if (!$stmt instanceof \PDOStatement) {
            return [];
        }

        return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
    }

    /**
     * صفحه‌بندی
     */
    public function getPaginated(
        int $page = 1,
        int $perPage = 20,
        ?int $userId = null,
        ?string $action = null,
        ?string $searchTerm = null
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = "WHERE al.deleted_at IS NULL";
        $params = [];

        if ($userId) {
            $where .= " AND al.user_id = ?";
            $params[] = (int)$userId;
        }
        if ($action) {
            $where .= " AND al.action = ?";
            $params[] = $action;
        }
        if ($searchTerm) {
            $like = '%' . $searchTerm . '%';
            $where .= " AND (al.description LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $base = "FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id $where";

        // Count
        $countStmt = $this->db->query("SELECT COUNT(*) $base", $params);
        $total = $countStmt instanceof \PDOStatement ? (int)$countStmt->fetchColumn() : 0;

        // Data
        $dataStmt = $this->db->query(
            "SELECT al.*, u.full_name, u.email $base ORDER BY al.created_at DESC LIMIT ? OFFSET ?",
            [...$params, $perPage, $offset]
        );

        $logs = $dataStmt instanceof \PDOStatement ? $dataStmt->fetchAll(\PDO::FETCH_OBJ) : [];

        return [
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int)ceil($total / $perPage),
        ];
    }

    /**
     * Soft delete
     */
    public function softDeleteOlderThan(int $days = 90): int
    {
        $days = max(1, $days);
        $stmt = $this->db->query(
            "UPDATE activity_logs SET deleted_at = NOW()
             WHERE deleted_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        
        return $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
    }

    /**
     * حذف فیزیکی
     */
    public function deleteOlderThan(int $days = 90): int
    {
        $days = max(1, $days);
        $stmt = $this->db->query(
            "DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        
        return $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
    }

    /**
     * Helper Methods
     */
    private function getUserId(): ?int
    {
        try {
            if (function_exists('user_id')) {
                return user_id();
            }
            $session = \Core\Session::getInstance();
            return $session->get('user_id') ? (int)$session->get('user_id') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function getIpAddress(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        if (function_exists('get_client_ip')) {
            return get_client_ip();
        }
        return null;
    }

    private function getUserAgent(): string
    {
        if (function_exists('get_user_agent')) {
            return get_user_agent();
        }
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
}
