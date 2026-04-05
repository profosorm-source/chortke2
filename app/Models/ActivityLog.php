<?php

namespace App\Models;

use Core\Model;
use Core\Database;

/**
 * ActivityLog Model
 *
 * استاندارد: extends Core\Model → $this->db از پدر (نه تعریف دوباره)
 * هیچ private $db جداگانه‌ای نداریم تا shadow نشود.
 */
class ActivityLog extends Model
{
    protected static string $table = 'activity_logs';

    /**
     * ثبت فعالیت جدید
     */
    public function log(
        string  $action,
        ?string $description = null,
        ?int    $userId      = null,
        ?array  $metadata    = null,
        ?string $model       = null,
        ?int    $modelId     = null
    ): bool {
        try {
            // اگر userId داده نشده، از session می‌گیریم
            if ($userId === null) {
                try {
                    $sid = \Core\Session::getInstance()->get('user_id');
                    $userId = $sid ? (int)$sid : null;
                } catch (\Throwable $e) {
                    $userId = null;
                }
            }

            $this->db->query(
                "INSERT INTO activity_logs
                    (user_id, action, description, model, model_id,
                     ip_address, user_agent, metadata, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $userId,
                    $action,
                    $description,
                    $model,
                    $modelId,
                    function_exists('get_client_ip') ? get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? null),
                    function_exists('get_user_agent') ? get_user_agent() : ($_SERVER['HTTP_USER_AGENT'] ?? null),
                    $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
                ]
            );

            return true;
        } catch (\Throwable $e) {
            if (function_exists('logger')) {
                logger()->error('ActivityLog insert failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * لاگ‌های اخیر (با join به users)
     */
    public function getRecent(int $limit = 50, ?int $userId = null, ?string $action = null): array
    {
        $limit  = max(1, min(500, $limit));
        $sql    = "SELECT al.*, u.full_name, u.email
                   FROM activity_logs al
                   LEFT JOIN users u ON al.user_id = u.id
                   WHERE al.deleted_at IS NULL";
        $params = [];

        if ($userId) {
            $sql     .= " AND al.user_id = ?";
            $params[] = (int)$userId;
        }
        if ($action) {
            $sql     .= " AND al.action = ?";
            $params[] = $action;
        }

        $sql .= " ORDER BY al.created_at DESC LIMIT {$limit}";

        return $this->db->query($sql, $params)->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * صفحه‌بندی شده با فیلتر
     */
    public function getPaginated(
        int     $page       = 1,
        int     $perPage    = 20,
        ?int    $userId     = null,
        ?string $action     = null,
        ?string $searchTerm = null
    ): array {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $where  = "WHERE al.deleted_at IS NULL";
        $params = [];

        if ($userId) {
            $where   .= " AND al.user_id = ?";
            $params[] = (int)$userId;
        }
        if ($action) {
            $where   .= " AND al.action = ?";
            $params[] = $action;
        }
        if ($searchTerm) {
            $like     = '%' . $searchTerm . '%';
            $where   .= " AND (al.description LIKE ? OR u.full_name LIKE ? OR u.email LIKE ?)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $base = "FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id $where";

        $total = (int)($this->db->query("SELECT COUNT(*) $base", $params)->fetchColumn() ?: 0);
        $logs  = $this->db->query(
            "SELECT al.*, u.full_name, u.email $base ORDER BY al.created_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll(\PDO::FETCH_OBJ);

        return [
            'logs'       => $logs,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => (int)ceil($total / $perPage),
        ];
    }

    /**
     * Soft-delete لاگ‌های قدیمی
     */
    public function softDeleteOlderThan(int $days = 90): int
    {
        $days = max(1, $days);
        $stmt = $this->db->query(
            "UPDATE activity_logs
             SET deleted_at = NOW()
             WHERE deleted_at IS NULL
               AND created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
        );
        return $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
    }

    /**
     * حذف فیزیکی لاگ‌های قدیمی (alias برای LogController)
     */
    public function deleteOlderThan(int $days = 90): int
    {
        $days = max(1, $days);
        $stmt = $this->db->query(
            "DELETE FROM activity_logs
             WHERE created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
        );
        return $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
    }
}
