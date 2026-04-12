<?php

namespace App\Services;

use Core\Database;
use Core\Cache;

class KpiService
{
    protected Database $db;
    private Cache $cache;
    private int $cacheTtl = 5; // دقیقه - داده‌های KPI تازه باشند

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->cache = Cache::getInstance();
    }

    // ==========================================
    //  آمار کلی کاربران
    // ==========================================

    /**
     * آمار کاربران
     */
    public function getUserStats(): array
    {
        $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL");
        $active = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM users WHERE status = 1 AND deleted_at IS NULL");
        $banned = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM users WHERE status = 3 AND deleted_at IS NULL");
        $suspended = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM users WHERE status = 2 AND deleted_at IS NULL");

        $today = \date('Y-m-d');
        $newToday = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE DATE(created_at) = :today AND deleted_at IS NULL",
            ['today' => $today]
        );

        $thisWeek = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND deleted_at IS NULL"
        );

        $thisMonth = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND deleted_at IS NULL"
        );

        // DAU - کاربران فعال امروز (آخرین ورود امروز)
        $dau = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE DATE(last_login) = :today AND deleted_at IS NULL",
            ['today' => $today]
        );

        // MAU - کاربران فعال ماهانه
        $mau = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND deleted_at IS NULL"
        );

        // WAU - کاربران فعال هفتگی
        $wau = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND deleted_at IS NULL"
        );

        // سطح‌بندی
        $tierStats = $this->db->fetchAll(
            "SELECT COALESCE(tier_level, 'silver') as tier, COUNT(*) as count 
             FROM users WHERE deleted_at IS NULL GROUP BY tier_level"
        );

        $tiers = ['silver' => 0, 'gold' => 0, 'vip' => 0];
        foreach ($tierStats as $t) {
            $key = \is_array($t) ? $t['tier'] : $t->tier;
            $val = \is_array($t) ? $t['count'] : $t->count;
            $tiers[$key] = (int)$val;
        }

        // KYC
        $kycVerified = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM kyc_verifications WHERE status = 'verified'"
        );

        $kycPending = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM kyc_verifications WHERE status IN ('pending','under_review')"
        );

        return [
            'total' => $total,
            'active' => $active,
            'banned' => $banned,
            'suspended' => $suspended,
            'new_today' => $newToday,
            'new_this_week' => $thisWeek,
            'new_this_month' => $thisMonth,
            'dau' => $dau,
            'wau' => $wau,
            'mau' => $mau,
            'tiers' => $tiers,
            'kyc_verified' => $kycVerified,
            'kyc_pending' => $kycPending,
        ];
    }

    // ==========================================
    //  آمار مالی
    // ==========================================

    /**
     * آمار مالی
     */
    public function getFinancialStats(?string $currency = null): array
    {
        $curr = $currency ?: $this->getActiveCurrency();

        // مجموع واریزها
        $totalDeposits = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions 
             WHERE type = 'deposit' AND status = 'completed' AND currency = :c",
            ['c' => $curr]
        );

        // مجموع برداشت‌ها
        $totalWithdrawals = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions 
             WHERE type = 'withdraw' AND status = 'completed' AND currency = :c",
            ['c' => $curr]
        );

        // واریز امروز
        $todayDeposits = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions 
             WHERE type = 'deposit' AND status = 'completed' AND currency = :c AND DATE(created_at) = :today",
            ['c' => $curr, 'today' => \date('Y-m-d')]
        );

        // برداشت امروز
        $todayWithdrawals = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions 
             WHERE type = 'withdraw' AND status = 'completed' AND currency = :c AND DATE(created_at) = :today",
            ['c' => $curr, 'today' => \date('Y-m-d')]
        );

        // تراکنش‌های در انتظار
        $pendingTransactions = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM transactions WHERE status = 'pending'"
        );

        // درآمد سایت (کمیسیون + مالیات)
        $siteRevenue = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions 
             WHERE type IN ('commission_site','tax','fee') AND status = 'completed' AND currency = :c",
            ['c' => $curr]
        );

        // درآمد امروز
        $todayRevenue = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions 
             WHERE type IN ('commission_site','tax','fee') AND status = 'completed' AND currency = :c AND DATE(created_at) = :today",
            ['c' => $curr, 'today' => \date('Y-m-d')]
        );

        // درآمد هفتگی
        $weeklyRevenue = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions 
             WHERE type IN ('commission_site','tax','fee') AND status = 'completed' AND currency = :c 
             AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            ['c' => $curr]
        );

        // درآمد ماهانه
        $monthlyRevenue = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM transactions 
             WHERE type IN ('commission_site','tax','fee') AND status = 'completed' AND currency = :c 
             AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            ['c' => $curr]
        );

        // تعداد تراکنش‌ها
        $totalTransactions = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM transactions WHERE deleted_at IS NULL"
        );

        // ARPU (Average Revenue Per User)
        $activeUsers = (int)$this->db->fetchColumn(
            "SELECT COUNT(DISTINCT user_id) FROM transactions WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $arpu = $activeUsers > 0 ? \round($monthlyRevenue / $activeUsers, 2) : 0;

        return [
            'currency' => $curr,
            'total_deposits' => $totalDeposits,
            'total_withdrawals' => $totalWithdrawals,
            'today_deposits' => $todayDeposits,
            'today_withdrawals' => $todayWithdrawals,
            'pending_transactions' => $pendingTransactions,
            'site_revenue' => $siteRevenue,
            'today_revenue' => $todayRevenue,
            'weekly_revenue' => $weeklyRevenue,
            'monthly_revenue' => $monthlyRevenue,
            'total_transactions' => $totalTransactions,
            'arpu' => $arpu,
            'net_flow' => $totalDeposits - $totalWithdrawals,
        ];
    }

    // ==========================================
    //  آمار تسک‌ها و تبلیغات
    // ==========================================

    /**
     * آمار تسک‌ها
     */
    public function getTaskStats(): array
    {
        $totalTasks = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM tasks WHERE deleted_at IS NULL"
        );

        $activeTasks = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM tasks WHERE status = 'active' AND deleted_at IS NULL"
        );

        $completedToday = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM task_executions WHERE status = 'completed' AND DATE(completed_at) = :today",
            ['today' => \date('Y-m-d')]
        );

        $completedThisWeek = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM task_executions WHERE status = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        $completedThisMonth = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM task_executions WHERE status = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        $pendingVerification = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM task_executions WHERE status = 'pending_review'"
        );

        $fraudDetected = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM task_executions WHERE status = 'fraud_detected'"
        );

        // تسک بر اساس پلتفرم
        $byPlatform = $this->db->fetchAll(
            "SELECT platform, COUNT(*) as count FROM tasks WHERE deleted_at IS NULL GROUP BY platform ORDER BY count DESC"
        );

        // تسک بر اساس نوع
        $byType = $this->db->fetchAll(
            "SELECT type, COUNT(*) as count FROM tasks WHERE deleted_at IS NULL GROUP BY type ORDER BY count DESC"
        );

        return [
            'total' => $totalTasks,
            'active' => $activeTasks,
            'completed_today' => $completedToday,
            'completed_week' => $completedThisWeek,
            'completed_month' => $completedThisMonth,
            'pending_verification' => $pendingVerification,
            'fraud_detected' => $fraudDetected,
            'by_platform' => $byPlatform,
            'by_type' => $byType,
        ];
    }

    // ==========================================
    //  آمار سرمایه‌گذاری
    // ==========================================

    /**
     * آمار سرمایه‌گذاری
     */
    public function getInvestmentStats(): array
    {
        $totalInvestments = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM investments WHERE deleted_at IS NULL"
        );

        $activeInvestments = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM investments WHERE status = 'active' AND deleted_at IS NULL"
        );

        $totalInvested = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(amount), 0) FROM investments WHERE status = 'active' AND deleted_at IS NULL"
        );

        $totalProfit = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(profit_loss), 0) FROM trading_records WHERE profit_loss > 0"
        );

        $totalLoss = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(ABS(profit_loss)), 0) FROM trading_records WHERE profit_loss < 0"
        );

        return [
            'total' => $totalInvestments,
            'active' => $activeInvestments,
            'total_invested' => $totalInvested,
            'total_profit' => $totalProfit,
            'total_loss' => $totalLoss,
            'net_profit' => $totalProfit - $totalLoss,
        ];
    }

    // ==========================================
    //  آمار قرعه‌کشی
    // ==========================================

    /**
     * آمار قرعه‌کشی
     */
    public function getLotteryStats(): array
    {
        $totalParticipants = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM lottery_participations WHERE active = 1"
        );

        $totalVotesToday = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM lottery_votes WHERE date = :today",
            ['today' => \date('Y-m-d')]
        );

        $avgChanceScore = (float)$this->db->fetchColumn(
            "SELECT COALESCE(AVG(chance_score), 0) FROM lottery_participations WHERE active = 1"
        );

        return [
            'total_participants' => $totalParticipants,
            'votes_today' => $totalVotesToday,
            'avg_chance_score' => \round($avgChanceScore, 1),
        ];
    }

    // ==========================================
    //  آمار پشتیبانی
    // ==========================================

    /**
     * آمار تیکت‌ها
     */
    public function getTicketStats(): array
    {
        $open = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM tickets WHERE status = 'open' AND deleted_at IS NULL"
        );

        $inProgress = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM tickets WHERE status = 'in_progress' AND deleted_at IS NULL"
        );

        $avgResponseTime = $this->db->fetchColumn(
            "SELECT AVG(TIMESTAMPDIFF(HOUR, t.created_at, tm.created_at))
             FROM tickets t
             INNER JOIN ticket_messages tm ON tm.ticket_id = t.id AND tm.user_type = 'admin'
             WHERE tm.id = (SELECT MIN(id) FROM ticket_messages WHERE ticket_id = t.id AND user_type = 'admin')"
        );

        $totalTickets = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM tickets WHERE deleted_at IS NULL"
        );

        return [
            'open' => $open,
            'in_progress' => $inProgress,
            'total' => $totalTickets,
            'avg_response_hours' => $avgResponseTime ? \round((float)$avgResponseTime, 1) : 0,
        ];
    }

    // ==========================================
    //  آمار ضد تقلب
    // ==========================================

    /**
     * آمار Anti-Fraud
     */
    public function getFraudStats(): array
    {
        $suspiciousUsers = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE fraud_score >= 50 AND deleted_at IS NULL"
        );

        $blockedToday = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE status = 3 AND DATE(updated_at) = :today AND deleted_at IS NULL",
            ['today' => \date('Y-m-d')]
        );

        $silentBlacklisted = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE is_silent_blacklisted = 1 AND deleted_at IS NULL"
        );

        $fraudTasks = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM task_executions WHERE status = 'fraud_detected' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        return [
            'suspicious_users' => $suspiciousUsers,
            'blocked_today' => $blockedToday,
            'silent_blacklisted' => $silentBlacklisted,
            'fraud_tasks_month' => $fraudTasks,
        ];
    }

    // ==========================================
    //  آمار Referral
    // ==========================================

    /**
     * آمار سیستم معرفی
     * از جدول referral_commissions و users استفاده می‌کند
     */
    public function getReferralStats(): array
    {
        // تعداد کل کاربران معرفی‌شده (کاربرانی که referred_by دارند)
        $totalReferrals = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE referred_by IS NOT NULL AND deleted_at IS NULL"
        );

        // مجموع کمیسیون‌های پرداخت‌شده
        $totalCommissions = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(commission_amount), 0) FROM referral_commissions WHERE status = 'paid'"
        );

        // کمیسیون‌های در انتظار پرداخت
        $pendingCommissions = (float)$this->db->fetchColumn(
            "SELECT COALESCE(SUM(commission_amount), 0) FROM referral_commissions WHERE status = 'pending'"
        );

        // تعداد کمیسیون‌های امروز
        $todayCommissions = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM referral_commissions WHERE DATE(created_at) = :today",
            ['today' => date('Y-m-d')]
        );

        // برترین معرف‌ها بر اساس تعداد زیرمجموعه و مجموع کمیسیون
        $topReferrers = $this->db->fetchAll(
            "SELECT 
                u.full_name,
                u.email,
                rc.referrer_id,
                COUNT(DISTINCT rc.referred_id) as referral_count,
                COALESCE(SUM(rc.commission_amount), 0) as total_earned
             FROM referral_commissions rc
             JOIN users u ON u.id = rc.referrer_id
             WHERE rc.status IN ('paid', 'pending')
             GROUP BY rc.referrer_id, u.full_name, u.email
             ORDER BY referral_count DESC
             LIMIT 10"
        );

        return [
            'total'               => $totalReferrals,
            'total_commissions'   => $totalCommissions,
            'pending_commissions' => $pendingCommissions,
            'today_commissions'   => $todayCommissions,
            'top_referrers'       => $topReferrers,
        ];
    }

    // ==========================================
    //  نمودارها - داده‌های زمانی
    // ==========================================

    /**
     * ثبت‌نام روزانه
     */
    public function getDailyRegistrations(int $days = 30): array
    {
        return $this->db->fetchAll(
            "SELECT DATE(created_at) as date, COUNT(*) as count 
             FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL :d DAY) AND deleted_at IS NULL
             GROUP BY DATE(created_at) ORDER BY date ASC",
            ['d' => $days]
        );
    }

    /**
     * درآمد روزانه
     */
    public function getDailyRevenue(int $days = 30, ?string $currency = null): array
    {
        $curr = $currency ?: $this->getActiveCurrency();

        return $this->db->fetchAll(
            "SELECT DATE(created_at) as date, COALESCE(SUM(amount), 0) as total
             FROM transactions 
             WHERE type IN ('commission_site','tax','fee') AND status = 'completed' AND currency = :c
             AND created_at >= DATE_SUB(NOW(), INTERVAL :d DAY)
             GROUP BY DATE(created_at) ORDER BY date ASC",
            ['c' => $curr, 'd' => $days]
        );
    }

    /**
     * واریز و برداشت روزانه
     */
    public function getDailyDepositsWithdrawals(int $days = 30, ?string $currency = null): array
    {
        $curr = $currency ?: $this->getActiveCurrency();

        $deposits = $this->db->fetchAll(
            "SELECT DATE(created_at) as date, COALESCE(SUM(amount), 0) as total
             FROM transactions WHERE type = 'deposit' AND status = 'completed' AND currency = :c
             AND created_at >= DATE_SUB(NOW(), INTERVAL :d DAY)
             GROUP BY DATE(created_at) ORDER BY date ASC",
            ['c' => $curr, 'd' => $days]
        );

        $withdrawals = $this->db->fetchAll(
            "SELECT DATE(created_at) as date, COALESCE(SUM(amount), 0) as total
             FROM transactions WHERE type = 'withdraw' AND status = 'completed' AND currency = :c
             AND created_at >= DATE_SUB(NOW(), INTERVAL :d DAY)
             GROUP BY DATE(created_at) ORDER BY date ASC",
            ['c' => $curr, 'd' => $days]
        );

        return ['deposits' => $deposits, 'withdrawals' => $withdrawals];
    }

    /**
     * تسک‌های تکمیل‌شده روزانه
     */
    public function getDailyCompletedTasks(int $days = 30): array
    {
        return $this->db->fetchAll(
            "SELECT DATE(completed_at) as date, COUNT(*) as count 
             FROM task_executions WHERE status = 'completed'
             AND completed_at >= DATE_SUB(NOW(), INTERVAL :d DAY)
             GROUP BY DATE(completed_at) ORDER BY date ASC",
            ['d' => $days]
        );
    }

    /**
     * تسک‌ها بر اساس پلتفرم (برای نمودار دایره‌ای)
     */
    public function getTasksByPlatform(): array
    {
        return $this->db->fetchAll(
            "SELECT platform, COUNT(*) as count FROM tasks WHERE deleted_at IS NULL GROUP BY platform ORDER BY count DESC"
        );
    }

    /**
     * فعالیت ساعتی (Heat Map)
     */
    public function getHourlyActivity(int $days = 7): array
    {
        return $this->db->fetchAll(
            "SELECT HOUR(created_at) as hour, DAYOFWEEK(created_at) as day_of_week, COUNT(*) as count
             FROM activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL :d DAY)
             GROUP BY HOUR(created_at), DAYOFWEEK(created_at)
             ORDER BY day_of_week, hour",
            ['d' => $days]
        );
    }

    /**
     * Churn Rate (نرخ ریزش)
     */
    public function getChurnRate(int $days = 30): float
    {
        $startActive = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users 
             WHERE last_login >= DATE_SUB(DATE_SUB(NOW(), INTERVAL :d DAY), INTERVAL :d2 DAY) 
             AND last_login < DATE_SUB(NOW(), INTERVAL :d3 DAY)
             AND deleted_at IS NULL",
            ['d' => $days, 'd2' => $days, 'd3' => $days]
        );

        if ($startActive === 0) return 0;

        $churned = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users 
             WHERE last_login >= DATE_SUB(DATE_SUB(NOW(), INTERVAL :d DAY), INTERVAL :d2 DAY) 
             AND last_login < DATE_SUB(NOW(), INTERVAL :d3 DAY)
             AND (last_login < DATE_SUB(NOW(), INTERVAL :d4 DAY) OR last_login IS NULL)
             AND deleted_at IS NULL",
            ['d' => $days, 'd2' => $days, 'd3' => $days, 'd4' => $days]
        );

        return \round(($churned / $startActive) * 100, 1);
    }

    /**
     * Conversion Rate (نرخ تبدیل - ثبت‌نام به اولین تراکنش)
     */
    public function getConversionRate(int $days = 30): float
    {
        $newUsers = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL :d DAY) AND deleted_at IS NULL",
            ['d' => $days]
        );

        if ($newUsers === 0) return 0;

        $converted = (int)$this->db->fetchColumn(
            "SELECT COUNT(DISTINCT u.id) FROM users u
             INNER JOIN transactions t ON t.user_id = u.id AND t.status = 'completed'
             WHERE u.created_at >= DATE_SUB(NOW(), INTERVAL :d DAY) AND u.deleted_at IS NULL",
            ['d' => $days]
        );

        return \round(($converted / $newUsers) * 100, 1);
    }

    /**
     * کاربران برتر (بر اساس درآمد)
     */
    public function getTopUsers(int $limit = 10): array
    {
        return $this->db->fetchAll(
            "SELECT u.id, u.full_name, u.email, u.tier_level,
                    COALESCE(SUM(CASE WHEN t.type = 'task_reward' THEN t.amount ELSE 0 END), 0) as task_earnings,
                    COALESCE(SUM(CASE WHEN t.type = 'commission' THEN t.amount ELSE 0 END), 0) as commission_earnings,
                    COUNT(t.id) as transaction_count
             FROM users u
             LEFT JOIN transactions t ON t.user_id = u.id AND t.status = 'completed' AND t.type IN ('task_reward','commission')
             WHERE u.deleted_at IS NULL
             GROUP BY u.id
             ORDER BY (task_earnings + commission_earnings) DESC
             LIMIT :lmt",
            ['lmt' => $limit]
        );
    }

    /**
     * خلاصه داشبورد اصلی
     */
    public function getDashboardSummary(): array
    {
        return $this->cache->remember('kpi_dashboard_summary', $this->cacheTtl, function () {
            return [
                'users'           => $this->getUserStats(),
                'financial'       => $this->getFinancialStats(),
                'tasks'           => $this->getTaskStats(),
                'tickets'         => $this->getTicketStats(),
                'fraud'           => $this->getFraudStats(),
                'churn_rate'      => $this->getChurnRate(),
                'conversion_rate' => $this->getConversionRate(),
            ];
        });
    }

    /**
     * پاک‌سازی cache KPI (بعد از تغییرات مهم)
     */
    public function clearCache(): void
    {
        $this->cache->forget('kpi_dashboard_summary');
    }

    /**
     * دریافت ارز فعال سیستم
     */
    protected function getActiveCurrency(): string
    {
        try {
            $mode = $this->db->fetchColumn(
                "SELECT value FROM system_settings WHERE `key` = 'currency_mode'"
            );
            return $mode === 'usdt' ? 'usdt' : 'irt';
        } catch (\Throwable $e) {
            return 'irt';
        }
    }
}