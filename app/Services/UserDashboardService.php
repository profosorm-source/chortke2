<?php

namespace App\Services;

use Core\Database;

class UserDashboardService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function getStats(int $userId): array
    {
        $today = \date('Y-m-d');

        $todayDeposit = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM transactions 
             WHERE user_id = :uid AND type = 'deposit' AND status = 'completed' AND DATE(created_at) = :d",
            ['uid' => $userId, 'd' => $today]
        );

        $todayWithdraw = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM transactions 
             WHERE user_id = :uid AND type = 'withdraw' AND status = 'completed' AND DATE(created_at) = :d",
            ['uid' => $userId, 'd' => $today]
        );

        $pendingTx = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM transactions 
             WHERE user_id = :uid AND status IN ('pending','processing')",
            ['uid' => $userId]
        );

        $totalEarningsMonth = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount),0) FROM transactions
             WHERE user_id = :uid AND type IN ('task_reward','commission') AND status = 'completed'
             AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            ['uid' => $userId]
        );

        $lastTransactions = $this->db->fetchAll(
            "SELECT id, type, currency, amount, status, created_at
             FROM transactions
             WHERE user_id = :uid
             ORDER BY id DESC
             LIMIT 10",
            ['uid' => $userId]
        );

        return [
            'today_deposit'      => $todayDeposit,
            'today_withdraw'     => $todayWithdraw,
            'pending_tx'         => $pendingTx,
            'earnings_30d'       => $totalEarningsMonth,
            'last_transactions'  => $lastTransactions,
        ];
    }
}
