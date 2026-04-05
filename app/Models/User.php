<?php

namespace App\Models;

use Core\Model;

class User extends Model
{
    protected static string $table = 'users';

    /**
     * ایجاد کاربر جدید با تمام Logic
     */
    public function createUser(array $data): int|false
    {
        if (isset($data['password'])) {
            $data['password'] = hash_password($data['password']);
        }

        if (!isset($data['referral_code'])) {
            $data['referral_code'] = $this->generateReferralCode();
        }

        $data['email_verification_token'] = bin2hex(random_bytes(32));
        $data['device_fingerprint'] = generate_device_fingerprint();
        $data['last_ip'] = get_client_ip();
        $data['last_user_agent'] = get_user_agent();
        $data['last_active_date'] = date('Y-m-d');

        if (!isset($data['status'])) {
            $data['status'] = 'active';
        }

        if (!isset($data['role'])) {
            $data['role'] = 'user';
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        return $this->create($data);
    }

    /**
     * تولید Referral Code یونیک
     * استفاده از random_bytes برای امنیت بیشتر
     */
    protected function generateReferralCode(): string
    {
        do {
            // تولید 4 بایت تصادفی و تبدیل به hex (8 کاراکتر)
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
            $exists = $this->findByReferralCode($code);
        } while ($exists);

        return $code;
    }

    /**
     * پیدا کردن با Referral Code
     */
    public function findByReferralCode(string $code): ?object
    {
        $result = $this->db->table(static::$table)
            ->where('referral_code', '=', $code)
            ->first();

        return $result ?: null;
    }

    /**
     * پیدا کردن کاربر با ایمیل
     */
    public function findByEmail(string $email): ?object
    {
        $result = $this->db->table(static::$table)
            ->where('email', '=', $email)
            ->first();

        return $result ?: null;
    }

    /**
     * پیدا کردن کاربر با موبایل
     */
    public function findByMobile(string $mobile): ?object
    {
        $result = $this->db->table(static::$table)
            ->where('mobile', '=', $mobile)
            ->first();

        return $result ?: null;
    }

    /**
     * پیدا کردن کاربر با ID
     */
    public function findById(int $id): ?object
    {
        $result = $this->db->table(static::$table)
            ->where('id', '=', $id)
            ->first();

        return $result ?: null;
    }

    /**
     * پیدا کردن کاربر با Remember Token
     */
    public function findByRememberToken(string $token): ?object
    {
        $result = $this->db->table(static::$table)
            ->where('remember_token', '=', $token)
            ->first();

        return $result ?: null;
    }

    /**
     * پیدا کردن کاربر با credential (email یا mobile)
     */
    public function findByCredentials(string $identifier): ?object
    {
        $result = $this->db->table(static::$table)
            ->where('email', '=', $identifier)
            ->orWhere('mobile', '=', $identifier)
            ->first();

        return $result ?: null;
    }

    /**
     * بررسی وجود ایمیل
     */
    public function emailExists(string $email): bool
    {
        return $this->findByEmail($email) !== null;
    }

    /**
     * بررسی وجود موبایل
     */
    public function mobileExists(string $mobile): bool
    {
        return $this->findByMobile($mobile) !== null;
    }

    /**
     * متد واحد و نهایی برای همه آپدیت‌ها
     */
    public function update(int $userId, array $data): bool
    {
        return $this->db->table(static::$table)
            ->where('id', '=', $userId)
            ->update($data);
    }

    /**
     * تأیید ایمیل بر اساس token
     */
    public function verifyEmailByToken(string $token): ?object
    {
        $user = $this->db->table(static::$table)
            ->where('email_verification_token', '=', $token)
            ->first();

        if (!$user) {
            return null;
        }

        $this->update($user->id, [
            'email_verified_at' => date('Y-m-d H:i:s'),
            'email_verification_token' => null,
        ]);

        return $user;
    }

    /**
     * تأیید ایمیل با کد ۶ رقمی (۶ کاراکتر اول token)
     */
    public function verifyEmailByCode(string $email, string $code): ?object
    {
        $code = strtolower(trim($code));

        $user = $this->db->table(static::$table)
            ->where('email', '=', $email)
            ->whereNotNull('email_verification_token')
            ->first();

        if (!$user) {
            return null;
        }

        // کد = ۶ کاراکتر اول token
        $expectedCode = strtolower(substr($user->email_verification_token, 0, 6));

        if (!hash_equals($expectedCode, $code)) {
            return null;
        }

        $this->update($user->id, [
            'email_verified_at'        => date('Y-m-d H:i:s'),
            'email_verification_token' => null,
        ]);

        return $user;
    }

    /**
     * تأیید ایمیل بر اساس userId
     */
    public function verifyEmail(int $userId): bool
    {
        return $this->update($userId, [
            'email_verified_at' => date('Y-m-d H:i:s'),
            'email_verification_token' => null,
        ]);
    }

    /**
     * تغییر رمز عبور
     */
    public function changePassword(int $userId, string $newPassword): bool
    {
        return $this->update($userId, [
            'password' => hash_password($newPassword),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * آپدیت Remember Token
     */
    public function updateRememberToken(int $userId, string $token): bool
    {
        return $this->update($userId, [
            'remember_token' => hash('sha256', $token),
        ]);
    }

    /**
     * آپدیت Password
     */
    public function updatePassword(int $userId, string $hashedPassword): bool
    {
        return $this->update($userId, [
            'password' => $hashedPassword,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * آپدیت اطلاعات Login
     */
    public function updateLoginInfo(int $userId): bool
    {
        return $this->update($userId, [
            'last_login' => date('Y-m-d H:i:s'),
            'last_ip' => get_client_ip(),
            'last_user_agent' => get_user_agent(),
        ]);
    }

    /**
     * آپدیت آخرین ورود (alias)
     */
    public function updateLastLogin(int $userId): bool
    {
        return $this->updateLoginInfo($userId);
    }

    /**
     * Ban کردن کاربر
     */
    public function ban(int $userId, ?string $reason = null): bool
    {
        return $this->update($userId, [
            'status' => 'banned',
            'ban_reason' => $reason,
            'banned_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * UnBan کردن کاربر
     */
    public function unban(int $userId): bool
    {
        return $this->update($userId, [
            'status' => 'active',
            'ban_reason' => null,
            'banned_at' => null,
        ]);
    }

    /**
     * افزایش Fraud Score
     */
    public function incrementFraudScore(int $userId, int $amount = 1): bool
    {
        $sql = "UPDATE " . static::$table . " SET fraud_score = COALESCE(fraud_score, 0) + :amount WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['amount' => $amount, 'id' => $userId]);
    }

    /**
     * بررسی Blacklist
     */
    public function isBlacklisted(int $userId): bool
    {
        $user = $this->find($userId);
        return $user && isset($user->is_blacklisted) && $user->is_blacklisted == 1;
    }

    /**
     * دریافت همه کاربران
     */
    public function all(int $limit = 100, int $offset = 0): array
    {
        return $this->db->table(static::$table)
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    /**
     * جستجو در کاربران
     */
    public function search(string $query): array
    {
        return $this->db->table(static::$table)
            ->where('full_name', 'LIKE', "%{$query}%")
            ->orWhere('email', 'LIKE', "%{$query}%")
            ->orWhere('mobile', 'LIKE', "%{$query}%")
            ->get();
    }

    /**
     * تعداد کل کاربران
     */
    public function count(): int
    {
        $result = $this->db->table(static::$table)->count();
        return (int) $result;
    }

    /**
     * کاربران فعال امروز
     */
    public function activeToday(): int
    {
        $today = date('Y-m-d');
        $result = $this->db->table(static::$table)
            ->where('last_active_date', '=', $today)
            ->count();
        return (int) $result;
    }

    /**
     * جستجو با فیلترهای پیشرفته (برای ادمین)
     */
    public function searchWithFilters(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = "(full_name LIKE :search1 OR email LIKE :search2 OR mobile LIKE :search3)";
            $params['search1'] = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
            $params['search3'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['role'])) {
            $where[] = "role = :role";
            $params['role'] = $filters['role'];
        }

        if (!empty($filters['status'])) {
            $where[] = "status = :status";
            $params['status'] = $filters['status'];
        }

        $whereStr = implode(' AND ', $where);
        $sql = "SELECT * FROM " . static::$table . " WHERE {$whereStr} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش کاربران با فیلتر
     */
    public function countWithFilters(array $filters = []): int
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = "(full_name LIKE :search1 OR email LIKE :search2 OR mobile LIKE :search3)";
            $params['search1'] = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
            $params['search3'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['role'])) {
            $where[] = "role = :role";
            $params['role'] = $filters['role'];
        }

        if (!empty($filters['status'])) {
            $where[] = "status = :status";
            $params['status'] = $filters['status'];
        }

        $whereStr = implode(' AND ', $where);
        $sql = "SELECT COUNT(*) as cnt FROM " . static::$table . " WHERE {$whereStr}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return (int)($result->cnt ?? 0);
    }

    /**
     * آمار کاربران برای ادمین
     */
    public function getAdminStats(): object
    {
        $sql = "SELECT
            COUNT(*) AS total_count,
            SUM(CASE WHEN deleted_at IS NULL AND status = 'active' THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN deleted_at IS NULL AND status = 'suspended' THEN 1 ELSE 0 END) AS suspended_count,
            SUM(CASE WHEN deleted_at IS NULL AND status = 'banned' THEN 1 ELSE 0 END) AS banned_count,
            SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS deleted_count
        FROM " . static::$table;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        
        return $result ?: (object)['total_count' => 0, 'active_count' => 0, 'suspended_count' => 0, 'banned_count' => 0, 'deleted_count' => 0];
    }

}