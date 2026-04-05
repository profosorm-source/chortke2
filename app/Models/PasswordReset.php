<?php

namespace App\Models;

use Core\Model;

class PasswordReset extends Model
{
    protected static string $table = 'password_resets';

    /**
     * ایجاد توکن بازیابی رمز عبور
     */
    public function createToken(string $email): string|false
    {
        $this->deleteByEmail($email);

        $token = bin2hex(random_bytes(32));

        $result = $this->db->table(static::$table)->insert([
            'email' => $email,
            'token' => $token,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $result ? $token : false;
    }

    /**
     * پیدا کردن با توکن
     */
    public function findByToken(string $token): ?object
    {
        $result = $this->db->table(static::$table)
            ->where('token', '=', $token)
            ->first();

        return $result ?: null;
    }

    /**
     * بررسی معتبر بودن توکن
     */
    public function isValidToken(string $token): bool
    {
        $reset = $this->findByToken($token);
        if (!$reset) {
            return false;
        }
        return !$this->isExpired($reset);
    }

    /**
     * بررسی انقضا (1 ساعت)
     */
    public function isExpired(object $reset): bool
    {
        $createdAt = strtotime($reset->created_at);
        return (time() - $createdAt) > 3600;
    }

    /**
     * تأیید توکن و دریافت رکورد
     */
    public function verifyToken(string $token): ?object
    {
        $reset = $this->findByToken($token);
        if (!$reset || $this->isExpired($reset)) {
            return null;
        }
        return $reset;
    }

    /**
     * حذف با ایمیل
     */
    public function deleteByEmail(string $email): bool
    {
        return $this->db->table(static::$table)
            ->where('email', '=', $email)
            ->delete();
    }

    /**
     * حذف توکن‌های منقضی شده
     */
    public function deleteExpired(): int
    {
        $oneHourAgo = date('Y-m-d H:i:s', time() - 3600);
        return $this->db->table(static::$table)
            ->where('created_at', '<', $oneHourAgo)
            ->delete();
    }
}
