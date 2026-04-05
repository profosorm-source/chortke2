<?php

namespace App\Models;

use Core\Model;

class CouponRedemption extends Model
{
    protected static string $table = 'coupon_redemptions';
    
    protected array $fillable = [
        'coupon_id', 'user_id', 'original_amount', 'discount_amount',
        'final_amount', 'currency', 'entity_type', 'entity_id', 'ip_address'
    ];

    /**
     * تمام مصارف با سازگاری با Model پایه
     */
    public function all(int $limit = 100, int $offset = 0): array
    {
        return $this->db->query(
            "SELECT cr.*, c.code, u.username 
             FROM " . static::$table . " cr
             LEFT JOIN coupons c ON cr.coupon_id = c.id
             LEFT JOIN users u ON cr.user_id = u.id
             ORDER BY cr.created_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        )->fetchAll();
    }

    /**
     * بررسی مصرف قبلی کاربر برای یک کوپن خاص
     */
    public function hasUserUsedCoupon(int $userId, int $couponId): bool
    {
        $result = $this->db->query(
            "SELECT id FROM " . static::$table . " 
             WHERE user_id = ? AND coupon_id = ? 
             LIMIT 1",
            [$userId, $couponId]
        )->fetch();

        return !empty($result);
    }

    /**
     * تاریخچه مصرف یک کوپن
     */
    public function getCouponHistory(int $couponId, int $limit = 50): array
    {
        return $this->db->query(
            "SELECT cr.*, u.username, u.email 
             FROM " . static::$table . " cr
             LEFT JOIN users u ON cr.user_id = u.id
             WHERE cr.coupon_id = ?
             ORDER BY cr.created_at DESC
             LIMIT ?",
            [$couponId, $limit]
        )->fetchAll();
    }

    /**
     * تاریخچه مصرف کوپن‌های یک کاربر
     */
    public function getUserHistory(int $userId, int $limit = 20): array
    {
        return $this->db->query(
            "SELECT cr.*, c.code, c.type, c.value 
             FROM " . static::$table . " cr
             LEFT JOIN coupons c ON cr.coupon_id = c.id
             WHERE cr.user_id = ?
             ORDER BY cr.created_at DESC
             LIMIT ?",
            [$userId, $limit]
        )->fetchAll();
    }

    /**
     * آمار مصرف کوپن
     */
    public function getCouponStats(int $couponId): object|false
    {
        return $this->db->query(
            "SELECT 
                COUNT(*) as total_uses,
                SUM(discount_amount) as total_discount,
                AVG(discount_amount) as avg_discount,
                MAX(discount_amount) as max_discount,
                MIN(discount_amount) as min_discount
             FROM " . static::$table . "
             WHERE coupon_id = ?",
            [$couponId]
        )->fetch();
    }

    /**
     * مصارف امروز
     */
    public function getTodayRedemptions(int $limit = 100): array
    {
        return $this->db->query(
            "SELECT cr.*, c.code, u.username 
             FROM " . static::$table . " cr
             LEFT JOIN coupons c ON cr.coupon_id = c.id
             LEFT JOIN users u ON cr.user_id = u.id
             WHERE DATE(cr.created_at) = CURDATE()
             ORDER BY cr.created_at DESC
             LIMIT ?",
            [$limit]
        )->fetchAll();
    }

    /**
     * آمار کلی مصارف
     */
    public function getOverallStats(): object|false
    {
        return $this->db->query(
            "SELECT 
                COUNT(*) as total_redemptions,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(DISTINCT coupon_id) as used_coupons,
                SUM(discount_amount) as total_discount_given,
                AVG(discount_amount) as avg_discount_per_use
             FROM " . static::$table
        )->fetch();
    }

    /**
     * مصارف بر اساس نوع موجودیت
     */
    public function getRedemptionsByEntityType(string $entityType, int $limit = 100): array
    {
        return $this->db->query(
            "SELECT cr.*, c.code, u.username 
             FROM " . static::$table . " cr
             LEFT JOIN coupons c ON cr.coupon_id = c.id
             LEFT JOIN users u ON cr.user_id = u.id
             WHERE cr.entity_type = ?
             ORDER BY cr.created_at DESC
             LIMIT ?",
            [$entityType, $limit]
        )->fetchAll();
    }

    /**
     * مصارف بر اساس واحد پول
     */
    public function getRedemptionsByCurrency(string $currency, int $limit = 100): array
    {
        return $this->db->query(
            "SELECT cr.*, c.code, u.username 
             FROM " . static::$table . " cr
             LEFT JOIN coupons c ON cr.coupon_id = c.id
             LEFT JOIN users u ON cr.user_id = u.id
             WHERE cr.currency = ?
             ORDER BY cr.created_at DESC
             LIMIT ?",
            [$currency, $limit]
        )->fetchAll();
    }

    /**
     * شمارش کل مصارف
     */
    public function count(): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as total FROM " . static::$table
        )->fetch();

        return (int)($result->total ?? 0);
    }

    /**
     * شمارش مصارف امروز
     */
    public function countToday(): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as total FROM " . static::$table . " 
             WHERE DATE(created_at) = CURDATE()"
        )->fetch();

        return (int)($result->total ?? 0);
    }
}