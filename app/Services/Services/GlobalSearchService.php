<?php

namespace App\Services;

use Core\Database;

/**
 * GlobalSearchService - جستجوی یکپارچه در همه بخش‌ها
 *
 * جستجو در:
 *   - کاربران (admin)
 *   - تراکنش‌ها (admin + user)
 *   - تیکت‌ها (admin + user)
 *   - آگهی‌ها / کمپین‌ها
 *   - اجراهای تسک
 *   - لاگ‌های فعالیت (admin)
 */
class GlobalSearchService
{
    private Database $db;

    public function __construct(Database $db){
        $this->db = $db;}

    /**
     * جستجوی جامع برای ادمین
     *
     * @return array ['users'=>[], 'transactions'=>[], 'tickets'=>[], ...]
     */
    public function searchAdmin(string $query, int $limit = 5): array
    {
        $q = $this->sanitize($query);
        if (strlen($q) < 2) {
            return $this->emptyResult();
        }

        return [
            'users'        => $this->searchUsers($q, $limit),
            'transactions' => $this->searchTransactions($q, $limit),
            'tickets'      => $this->searchTickets($q, $limit),
            'withdrawals'  => $this->searchWithdrawals($q, $limit),
            'deposits'     => $this->searchDeposits($q, $limit),
            'ads'          => $this->searchAds($q, $limit),
            'total'        => 0, // بعد محاسبه می‌شود
        ];
    }

    /**
     * جستجوی محدود برای کاربر عادی (فقط داده‌های خودش)
     */
    public function searchUser(string $query, int $userId, int $limit = 5): array
    {
        $q = $this->sanitize($query);
        if (strlen($q) < 2) {
            return $this->emptyResult();
        }

        return [
            'transactions' => $this->searchUserTransactions($q, $userId, $limit),
            'tickets'      => $this->searchUserTickets($q, $userId, $limit),
            'ads'          => $this->searchUserAds($q, $userId, $limit),
            'tasks'        => $this->searchUserTasks($q, $userId, $limit),
        ];
    }

    // ─────────────────────────────────────────────────────────
    //  Admin searches
    // ─────────────────────────────────────────────────────────

    private function searchUsers(string $q, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->fetchAll(
            "SELECT id, full_name, email, mobile, kyc_status, tier_level, created_at
             FROM users
             WHERE deleted_at IS NULL
               AND (full_name LIKE ? OR email LIKE ? OR mobile LIKE ? OR referral_code = ?)
             ORDER BY created_at DESC
             LIMIT ?",
            [$like, $like, $like, $q, $limit]
        );
    }

    private function searchTransactions(string $q, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->fetchAll(
            "SELECT t.id, t.type, t.amount, t.currency, t.status, t.description, t.created_at,
                    u.full_name, u.email
             FROM transactions t
             LEFT JOIN users u ON u.id = t.user_id
             WHERE t.reference_id LIKE ? OR t.description LIKE ? OR u.email LIKE ?
               OR CAST(t.id AS CHAR) = ?
             ORDER BY t.created_at DESC
             LIMIT ?",
            [$like, $like, $like, $q, $limit]
        );
    }

    private function searchTickets(string $q, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->fetchAll(
            "SELECT tk.id, tk.subject, tk.status, tk.priority, tk.created_at,
                    u.full_name, u.email
             FROM tickets tk
             LEFT JOIN users u ON u.id = tk.user_id
             WHERE tk.subject LIKE ? OR tk.message LIKE ? OR u.email LIKE ?
               OR CAST(tk.id AS CHAR) = ?
             ORDER BY tk.created_at DESC
             LIMIT ?",
            [$like, $like, $like, $q, $limit]
        );
    }

    private function searchWithdrawals(string $q, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->fetchAll(
            "SELECT w.id, w.amount, w.currency, w.status, w.created_at,
                    u.full_name, u.email
             FROM withdrawals w
             LEFT JOIN users u ON u.id = w.user_id
             WHERE w.tracking_code LIKE ? OR u.email LIKE ?
               OR CAST(w.id AS CHAR) = ?
             ORDER BY w.created_at DESC
             LIMIT ?",
            [$like, $like, $q, $limit]
        );
    }

    private function searchDeposits(string $q, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->fetchAll(
            "SELECT md.id, md.amount, md.status, md.created_at, 'manual' AS type,
                    u.full_name, u.email
             FROM manual_deposits md
             LEFT JOIN users u ON u.id = md.user_id
             WHERE md.tracking_code LIKE ? OR u.email LIKE ?
             UNION ALL
             SELECT cd.id, cd.amount, cd.status, cd.created_at, 'crypto' AS type,
                    u.full_name, u.email
             FROM crypto_deposits cd
             LEFT JOIN users u ON u.id = cd.user_id
             WHERE cd.tx_hash LIKE ? OR u.email LIKE ?
             ORDER BY created_at DESC
             LIMIT ?",
            [$like, $like, $like, $like, $limit]
        );
    }

    private function searchAds(string $q, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->fetchAll(
            "SELECT a.id, a.title, a.platform, a.task_type, a.status, a.created_at,
                    u.full_name, u.email
             FROM advertisements a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.deleted_at IS NULL AND (a.title LIKE ? OR u.email LIKE ?)
             ORDER BY a.created_at DESC
             LIMIT ?",
            [$like, $like, $limit]
        );
    }

    // ─────────────────────────────────────────────────────────
    //  User searches (محدود به داده‌های خود)
    // ─────────────────────────────────────────────────────────

    private function searchUserTransactions(string $q, int $userId, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->fetchAll(
            "SELECT id, type, amount, currency, status, description, created_at
             FROM transactions
             WHERE user_id = ? AND (description LIKE ? OR reference_id LIKE ?)
             ORDER BY created_at DESC LIMIT ?",
            [$userId, $like, $like, $limit]
        );
    }

    private function searchUserTickets(string $q, int $userId, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->fetchAll(
            "SELECT id, subject, status, priority, created_at
             FROM tickets
             WHERE user_id = ? AND (subject LIKE ? OR message LIKE ?)
             ORDER BY created_at DESC LIMIT ?",
            [$userId, $like, $like, $limit]
        );
    }

    private function searchUserAds(string $q, int $userId, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->fetchAll(
            "SELECT id, title, platform, task_type, status, created_at
             FROM advertisements
             WHERE user_id = ? AND deleted_at IS NULL AND title LIKE ?
             ORDER BY created_at DESC LIMIT ?",
            [$userId, $like, $limit]
        );
    }

    private function searchUserTasks(string $q, int $userId, int $limit): array
    {
        $like = "%{$q}%";
        return $this->db->fetchAll(
            "SELECT te.id, te.status, te.reward_amount, te.created_at, a.title AS ad_title
             FROM task_executions te
             JOIN advertisements a ON a.id = te.advertisement_id
             WHERE te.executor_id = ? AND a.title LIKE ?
             ORDER BY te.created_at DESC LIMIT ?",
            [$userId, $like, $limit]
        );
    }

    // ─────────────────────────────────────────────────────────
    //  helpers
    // ─────────────────────────────────────────────────────────

    private function sanitize(string $q): string
    {
        return trim(preg_replace('/[%_\\\\]/', '\\\\$0', $q));
    }

    private function emptyResult(): array
    {
        return [
            'users' => [], 'transactions' => [], 'tickets' => [],
            'withdrawals' => [], 'deposits' => [], 'ads' => [],
            'tasks' => [], 'total' => 0,
        ];
    }
}
