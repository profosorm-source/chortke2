<?php

namespace App\Models;

use Core\Model;

/**
 * مدل کدهای بازیابی / OTP دو مرحله‌ای
 * جدول: two_factor_codes
 */
class TwoFactorCode extends Model
{
    protected static string $table = 'two_factor_codes';

    /**
     * حذف همه کدهای یک کاربر (قبل از ذخیره کدهای جدید)
     */
    public function deleteByUserId(int $userId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM " . static::$table . " WHERE user_id = ?");
        return $stmt->execute([$userId]);
    }

    /**
     * ذخیره یک کد بازیابی (hash شده)
     */
    public function insertCode(int $userId, string $hashedCode, string $expiresAt): bool
    {
        $sql = "INSERT INTO " . static::$table . " (user_id, code, used, expires_at, created_at)
                VALUES (?, ?, 0, ?, NOW())";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$userId, $hashedCode, $expiresAt]);
    }

    /**
     * یافتن کد بازیابی معتبر (استفاده‌نشده و منقضی‌نشده)
     */
    public function findValidCode(int $userId, string $hashedCode): ?array
    {
        $sql = "SELECT id FROM " . static::$table . "
                WHERE user_id = ?
                  AND code = ?
                  AND used = 0
                  AND expires_at > NOW()
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $hashedCode]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * علامت‌گذاری کد به عنوان استفاده‌شده
     */
    public function markAsUsed(int $id): bool
    {
        $sql  = "UPDATE " . static::$table . " SET used = 1 WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }
}
