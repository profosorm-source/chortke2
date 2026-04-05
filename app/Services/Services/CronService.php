<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Advertisement;
use App\Models\CryptoDeposit;
use App\Models\KYCVerification;
use App\Models\PasswordReset;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSession;
use Core\Cache;
use Core\Database;

/**
 * CronService — لایه Service برای عملیات Cron Jobs
 *
 * تمام عملیات DB مستقیم از cron scripts به اینجا منتقل شده.
 * Cron scripts فقط این Service را صدا می‌زنند.
 */
class CronService
{
    private Database        $db;
    private UserSession     $sessionModel;
    private ActivityLog     $logModel;
    private PasswordReset   $passwordResetModel;
    private Advertisement   $adModel;
    private Transaction     $transactionModel;
    private CryptoDeposit   $cryptoDepositModel;
    private KYCVerification $kycModel;
    private User            $userModel;

    public function __construct(Database $db,
        \App\Models\ActivityLog $logModel,
        \App\Models\Advertisement $adModel,
        \App\Models\CryptoDeposit $cryptoDepositModel,
        \App\Models\KYCVerification $kycModel,
        \App\Models\PasswordReset $passwordResetModel,
        \App\Models\Transaction $transactionModel,
        \App\Models\User $userModel,
        \App\Models\UserSession $sessionModel){
        $this->db = $db;
        $this->sessionModel = $sessionModel;
        $this->logModel = $logModel;
        $this->passwordResetModel = $passwordResetModel;
        $this->adModel = $adModel;
        $this->transactionModel = $transactionModel;
        $this->cryptoDepositModel = $cryptoDepositModel;
        $this->kycModel = $kycModel;
        $this->userModel = $userModel;
    }

    // ─────────────────────────────────────────────────────────────
    // Sessions
    // ─────────────────────────────────────────────────────────────

    /**
     * حذف session های قدیمی (بیش از N روز)
     * استفاده در: cleanup_old_sessions.php و cron.php
     */
    public function deleteOldSessions(int $days = 7): int
    {
        // UserSession model متد deleteExpired دارد
        // اما برای پشتیبانی از N روز دلخواه، مستقیم query می‌زنیم
        $stmt = $this->db->prepare(
            "DELETE FROM user_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL :days DAY)"
        );
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    // ─────────────────────────────────────────────────────────────
    // Activity Logs
    // ─────────────────────────────────────────────────────────────

    /**
     * حذف لاگ‌های قدیمی (بیش از N روز) — از طریق ActivityLog Model
     */
    public function deleteOldActivityLogs(int $days = 90): int
    {
        return $this->logModel->softDeleteOlderThan($days);
    }

    // ─────────────────────────────────────────────────────────────
    // Password Resets
    // ─────────────────────────────────────────────────────────────

    /**
     * حذف توکن‌های منقضی‌شده بازیابی رمز — از طریق PasswordReset Model
     */
    public function deleteExpiredPasswordResets(): int
    {
        return $this->passwordResetModel->deleteExpired();
    }

    // ─────────────────────────────────────────────────────────────
    // Email Queue
    // ─────────────────────────────────────────────────────────────

    /**
     * حذف ایمیل‌های ارسال‌شده قدیمی (بیش از N روز)
     */
    public function deleteOldSentEmails(int $days = 30): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM email_queue
             WHERE status = 'sent' AND sent_at < DATE_SUB(NOW(), INTERVAL :days DAY)"
        );
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    // ─────────────────────────────────────────────────────────────
    // Advertisements
    // ─────────────────────────────────────────────────────────────

