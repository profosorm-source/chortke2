<?php

namespace App\Models;

use Core\Model;

class Coupon extends Model
{
    protected static string $table = 'coupons';
    
    protected array $fillable = [
        'code', 'type', 'value', 'min_purchase', 'max_discount',
        'start_date', 'end_date', 'usage_limit', 'usage_count',
        'applicable_to', 'active', 'created_by'
    ];

    /**
     * تمام کوپن‌ها با سازگاری با Model پایه
     */
    public function all(int $limit = 100, int $offset = 0): array
    {
        return $this->db->query(
            "SELECT * FROM " . static::$table . " 
             WHERE deleted_at IS NULL 
             ORDER BY id DESC 
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        )->fetchAll();
    }

    /**
     * یافتن کوپن با کد
     */
    public function findByCode(string $code): ?object
    {
        $result = $this->db->query(
            "SELECT * FROM " . static::$table . " WHERE code = ? AND deleted_at IS NULL LIMIT 1",
            [$code]
        )->fetch();

        return $result ?: null;
    }

    /**
     * لیست کوپن‌های فعال
     */
    public function getActiveCoupons(int $limit = 100): array
    {
        return $this->db->query(
            "SELECT * FROM " . static::$table . " 
             WHERE active = 1 AND deleted_at IS NULL 
             ORDER BY created_at DESC 
             LIMIT ?",
            [$limit]
        )->fetchAll();
    }

    /**
     * بررسی فعال بودن کوپن (instance method)
     */
    public function isActive(): bool
    {
        if (!$this->active) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        if ($this->start_date && $this->start_date > $now) {
            return false;
        }

        if ($this->end_date && $this->end_date < $now) {
            return false;
        }

        if ($this->usage_limit > 0 && $this->usage_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    /**
     * افزایش شمارنده مصرف
     */
    public function incrementUsage(int $couponId): bool
    {
        return $this->db->query(
            "UPDATE " . static::$table . " SET usage_count = usage_count + 1 WHERE id = ?",
            [$couponId]
        ) !== false;
    }

    /**
     * تغییر وضعیت فعال/غیرفعال
     */
    public function toggleActive(int $couponId): bool
    {
        return $this->db->query(
            "UPDATE " . static::$table . " SET active = NOT active WHERE id = ?",
            [$couponId]
        ) !== false;
    }

    /**
     * جستجو در کوپن‌ها
     */
    public function search(string $query, int $limit = 50): array
    {
        return $this->db->query(
            "SELECT * FROM " . static::$table . " 
             WHERE (code LIKE ? OR applicable_to LIKE ?) 
             AND deleted_at IS NULL 
             ORDER BY created_at DESC
             LIMIT ?",
            ["%{$query}%", "%{$query}%", $limit]
        )->fetchAll();
    }

    /**
     * کوپن‌های منقضی شده
     */
    public function getExpiredCoupons(int $limit = 100): array
    {
        $now = date('Y-m-d H:i:s');
        return $this->db->query(
            "SELECT * FROM " . static::$table . " 
             WHERE deleted_at IS NULL 
             AND (
                 (end_date IS NOT NULL AND end_date < ?) 
                 OR (usage_limit > 0 AND usage_count >= usage_limit)
             )
             ORDER BY end_date DESC
             LIMIT ?",
            [$now, $limit]
        )->fetchAll();
    }

    /**
     * شمارش کل کوپن‌ها
     */
    public function count(): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as total FROM " . static::$table . " WHERE deleted_at IS NULL"
        )->fetch();

        return (int)($result->total ?? 0);
    }

    /**
     * شمارش کوپن‌های فعال
     */
    public function countActive(): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as total FROM " . static::$table . " 
             WHERE active = 1 AND deleted_at IS NULL"
        )->fetch();

        return (int)($result->total ?? 0);
    }
}