<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class UserSession extends Model {
/**
     * ایجاد نشست جدید
     */
    public function create(array $data): int|false
    {
        // از INSERT ... ON DUPLICATE KEY UPDATE استفاده می‌کنیم
        // تا در صورت تکراری بودن session_id، به‌جای خطا، رکورد آپدیت شود
        $sql = "INSERT INTO user_sessions (
            user_id, session_id, ip_address, user_agent, 
            device_type, browser, os, country, city, fingerprint,
            last_activity, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            ip_address    = VALUES(ip_address),
            user_agent    = VALUES(user_agent),
            device_type   = VALUES(device_type),
            browser       = VALUES(browser),
            os            = VALUES(os),
            country       = VALUES(country),
            city          = VALUES(city),
            fingerprint   = VALUES(fingerprint),
            last_activity = VALUES(last_activity),
            updated_at    = VALUES(updated_at)";

        $now    = \date('Y-m-d H:i:s');
        $result = $this->db->query($sql, [
            $data['user_id'],
            $data['session_id'],
            $data['ip_address'],
            $data['user_agent'],
            $data['device_type'] ?? null,
            $data['browser'] ?? null,
            $data['os'] ?? null,
            $data['country'] ?? null,
            $data['city'] ?? null,
            $data['fingerprint'] ?? null,
            $now,
            $now,
            $now
        ]);

        return $result ? $this->db->lastInsertId() : false;
    }

    /**
     * به‌روزرسانی زمان فعالیت
     */
    public function updateActivity(string $sessionId): bool
    {
        $sql = "UPDATE user_sessions 
                SET last_activity = ?, updated_at = ?
                WHERE session_id = ? AND is_active = 1";
        
        return (bool)$this->db->query($sql, [
            \date('Y-m-d H:i:s'),
            \date('Y-m-d H:i:s'),
            $sessionId
        ]);
    }

    /**
     * دریافت نشست‌های فعال کاربر
     */
    public function getActiveSessions(int $userId): array
    {
        $sql = "SELECT * FROM user_sessions 
                WHERE user_id = ? AND is_active = 1
                ORDER BY last_activity DESC";
        
        return $this->db->query($sql, [$userId])->fetchAll();
    }

    /**
     * یافتن نشست با session_id
     */
    public function findBySessionId(string $sessionId): ?object
    {
        $sql    = "SELECT * FROM user_sessions WHERE session_id = ? LIMIT 1";
        $result = $this->db->query($sql, [$sessionId])->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * غیرفعال کردن نشست
     */
    public function deactivate(int $id): bool
    {
        $sql = "UPDATE user_sessions SET is_active = 0, updated_at = ? WHERE id = ?";
        return (bool)$this->db->query($sql, [\date('Y-m-d H:i:s'), $id]);
    }

    /**
     * حذف نشست‌های غیرفعال (قدیمی‌تر از 30 روز)
     */
    public function deleteInactive(): bool
    {
        $sql = "DELETE FROM user_sessions 
                WHERE is_active = 0 AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)";
        
        return (bool)$this->db->query($sql);
    }

    /**
     * حذف نشست‌های منقضی شده (بیش از 7 روز بدون فعالیت)
     */
    public function deleteExpired(): bool
    {
        $sql = "UPDATE user_sessions 
                SET is_active = 0 
                WHERE last_activity < DATE_SUB(NOW(), INTERVAL 7 DAY)";
        
        return (bool)$this->db->query($sql);
    }

    /**
     * شمارش نشست‌های فعال
     */
    public function countActive(int $userId): int
    {
        $sql  = "SELECT COUNT(*) as total FROM user_sessions WHERE user_id = ? AND is_active = 1";
        $row  = $this->db->query($sql, [$userId])->fetch(\PDO::FETCH_OBJ);
        return (int)(($row !== false ? $row : null)?->total ?? 0);
    }
}