    /**
     * غیرفعال کردن آگهی‌های منقضی‌شده
     */
    public function expireOldAdvertisements(): int
    {
        $stmt = $this->db->prepare(
            "UPDATE advertisements
             SET status = 'completed', updated_at = NOW()
             WHERE status = 'active'
               AND (
                 (end_date IS NOT NULL AND end_date < NOW())
                 OR remaining_count <= 0
                 OR remaining_budget <= 0
               )"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    // ─────────────────────────────────────────────────────────────
    // Crypto Deposits
    // ─────────────────────────────────────────────────────────────

    /**
     * دریافت واریزهای در انتظار تأیید (حداکثر N ساعت قبل)
     */
    public function getPendingCryptoDeposits(int $hours = 12, int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT id FROM crypto_deposits
             WHERE status = 'pending'
               AND created_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
             ORDER BY created_at ASC
             LIMIT :lim",
            ['hours' => $hours, 'lim' => $limit]
        );
    }

    /**
     * دریافت واریزهای منقضی‌شده (بیش از N دقیقه، N بار تلاش شده)
     */
    public function getExpiredCryptoDeposits(int $minutes = 30, int $minAttempts = 3): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM crypto_deposits
             WHERE verification_status = 'pending'
               AND verification_attempts >= :attempts
               AND created_at < DATE_SUB(NOW(), INTERVAL :minutes MINUTE)"
        );
        $stmt->execute(['attempts' => $minAttempts, 'minutes' => $minutes]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * انتقال واریز به manual review — از طریق CryptoDeposit Model
     */
    public function moveCryptoDepositToManualReview(int $depositId, string $reason): bool
    {
        return $this->cryptoDepositModel->updateStatus($depositId, 'manual_review', null, $reason);
    }

    // ─────────────────────────────────────────────────────────────
    // Transactions
    // ─────────────────────────────────────────────────────────────

    /**
     * دریافت تراکنش‌های گیرکرده (processing بیش از N ساعت)
     */
    public function getStuckTransactions(int $hours = 1): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM transactions
             WHERE status = 'processing'
               AND created_at < DATE_SUB(NOW(), INTERVAL :hours HOUR)"
        );
        $stmt->execute(['hours' => $hours]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * علامت‌گذاری تراکنش به عنوان failed — از طریق Transaction Model
     */
    public function markTransactionFailed(int $transactionId): bool
    {
        return $this->transactionModel->updateStatus(
            $transactionId,
            'failed',
            ['reason' => 'Transaction timeout - auto-failed by system']
        );
    }

    // ─────────────────────────────────────────────────────────────
    // KYC
    // ─────────────────────────────────────────────────────────────

    /**
     * دریافت KYC های رد شده قدیمی (برای پاک‌سازی فایل‌ها)
     */
    public function getOldRejectedKycRecords(int $days = 60): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, document_front, document_back, selfie
             FROM kyc_verifications
             WHERE status = 'rejected'
               AND updated_at < DATE_SUB(NOW(), INTERVAL :days DAY)
               AND documents_deleted = 0"
        );
        $stmt->execute(['days' => $days]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * علامت‌گذاری KYC به عنوان پاک‌شده — از طریق KYCVerification Model
     */
    public function markKycDocumentsDeleted(int $kycId): bool
    {
        return $this->kycModel->update($kycId, ['documents_deleted' => 1]);
    }

    // ─────────────────────────────────────────────────────────────
    // Users / Tiers
    // ─────────────────────────────────────────────────────────────

    /**
     * دریافت کاربران با سطح بالا (برای بررسی tier)
     */
    public function getUsersWithElevatedTier(array $tiers = ['gold', 'vip']): array
    {
        $placeholders = implode(',', array_fill(0, count($tiers), '?'));
        $stmt         = $this->db->prepare(
            "SELECT id, username, tier_level, last_active_date
             FROM users
             WHERE tier_level IN ({$placeholders})
               AND deleted_at IS NULL"
        );
        $stmt->execute($tiers);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * شمارش روزهای فعال کاربر در ماه جاری — از طریق ActivityLog Model
     */
    public function countUserActiveDaysThisMonth(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT DATE(created_at)) as days
             FROM activity_logs
             WHERE user_id = :user_id
               AND created_at >= :month_start"
        );
        $stmt->execute(['user_id' => $userId, 'month_start' => date('Y-m-01')]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (int)($row['days'] ?? 0);
    }

    /**
     * Reset کردن tier کاربر — از طریق User Model
     */
    public function resetUserTierToSilver(int $userId): bool
    {
        return $this->userModel->update($userId, [
            'tier_level'       => 'silver',
            'tier_points'      => 0,
            'tier_expires_at'  => null,
        ]);
    }

    /**
     * ثبت لاگ فعالیت سیستمی — از طریق ActivityLog Model
     */
    public function logSystemActivity(int $userId, string $action, string $description): void
    {
        $this->logModel->log([
            'user_id'     => $userId,
            'action'      => $action,
            'description' => $description,
            'ip_address'  => '127.0.0.1',
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Weekly KPI Report
    // ─────────────────────────────────────────────────────────────

    /**
     * تعداد کاربران جدید N روز گذشته
     */
    public function countNewUsers(int $days = 7): int
    {
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)",
            ['days' => $days]
        );
    }

    /**
     * حجم تراکنش‌های N روز گذشته
     */
    public function getTransactionVolume(int $days = 7): float
    {
        return (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
               AND status = 'completed'",
            ['days' => $days]
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Daily Wallet Report
    // ─────────────────────────────────────────────────────────────

    /**
     * گزارش تراکنش‌های روزانه برای یک ارز
     */
    public function getDailyTransactionReport(string $currency, string $date): object
    {
        $stmt = $this->db->prepare(
            "SELECT
                COUNT(*)                                                              AS total_count,
                SUM(CASE WHEN type='deposit'  AND status='completed' THEN amount ELSE 0 END) AS total_deposits,
                SUM(CASE WHEN type='withdraw' AND status='completed' THEN amount ELSE 0 END) AS total_withdrawals,
                COUNT(CASE WHEN type='deposit'  THEN 1 END)                          AS deposit_count,
                COUNT(CASE WHEN type='withdraw' THEN 1 END)                          AS withdrawal_count
             FROM transactions
             WHERE currency = :currency AND DATE(created_at) = :date"
        );
        $stmt->execute(['currency' => $currency, 'date' => $date]);
        return $stmt->fetch(\PDO::FETCH_OBJ);
    }
}
