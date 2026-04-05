<?php

namespace App\Models;

use Core\Model;

class EmailQueue extends Model
{
    protected static string $table = 'email_queue';

    /**
     * دریافت ایمیل‌های آماده ارسال (با اولویت‌بندی)
     */
    public function getPendingEmails(int $limit = 10): array
    {
        $now = date('Y-m-d H:i:s');

        return $this->db->fetchAll(
            "SELECT * FROM " . static::$table . "
             WHERE status IN ('pending', 'sending')
               AND attempts < 3
               AND (scheduled_at IS NULL OR scheduled_at <= :now)
             ORDER BY
               CASE priority
                 WHEN 'urgent' THEN 1
                 WHEN 'high'   THEN 2
                 WHEN 'normal' THEN 3
                 ELSE 4
               END ASC,
               created_at ASC
             LIMIT :limit",
            ['now' => $now, 'limit' => $limit]
        );
    }

    /**
     * علامت‌گذاری به عنوان در حال ارسال
     */
    public function markAsSending(int $emailId): bool
    {
        return $this->db->execute(
            "UPDATE " . static::$table . "
             SET status = 'sending', updated_at = NOW()
             WHERE id = ?",
            [$emailId]
        ) !== false;
    }

    /**
     * علامت‌گذاری به عنوان ارسال شده
     */
    public function markAsSent(int $emailId): bool
    {
        return $this->db->execute(
            "UPDATE " . static::$table . "
             SET status = 'sent', sent_at = NOW(), updated_at = NOW()
             WHERE id = ?",
            [$emailId]
        ) !== false;
    }

    /**
     * علامت‌گذاری به عنوان ناموفق (با retry logic)
     */
    public function markAsFailed(int $emailId, string $error): bool
    {
        // اگر attempts >= 2 بشه 3، دیگه retry نمی‌شه → status = failed
        return $this->db->execute(
            "UPDATE " . static::$table . "
             SET
               attempts      = attempts + 1,
               status        = IF(attempts + 1 >= 3, 'failed', 'pending'),
               error_message = ?,
               updated_at    = NOW()
             WHERE id = ?",
            [$error, $emailId]
        ) !== false;
    }

    /**
     * حذف ایمیل‌های ارسال‌شده قدیمی
     */
    public function cleanOldSent(int $days = 30): int
    {
        return (int)$this->db->execute(
            "DELETE FROM " . static::$table . "
             WHERE status = 'sent'
               AND sent_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
    }

    /**
     * آرشیو ایمیل‌های قدیمی به جای حذف
     */
    public function archiveOldSent(int $days = 30): int
    {
        return (int)$this->db->query(
            "UPDATE " . static::$table . "
             SET is_archived = 1, archived_at = NOW()
             WHERE status = 'sent'
               AND sent_at < DATE_SUB(NOW(), INTERVAL ? DAY)
               AND (is_archived IS NULL OR is_archived = 0)",
            [$days]
        );
    }

    /**
     * آمار صف
     */
    public function getStats(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT status, COUNT(*) as cnt FROM " . static::$table . "
             GROUP BY status"
        );
        $stats = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($rows as $r) {
            $r = (array)$r;
            $stats[$r['status']] = (int)$r['cnt'];
        }
        return $stats;
    }

    /**
     * ایمیل‌های ناموفق برای بررسی ادمین
     */
    public function getFailedEmails(int $limit = 50): array
    {
        return $this->db->fetchAll(
            "SELECT eq.*, u.full_name, u.email as user_email
             FROM " . static::$table . " eq
             LEFT JOIN users u ON u.id = eq.user_id
             WHERE eq.status = 'failed'
             ORDER BY eq.updated_at DESC
             LIMIT ?",
            [$limit]
        );
    }
}