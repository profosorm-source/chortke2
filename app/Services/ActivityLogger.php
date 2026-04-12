<?php

namespace App\Services;

use App\Models\ActivityLog;
use Core\Database;

/**
 * ActivityLogger Service - مدیریت لاگ فعالیت‌های کاربران
 * 
 * جایگزین کامل log_activity()
 */
class ActivityLogger
{
    private Database $db;
    private ActivityLog $activityLog;

    public function __construct(Database $db, ActivityLog $activityLog)
    {
        $this->db = $db;
        $this->activityLog = $activityLog;
    }

    /**
     * ثبت فعالیت
     */
    public function log(
        string $action,
        ?string $description = null,
        ?int $userId = null,
        ?array $metadata = null,
        ?string $model = null,
        ?int $modelId = null
    ): bool {
        return $this->activityLog->create([
            'user_id' => $userId,
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata,
            'model' => $model,
            'model_id' => $modelId,
        ]);
    }

    /**
     * لاگ‌های اخیر
     */
    public function getRecent(int $limit = 50, ?int $userId = null, ?string $action = null): array
    {
        return $this->activityLog->getRecent($limit, $userId, $action);
    }

    /**
     * لاگ‌ها با صفحه‌بندی
     */
    public function getPaginated(
        int $page = 1,
        int $perPage = 20,
        ?int $userId = null,
        ?string $action = null,
        ?string $searchTerm = null
    ): array {
        return $this->activityLog->getPaginated($page, $perPage, $userId, $action, $searchTerm);
    }

    /**
     * حذف لاگ‌های قدیمی
     */
    public function deleteOlderThan(int $days = 90): int
    {
        return $this->activityLog->deleteOlderThan($days);
    }

    /**
     * Soft delete لاگ‌های قدیمی
     */
    public function softDeleteOlderThan(int $days = 90): int
    {
        return $this->activityLog->softDeleteOlderThan($days);
    }

    /**
     * آمار فعالیت‌ها
     */
    public function getStats(?int $userId = null): array
    {
        try {
            $where = $userId ? "WHERE user_id = ?" : "WHERE 1=1";
            $params = $userId ? [$userId] : [];

            $stmt = $this->db->query(
                "SELECT 
                    COUNT(*) as total,
                    COUNT(DISTINCT user_id) as unique_users,
                    COUNT(DISTINCT action) as unique_actions,
                    DATE(created_at) as date
                 FROM activity_logs
                 {$where}
                 AND deleted_at IS NULL
                 AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY DATE(created_at)
                 ORDER BY date DESC",
                $params
            );

            if (!$stmt instanceof \PDOStatement) {
                return [];
            }

            return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * پرکاربردترین فعالیت‌ها
     */
    public function getTopActions(int $limit = 10, ?int $userId = null): array
    {
        try {
            $where = $userId ? "WHERE user_id = ?" : "WHERE 1=1";
            $params = $userId ? [$userId, $limit] : [$limit];

            $stmt = $this->db->query(
                "SELECT action, COUNT(*) as count
                 FROM activity_logs
                 {$where}
                 AND deleted_at IS NULL
                 GROUP BY action
                 ORDER BY count DESC
                 LIMIT ?",
                $params
            );

            if (!$stmt instanceof \PDOStatement) {
                return [];
            }

            return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }
}
