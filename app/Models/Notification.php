<?php

namespace App\Models;

use Core\Model;

class Notification extends Model
{
    protected static string $table = 'notifications';

    // ─── BUG FIX 5: ثابت‌های TYPE که در NotificationService استفاده می‌شوند
    // قبلاً فقط TYPE_SYSTEM/DEPOSIT/SECURITY وجود داشت
    // TYPE_TASK/WITHDRAWAL/KYC/LOTTERY/REFERRAL/INVESTMENT گم بودند → undefined constant
    public const TYPE_SYSTEM     = 'system';
    public const TYPE_DEPOSIT    = 'deposit';
    public const TYPE_WITHDRAWAL = 'withdrawal';
    public const TYPE_TASK       = 'task';
    public const TYPE_KYC        = 'kyc';
    public const TYPE_LOTTERY    = 'lottery';
    public const TYPE_REFERRAL   = 'referral';
    public const TYPE_SECURITY   = 'security';
    public const TYPE_INVESTMENT = 'investment';
    public const TYPE_INFO       = 'info';

    /**
     * ایجاد نوتیفیکیشن
     */
    public function create(array $data): int|false
    {
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->query(
            "INSERT INTO notifications
                (user_id, type, title, message, data, action_url, action_text,
                 priority, is_read, is_archived, expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['user_id']     ?? null,
                $data['type']        ?? self::TYPE_SYSTEM,
                $data['title']       ?? '',
                $data['message']     ?? '',
                isset($data['data'])
                    ? (is_array($data['data']) ? json_encode($data['data'], JSON_UNESCAPED_UNICODE) : $data['data'])
                    : null,
                $data['action_url']  ?? null,
                $data['action_text'] ?? null,
                $data['priority']    ?? 'normal',
                (int)($data['is_read']     ?? 0),
                (int)($data['is_archived'] ?? 0),
                $data['expires_at']  ?? null,
                $now,
                $now,
            ]
        );

        if (!$stmt) {
            return false;
        }

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : false;
    }

    /**
     * آخرین نوتیفیکیشن‌های کاربر
     */
    public function getLatestForUser(int $userId, int $limit = 10): array
    {
        $limit = max(1, min(200, $limit));

        return $this->db->query(
            "SELECT *
             FROM notifications
             WHERE user_id = ?
               AND is_archived = 0
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY id DESC
             LIMIT {$limit}",
            [$userId]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * نوتیفیکیشن‌های کاربر با فیلتر و مرتب‌سازی اولویت
     */
    public function getUserNotifications(int $userId, bool $onlyUnread = false, int $limit = 20): array
    {
        $limit  = max(1, min(200, $limit));
        $sql    = "SELECT *
                   FROM notifications
                   WHERE user_id = ?
                     AND is_archived = 0
                     AND (expires_at IS NULL OR expires_at > NOW())";
        $params = [$userId];

        if ($onlyUnread) {
            $sql .= " AND is_read = 0";
        }

        $sql .= " ORDER BY
                    CASE priority
                      WHEN 'urgent' THEN 4
                      WHEN 'high'   THEN 3
                      WHEN 'normal' THEN 2
                      WHEN 'low'    THEN 1
                      ELSE 0
                    END DESC,
                    created_at DESC
                  LIMIT {$limit}";

        return $this->db->query($sql, $params)->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * تعداد خوانده‌نشده
     */
    public function countUnread(int $userId): int
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS total
             FROM notifications
             WHERE user_id = ?
               AND is_read = 0
               AND is_archived = 0
               AND (expires_at IS NULL OR expires_at > NOW())",
            [$userId]
        )->fetch(\PDO::FETCH_OBJ);

        return (int)($row->total ?? 0);
    }

    /** alias */
    public function getUnreadCount(int $userId): int
    {
        return $this->countUnread($userId);
    }

    /**
     * علامت خواندن یک نوتیفیکیشن
     */
    public function markAsRead(int $id, int $userId): bool
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_read = 1, read_at = ?, updated_at = ?
             WHERE id = ? AND user_id = ?",
            [$now, $now, $id, $userId]
        );
        return $stmt instanceof \PDOStatement && $stmt->rowCount() > 0;
    }

    /**
     * علامت خواندن همه نوتیفیکیشن‌ها
     * BUG FIX 8: قبلاً rowCount را به عنوان نتیجه برمی‌گرداند که با true/false مغایر بود
     */
    public function markAllAsRead(int $userId): bool
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_read = 1, read_at = ?, updated_at = ?
             WHERE user_id = ? AND is_read = 0",
            [$now, $now, $userId]
        );
        // هر تعداد ردیف (حتی 0) موفقیت‌آمیز است
        return $stmt instanceof \PDOStatement;
    }

    /**
     * تعداد خوانده‌شده در یک markAllAsRead
     */
    public function markAllAsReadCount(int $userId): int
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_read = 1, read_at = ?, updated_at = ?
             WHERE user_id = ? AND is_read = 0",
            [$now, $now, $userId]
        );
        return $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
    }

    /**
     * آرشیو کردن
     */
    public function archive(int $notificationId, int $userId): bool
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_archived = 1, archived_at = ?, updated_at = ?
             WHERE id = ? AND user_id = ?",
            [$now, $now, $notificationId, $userId]
        );
        return $stmt instanceof \PDOStatement && $stmt->rowCount() > 0;
    }

    /**
     * آرشیو کردن منقضی‌شده‌ها
     */
    public function deleteExpired(): int
    {
        $now  = date('Y-m-d H:i:s');
        $stmt = $this->db->query(
            "UPDATE notifications
             SET is_archived = 1, archived_at = ?, updated_at = ?
             WHERE is_archived = 0
               AND expires_at IS NOT NULL
               AND expires_at < ?",
            [$now, $now, $now]
        );
        return $stmt instanceof \PDOStatement ? $stmt->rowCount() : 0;
    }

    /**
     * دریافت بر اساس نوع
     */
    public function getByType(int $userId, string $type, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));

        return $this->db->query(
            "SELECT *
             FROM notifications
             WHERE user_id = ?
               AND type = ?
               AND is_archived = 0
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY created_at DESC
             LIMIT {$limit}",
            [$userId, $type]
        )->fetchAll(\PDO::FETCH_OBJ);
    }
}
