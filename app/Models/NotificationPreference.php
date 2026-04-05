<?php

namespace App\Models;

use Core\Model;

class NotificationPreference extends Model
{
    protected static string $table = 'notification_preferences';

    // فیلدهای مجاز برای in-app و email
    private const ALLOWED_FIELDS = [
        'in_app_enabled',    'email_enabled',
        'in_app_deposit',    'email_deposit',
        'in_app_withdrawal', 'email_withdrawal',
        'in_app_task',       'email_task',
        'in_app_kyc',        'email_kyc',
        'in_app_lottery',    'email_lottery',
        'in_app_referral',   'email_referral',
        'in_app_security',   'email_security',
        'in_app_investment',  'email_investment',
        'in_app_system',     'email_system',
        'email_marketing',
    ];

    /**
     * دریافت یا ایجاد تنظیمات کاربر
     */
    public function getOrCreate(int $userId): object
    {
        $prefs = $this->db->query(
            "SELECT * FROM " . static::$table . " WHERE user_id = ? LIMIT 1",
            [$userId]
        )->fetch(\PDO::FETCH_OBJ);

        if (!$prefs) {
            $now = date('Y-m-d H:i:s');
            $this->db->query(
                "INSERT INTO " . static::$table . " (user_id, created_at, updated_at)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE updated_at = updated_at",
                [$userId, $now, $now]
            );

            $prefs = $this->db->query(
                "SELECT * FROM " . static::$table . " WHERE user_id = ? LIMIT 1",
                [$userId]
            )->fetch(\PDO::FETCH_OBJ);
        }

        // اگر رکورد هنوز نیست (جدول پیدا نشد)، یک object پیش‌فرض برمی‌گردانیم
        if (!$prefs) {
            return (object)[
                'user_id'       => $userId,
                'in_app_enabled' => 1,
                'email_enabled'  => 1,
            ];
        }

        return $prefs;
    }

    /**
     * بررسی فعال بودن نوتیف In-App
     * BUG FIX 9: استفاده از SQL مستقیم به جای table()->insert که ممکن بود وجود نداشته باشد
     */
    public function isInAppEnabled(int $userId, string $type): bool
    {
        try {
            $prefs = $this->getOrCreate($userId);
        } catch (\Throwable $e) {
            return true; // در صورت خطا، ارسال می‌شود
        }

        // اگر in-app کلاً غیرفعال باشد
        if (isset($prefs->in_app_enabled) && !$prefs->in_app_enabled) {
            return false;
        }

        // چک فیلد خاص type
        $field = 'in_app_' . $type;
        if (property_exists($prefs, $field)) {
            return (bool)$prefs->$field;
        }

        return true; // پیش‌فرض: فعال
    }

    /**
     * بررسی فعال بودن نوتیف Email
     */
    public function isEmailEnabled(int $userId, string $type): bool
    {
        try {
            $prefs = $this->getOrCreate($userId);
        } catch (\Throwable $e) {
            return true;
        }

        if (isset($prefs->email_enabled) && !$prefs->email_enabled) {
            return false;
        }

        $field = 'email_' . $type;
        if (property_exists($prefs, $field)) {
            return (bool)$prefs->$field;
        }

        return true;
    }

    /**
     * آپدیت تنظیمات کاربر
     * BUG FIX 9: قبلاً با implode و array_map SQL می‌ساخت که برای کلیدهای خاص
     * (مثل کلیدهایی با backtick یا injection) ناایمن بود
     */
    public function updateForUser(int $userId, array $data): bool
    {
        if (empty($data)) {
            return true;
        }

        // فقط فیلدهای مجاز را قبول می‌کنیم
        $filtered = array_filter(
            $data,
            fn($key) => in_array($key, self::ALLOWED_FIELDS, true),
            ARRAY_FILTER_USE_KEY
        );

        if (empty($filtered)) {
            return false;
        }

        $this->getOrCreate($userId); // اطمینان از وجود رکورد

        $sets   = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($filtered)));
        $values = array_values($filtered);
        $values[] = $userId;

        $stmt = $this->db->query(
            "UPDATE " . static::$table . " SET {$sets}, updated_at = NOW() WHERE user_id = ?",
            $values
        );

        return $stmt instanceof \PDOStatement;
    }
}
