<?php

namespace App\Services;

use Core\Database;

/**
 * AdminDashboardService
 *
 * تمام منطق داشبورد ادمین اینجاست:
 *   - getDashboardData()   → داده‌های صفحه اصلی (index)
 *   - getRecentActivity()  → فعالیت‌های اخیر + خلاصه وضعیت کاربر
 *   - getSystemStatus()    → سرویس‌ها، درگاه پرداخت، صف ایمیل، منابع سرور
 */
class AdminDashboardService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ══════════════════════════════════════════════════════════
    // داده‌های صفحه اصلی داشبورد
    // ══════════════════════════════════════════════════════════

    public function getDashboardData(int $userId): array
    {
        $cacheKey = 'admin_dashboard_' . $userId;
        $cached = \Core\Cache::getInstance()->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $userStats = $this->db->query("
            SELECT
                COUNT(*) AS total_users,
                SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_users,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_users,
                SUM(CASE WHEN status = 'banned' THEN 1 ELSE 0 END) AS banned_users
            FROM users
            WHERE deleted_at IS NULL
        ")->fetch();

        $taskStats = $this->db->query("
            SELECT
                COUNT(*) AS total_tasks,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_tasks
            FROM advertisements
        ")->fetch();

        $financialStats = $this->db->query("
            SELECT
                COALESCE(SUM(CASE WHEN type='deposit' AND status='completed' AND MONTH(created_at)=MONTH(NOW()) THEN amount ELSE 0 END),0) AS monthly_revenue,
                COALESCE(SUM(CASE WHEN type='deposit' AND status='completed' THEN amount ELSE 0 END),0) AS total_revenue
            FROM transactions
        ")->fetch();

        $walletStats = $this->db->query("
            SELECT COALESCE(SUM(balance_irt),0) AS total_wallet_balance FROM wallets
        ")->fetch();

        $ticketStats = $this->db->query("
            SELECT
                SUM(CASE WHEN status IN ('open','pending') THEN 1 ELSE 0 END) AS open_tickets,
                SUM(CASE WHEN priority='urgent' AND status NOT IN ('closed','resolved') THEN 1 ELSE 0 END) AS urgent_tickets
            FROM tickets
        ")->fetch();

        $kycStats = $this->db->query("
            SELECT SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_kyc FROM kyc_verifications
        ")->fetch();

        $withdrawalStats = $this->db->query("
            SELECT COUNT(*) AS pending_withdrawals, COALESCE(SUM(amount),0) AS pending_withdrawal_amount
            FROM withdrawals WHERE status='pending'
        ")->fetch();

        $stats = [
            'total_users'               => $userStats->total_users               ?? 0,
            'today_users'               => $userStats->today_users               ?? 0,
            'active_users'              => $userStats->active_users              ?? 0,
            'banned_users'              => $userStats->banned_users              ?? 0,
            'total_tasks'               => $taskStats->total_tasks               ?? 0,
            'active_tasks'              => $taskStats->active_tasks              ?? 0,
            'monthly_revenue'           => $financialStats->monthly_revenue      ?? 0,
            'total_revenue'             => $financialStats->total_revenue        ?? 0,
            'total_wallet_balance'      => $walletStats->total_wallet_balance    ?? 0,
            'open_tickets'              => $ticketStats->open_tickets            ?? 0,
            'urgent_tickets'            => $ticketStats->urgent_tickets          ?? 0,
            'pending_kyc'               => $kycStats->pending_kyc               ?? 0,
            'pending_withdrawals'       => $withdrawalStats->pending_withdrawals ?? 0,
            'pending_withdrawal_amount' => $withdrawalStats->pending_withdrawal_amount ?? 0,
        ];

        // نمودار ۳۰ روز
        $chartRows = $this->db->query("
            SELECT DATE(created_at) AS d, COUNT(*) AS cnt
            FROM users
            WHERE deleted_at IS NULL AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
        ")->fetchAll();
        $chartMap = [];
        foreach ($chartRows as $row) { $chartMap[$row->d] = (int)$row->cnt; }
        $chartData = [];
        for ($i = 29; $i >= 0; $i--) {
            $chartData[] = $chartMap[date('Y-m-d', strtotime("-{$i} days"))] ?? 0;
        }

        $recentUsers = $this->db->query("
            SELECT u.*, COALESCE(w.balance_irt, 0) AS wallet_balance
            FROM users u
            LEFT JOIN wallets w ON w.user_id = u.id
            WHERE u.deleted_at IS NULL
            ORDER BY u.created_at DESC LIMIT 8
        ")->fetchAll();

        $pendingWithdrawalsList = $this->db->query("
            SELECT wr.*, u.full_name, u.email, bc.bank_name
            FROM withdrawals wr
            LEFT JOIN users u ON u.id = wr.user_id
            LEFT JOIN user_bank_cards bc ON bc.id = wr.card_id
            WHERE wr.status = 'pending'
            ORDER BY wr.created_at ASC LIMIT 5
        ")->fetchAll();

        $recentActivities = $this->db->query("
            SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 8
        ")->fetchAll();

        $currentUser = $this->db->query(
            "SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]
        )->fetch();
        
        $adminAccessLog = $this->getAdminAccessLog(10);

        $result = compact(
            'stats', 'chartData', 'recentUsers',
            'pendingWithdrawalsList', 'recentActivities', 'currentUser', 'adminAccessLog'
        );
        \Core\Cache::getInstance()->set($cacheKey, $result, 120);
        return $result;
    }

    // ══════════════════════════════════════════════════════════
    // ورود و خروج مدیران اخیر
    // ══════════════════════════════════════════════════════════

    public function getAdminAccessLog(int $limit = 10): array
    {
        try {
            $logs = $this->db->query("
                SELECT
                    al.id,
                    al.user_id,
                    al.action,
                    al.description,
                    al.ip_address,
                    al.created_at,
                    u.full_name,
                    u.email,
                    u.role
                FROM activity_logs al
                INNER JOIN users u ON u.id = al.user_id
                WHERE u.role IN ('admin', 'support')
                  AND al.action IN ('login', 'logout', 'admin_login', 'admin_logout', 'login_success', 'login_failed')
                  AND al.deleted_at IS NULL
                ORDER BY al.created_at DESC
                LIMIT ?
            ", [$limit])->fetchAll();

            return array_map(function ($log) {
                $action  = $log->action ?? '';
                $isLogin = str_contains($action, 'login');
                return [
                    'id'          => $log->id,
                    'user_id'     => $log->user_id,
                    'type'        => $action,
                    'is_login'    => $isLogin,
                    'description' => $log->description,
                    'full_name'   => $log->full_name,
                    'email'       => $log->email,
                    'role'        => $log->role,
                    'ip_address'  => $log->ip_address,
                    'created_at'  => $log->created_at,
                    'time_ago'    => $this->timeAgo($log->created_at),
                ];
            }, $logs);
        } catch (\Throwable $e) {
            logger()->error('Failed to get admin access log', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ══════════════════════════════════════════════════════════
    // احراز هویت ادمین
    // ══════════════════════════════════════════════════════════

    public function attemptLogin(string $email, string $password): ?array
    {
        $user = $this->db->query(
            "SELECT * FROM users WHERE email = ? AND role = 'admin' LIMIT 1", [$email]
        )->fetch();

        if (!$user || !password_verify($password, $user->password)) {
            return null;
        }

        $this->db->query("UPDATE users SET last_login = NOW() WHERE id = ?", [$user->id]);

        return ['id' => $user->id, 'role' => $user->role];
    }

    // ══════════════════════════════════════════════════════════
    // آخرین فعالیت‌های کاربران + خلاصه وضعیت
    // ══════════════════════════════════════════════════════════

    public function getRecentActivity(string $type = 'all', int $limit = 20, int $page = 1): array
    {
        $allowedTypes = ['all', 'task', 'ad', 'withdraw', 'register', 'kyc', 'card', 'login', 'deposit'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'all';
        }

        $offset    = ($page - 1) * $limit;
        $whereType = $type !== 'all' ? "AND al.type = '{$type}'" : '';

        $rows = $this->db->query("
            SELECT
                al.id,
                al.user_id,
                al.type,
                al.description,
                al.meta,
                al.created_at,

                u.full_name      AS user_name,
                u.email          AS user_email,
                u.status         AS user_status,
                u.kyc_status,
                u.created_at     AS user_created_at,

                (SELECT COUNT(*) FROM task_executions te
                    WHERE te.user_id = u.id AND te.status = 'approved')
                    AS task_count,

                (SELECT COUNT(*) FROM task_executions te2
                    WHERE te2.user_id = u.id AND te2.status = 'rejected')
                    AS rejected_task_count,

                (SELECT COALESCE(w.balance_irt, 0) FROM wallets w
                    WHERE w.user_id = u.id LIMIT 1)
                    AS wallet_balance,

                (SELECT COUNT(*) FROM advertisements at2
                    WHERE at2.user_id = u.id AND at2.status = 'active')
                    AS active_ads,

                (SELECT ROUND(AVG(
                    CASE WHEN at3.impression_count > 0
                         THEN (at3.click_count / at3.impression_count * 100)
                         ELSE 0 END
                ), 1) FROM advertisements at3
                    WHERE at3.user_id = u.id AND at3.status IN ('active','completed'))
                    AS avg_ctr,

                (SELECT COUNT(*) FROM users ref WHERE ref.referred_by = u.id)
                    AS referral_count,

                (SELECT COUNT(*) FROM withdrawals wd
                    WHERE wd.user_id = u.id AND wd.status = 'approved')
                    AS withdraw_count,

                (SELECT bc.card_number FROM user_bank_cards bc
                    WHERE bc.user_id = u.id AND bc.is_default = 1 LIMIT 1)
                    AS default_card,

                (SELECT bc2.bank_name FROM user_bank_cards bc2
                    WHERE bc2.user_id = u.id AND bc2.is_default = 1 LIMIT 1)
                    AS default_bank,

                (SELECT COUNT(*) FROM user_bank_cards bc3
                    WHERE bc3.user_id = u.id)
                    AS card_count

            FROM activity_logs al
            INNER JOIN users u ON u.id = al.user_id
            WHERE 1=1 {$whereType}
            ORDER BY al.created_at DESC
            LIMIT {$limit} OFFSET {$offset}
        ")->fetchAll();

        $statsRow = $this->db->query("
            SELECT
                SUM(CASE WHEN type = 'task'     THEN 1 ELSE 0 END) AS task_count,
                SUM(CASE WHEN type = 'ad'       THEN 1 ELSE 0 END) AS ad_count,
                SUM(CASE WHEN type = 'withdraw' THEN 1 ELSE 0 END) AS withdraw_count,
                SUM(CASE WHEN type = 'register' THEN 1 ELSE 0 END) AS register_count,
                SUM(CASE WHEN type = 'kyc'      THEN 1 ELSE 0 END) AS kyc_count,
                COUNT(*)                                             AS total
            FROM activity_logs
            WHERE created_at >= CURDATE()
        ")->fetch();

        $items = array_map(fn($row) => [
            'id'          => $row->id,
            'type'        => $row->type,
            'description' => $row->description,
            'created_at'  => $row->created_at,
            'time_ago'    => $this->timeAgo($row->created_at),
            'meta'        => !empty($row->meta) ? json_decode($row->meta, true) : [],
            'full_name'   => $row->user_name,
            'email'       => $row->user_email,
            'avatar_url'  => '', // می‌تونید بعداً از جدول users بگیرید
            'summary'     => $this->buildUserSummary((array)$row),
            'user'        => [
                'id'           => $row->user_id,
                'name'         => $row->user_name,
                'email'        => $row->user_email,
                'status'       => $row->user_status,
                'kyc_status'   => $row->kyc_status,
                'member_since' => $row->user_created_at,
            ],
        ], $rows);

        return ['items' => $items, 'stats' => $statsRow];
    }

    // ══════════════════════════════════════════════════════════
    // وضعیت سیستم
    // ══════════════════════════════════════════════════════════

    public function getSystemStatus(): array
    {
        return [
            'services'      => $this->getServicesStatus(),
            'cron_jobs'     => $this->getCronJobsStatus(),
            'payment_gates' => $this->getPaymentGateStatus(),
            'email_queue'   => $this->getEmailQueueStats(),
            'resources'     => $this->getServerResources(),
        ];
    }

    // ──────────────────────────────────────────────────────────
    // Private: وضعیت سرویس‌ها
    // ──────────────────────────────────────────────────────────

    private function getServicesStatus(): array
    {
        $services = [];

        $services[] = ['name' => 'وب‌سرور', 'key' => 'webserver',
                       'status' => 'online', 'label' => 'آنلاین', 'pulse' => true];

        try {
            $this->db->query("SELECT 1")->fetch();
            $services[] = ['name' => 'پایگاه داده', 'key' => 'database',
                           'status' => 'online', 'label' => 'سالم', 'pulse' => false];
        } catch (\Throwable) {
            $services[] = ['name' => 'پایگاه داده', 'key' => 'database',
                           'status' => 'error', 'label' => 'خطا', 'pulse' => false];
        }

        // ── Redis: بر اساس Cache singleton ──
        $cache = \Core\Cache::getInstance();
        if ($cache->driver() === 'redis') {
            try {
                $redis   = $cache->redis();
                $info    = $redis->info('server');
                $version = $info['redis_version'] ?? '?';
                $services[] = [
                    'name'   => 'Redis Cache',
                    'key'    => 'redis',
                    'status' => 'online',
                    'label'  => "فعال (v{$version})",
                    'pulse'  => true,
                    'hint'   => null,
                ];
            } catch (\Throwable $e) {
                $services[] = [
                    'name'   => 'Redis Cache',
                    'key'    => 'redis',
                    'status' => 'error',
                    'label'  => 'اتصال قطع شد',
                    'pulse'  => false,
                    'hint'   => 'Redis نصب است ولی اتصال برقرار نشد. سرویس Redis را بررسی کنید.',
                ];
            }
        } elseif (strtolower((string) env('REDIS_ENABLED', 'true')) === 'false') {
            $services[] = [
                'name'   => 'Redis Cache',
                'key'    => 'redis',
                'status' => 'info',
                'label'  => 'غیرفعال',
                'pulse'  => false,
                'hint'   => 'Redis در .env غیرفعال است (REDIS_ENABLED=false). سیستم از کش فایلی استفاده می‌کند.',
            ];
        } elseif (!extension_loaded('redis')) {
            $phpIni = php_ini_loaded_file() ?: 'php.ini';
            $services[] = [
                'name'   => 'Redis Cache',
                'key'    => 'redis',
                'status' => 'info',
                'label'  => 'استفاده از کش فایلی',
                'pulse'  => false,
                'hint'   => 'PHP extension ردیس نصب نیست — سیستم به‌طور خودکار از کش فایلی استفاده می‌کند و عملکرد عادی دارد. '
                          . 'برای فعال‌سازی Redis در XAMPP: php_redis.dll را از pecl.php.net دانلود و در ' . $phpIni . ' فعال کنید.',
            ];
        } else {
            $host = env('REDIS_HOST', '127.0.0.1');
            $port = env('REDIS_PORT', 6379);
            $services[] = [
                'name'   => 'Redis Cache',
                'key'    => 'redis',
                'status' => 'error',
                'label'  => 'خطا در اتصال',
                'pulse'  => false,
                'hint'   => "Extension نصب است ولی اتصال به {$host}:{$port} ناموفق بود. "
                          . 'سرویس Redis را راه‌اندازی کنید یا REDIS_HOST/PORT را در .env بررسی کنید.',
            ];
        }

        try {
            $qRow    = $this->db->query("
                SELECT COUNT(*) AS cnt FROM email_queue
                WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
            ")->fetch();
            $qStatus = (int)($qRow->cnt ?? 0) > 0 ? 'online' : 'warning';
            $services[] = ['name' => 'Queue Worker', 'key' => 'queue',
                           'status' => $qStatus, 'label' => $qStatus === 'online' ? 'فعال' : 'بی‌فعالیت',
                           'pulse' => $qStatus === 'online'];
        } catch (\Throwable) {
            $services[] = ['name' => 'Queue Worker', 'key' => 'queue',
                           'status' => 'warning', 'label' => 'نامشخص', 'pulse' => false];
        }

        try {
            $smsRow = $this->db->query("
                SELECT created_at FROM activity_logs
                WHERE type = 'sms_sent' AND status = 'success'
                ORDER BY created_at DESC LIMIT 1
            ")->fetch();
            if (!$smsRow) {
                $sStatus = 'warning'; $sLabel = 'نامشخص';
            } else {
                $diffMin = (time() - strtotime($smsRow->created_at)) / 60;
                if ($diffMin < 30)      { $sStatus = 'online';  $sLabel = 'فعال'; }
                elseif ($diffMin < 120) { $sStatus = 'warning'; $sLabel = 'تاخیر'; }
                else                    { $sStatus = 'error';   $sLabel = 'خطا'; }
            }
            $services[] = ['name' => 'سرویس SMS', 'key' => 'sms',
                           'status' => $sStatus, 'label' => $sLabel, 'pulse' => false];
        } catch (\Throwable) {
            $services[] = ['name' => 'سرویس SMS', 'key' => 'sms',
                           'status' => 'warning', 'label' => 'نامشخص', 'pulse' => false];
        }

        return $services;
    }

    // ──────────────────────────────────────────────────────────
    // Private: وضعیت Cron Jobs
    // ──────────────────────────────────────────────────────────

    private function getCronJobsStatus(): array
    {
        $cronJobs = [
            [
                'name'        => 'پردازش صف ایمیل',
                'key'         => 'email_queue',
                'schedule'    => 'هر 5 دقیقه',
                'description' => 'ارسال ایمیل‌های در صف انتظار',
            ],
            [
                'name'        => 'پردازش سود سرمایه‌گذاری',
                'key'         => 'investment_profit',
                'schedule'    => 'روزانه (00:05)',
                'description' => 'محاسبه و واریز سود سرمایه‌گذاری‌ها',
            ],
            [
                'name'        => 'قرعه‌کشی لاتاری',
                'key'         => 'lottery_draw',
                'schedule'    => 'روزانه (23:55)',
                'description' => 'برگزاری قرعه‌کشی روزانه',
            ],
            [
                'name'        => 'تسویه کمیسیون معرفی',
                'key'         => 'referral_commission',
                'schedule'    => 'روزانه (01:00)',
                'description' => 'واریز کمیسیون‌های معرفی',
            ],
            [
                'name'        => 'به‌روزرسانی آمار تبلیغات',
                'key'         => 'ad_stats',
                'schedule'    => 'هر 15 دقیقه',
                'description' => 'بروزرسانی نرخ کلیک و بازدید',
            ],
            [
                'name'        => 'پاکسازی لاگ‌های قدیمی',
                'key'         => 'cleanup_logs',
                'schedule'    => 'هفتگی (یکشنبه 02:00)',
                'description' => 'حذف لاگ‌های بیش از 90 روز',
            ],
        ];

        foreach ($cronJobs as &$job) {
            try {
                // چک کردن آخرین اجرای موفق از activity_logs
                $lastRun = $this->db->query("
                    SELECT created_at, metadata 
                    FROM activity_logs 
                    WHERE action = 'cron' 
                    AND description LIKE ?
                    ORDER BY created_at DESC 
                    LIMIT 1
                ", ['%' . $job['key'] . '%'])->fetch();

                if (!$lastRun) {
                    $job['status']       = 'warning';
                    $job['label']        = 'اجرا نشده';
                    $job['last_run']     = null;
                    $job['last_run_ago'] = 'هرگز';
                    $job['pulse']        = false;
                    continue;
                }

                $lastRunTime = strtotime($lastRun->created_at);
                $diffMinutes = (time() - $lastRunTime) / 60;
                
                // تعیین وضعیت بر اساس schedule
                $expectedInterval = match($job['key']) {
                    'email_queue', 'ad_stats' => 15,      // هر 15 دقیقه
                    'investment_profit', 'lottery_draw', 'referral_commission' => 1440,  // روزانه
                    'cleanup_logs' => 10080,  // هفتگی
                    default => 60,
                };

                if ($diffMinutes < $expectedInterval * 1.5) {
                    $job['status'] = 'online';
                    $job['label']  = 'فعال';
                    $job['pulse']  = true;
                } elseif ($diffMinutes < $expectedInterval * 3) {
                    $job['status'] = 'warning';
                    $job['label']  = 'تاخیر';
                    $job['pulse']  = false;
                } else {
                    $job['status'] = 'error';
                    $job['label']  = 'متوقف شده';
                    $job['pulse']  = false;
                }

                $job['last_run']     = $lastRun->created_at;
                $job['last_run_ago'] = $this->timeAgo($lastRun->created_at);
                
                // دریافت اطلاعات اجرا از meta
                $meta = !empty($lastRun->metadata) ? json_decode($lastRun->metadata, true) : [];
                $job['execution_time'] = $meta['execution_time'] ?? null;
                $job['items_processed'] = $meta['items_processed'] ?? null;

            } catch (\Throwable $e) {
                $job['status']       = 'error';
                $job['label']        = 'خطا';
                $job['last_run']     = null;
                $job['last_run_ago'] = 'نامشخص';
                $job['pulse']        = false;
            }
        }

        return $cronJobs;
    }

    // ──────────────────────────────────────────────────────────
    // Private: درگاه‌های پرداخت
    // ──────────────────────────────────────────────────────────

    private function getPaymentGateStatus(): array
    {
        $labels   = ['zarinpal' => 'زرین‌پال', 'idpay' => 'ایدی پی',
                     'nextpay'  => 'نکست‌پی',  'payir' => 'پی‌آیر', 'vandar' => 'وندار'];
        $pingUrls = [
            'zarinpal' => 'https://api.zarinpal.com/pg/v4/payment/request.json',
            'idpay'    => 'https://api.idpay.ir/v1.1/payment',
            'nextpay'  => 'https://nextpay.org/nx/gateway/token',
            'payir'    => 'https://pay.ir/pg/send',
            'vandar'   => 'https://ipg.vandar.io/api/v3/send',
        ];

        try {
            $activeGates = $this->db->query(
                "SELECT gateway_name FROM payment_gateways WHERE is_active = 1 ORDER BY sort_order ASC"
            )->fetchAll();
        } catch (\Throwable) {
            $activeGates = [(object)['gateway_name' => 'zarinpal'], (object)['gateway_name' => 'idpay']];
        }

        $gates = [];
        foreach ($activeGates as $gate) {
            $key  = $gate->gateway_name;
            $ping = isset($pingUrls[$key]) ? $this->pingUrl($pingUrls[$key]) : null;

            try {
                // تراکنش‌های امروز
                $txRow    = $this->db->query(
                    "SELECT COUNT(*) AS cnt FROM transactions
                     WHERE gateway = ? AND status = 'completed' AND created_at >= CURDATE()", [$key]
                )->fetch();
                $todayTxn = (int)($txRow->cnt ?? 0);
                
                // تراکنش‌های ناموفق امروز
                $failedRow = $this->db->query(
                    "SELECT COUNT(*) AS cnt FROM transactions
                     WHERE gateway = ? AND status = 'failed' AND created_at >= CURDATE()", [$key]
                )->fetch();
                $failedTxn = (int)($failedRow->cnt ?? 0);
                
                // آخرین تراکنش موفق
                $lastSuccess = $this->db->query(
                    "SELECT created_at FROM transactions
                     WHERE gateway = ? AND status = 'completed'
                     ORDER BY created_at DESC LIMIT 1", [$key]
                )->fetch();
                
                // مبلغ کل امروز
                $amountRow = $this->db->query(
                    "SELECT COALESCE(SUM(amount), 0) AS total FROM transactions
                     WHERE gateway = ? AND status = 'completed' AND created_at >= CURDATE()", [$key]
                )->fetch();
                $totalAmount = (int)($amountRow->total ?? 0);
                
            } catch (\Throwable) {
                $todayTxn    = 0;
                $failedTxn   = 0;
                $lastSuccess = null;
                $totalAmount = 0;
            }

            if ($ping === null)   { $status = 'unknown'; $label = 'نامشخص'; }
            elseif ($ping < 300)  { $status = 'online';  $label = 'متصل'; }
            elseif ($ping < 1500) { $status = 'warning'; $label = 'تاخیر'; }
            else                  { $status = 'error';   $label = 'کند'; }
            
            // چک کردن آخرین تراکنش موفق
            if ($lastSuccess && $todayTxn > 0) {
                $lastSuccessMin = (time() - strtotime($lastSuccess->created_at)) / 60;
                if ($lastSuccessMin > 60) {
                    $status = 'warning';
                    $label  = 'بی‌فعالیت';
                }
            }
            
            // نرخ موفقیت
            $totalTxn    = $todayTxn + $failedTxn;
            $successRate = $totalTxn > 0 ? round(($todayTxn / $totalTxn) * 100, 1) : 100;

            $gates[] = [
                'key'          => $key,
                'name'         => $labels[$key] ?? $key,
                'status'       => $status,
                'label'        => $label,
                'ping_ms'      => $ping,
                'txn_today'    => $todayTxn,
                'failed_today' => $failedTxn,
                'success_rate' => $successRate,
                'amount_today' => $totalAmount,
                'last_success' => $lastSuccess ? $this->timeAgo($lastSuccess->created_at) : 'هرگز',
            ];
        }

               

        return $gates;
    }

    // ──────────────────────────────────────────────────────────
    // Private: صف ایمیل
    // ──────────────────────────────────────────────────────────

    private function getEmailQueueStats(): array
    {
        try {
            $queued      = $this->db->query("SELECT COUNT(*) AS cnt FROM email_queue WHERE status = 'pending'")->fetch();
            $sentToday   = $this->db->query("SELECT COUNT(*) AS cnt FROM email_queue WHERE status = 'sent' AND sent_at >= CURDATE()")->fetch();
            $failedToday = $this->db->query("SELECT COUNT(*) AS cnt FROM email_queue WHERE status = 'failed' AND created_at >= CURDATE()")->fetch();
            
            // ایمیل‌های معلق (بیش از 30 دقیقه در صف)
            $stuckEmails = $this->db->query("
                SELECT COUNT(*) AS cnt FROM email_queue 
                WHERE status = 'pending' 
                AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
            ")->fetch();
            
            // ایمیل‌های در حال ارسال (processing)
            $processingEmails = $this->db->query("
                SELECT COUNT(*) AS cnt FROM email_queue 
                WHERE status = 'sending'
            ")->fetch();
            
            // ایمیل‌های شکست خورده اخیر با جزئیات
            $recentFailed = $this->db->query("
                SELECT id, to_email AS recipient, subject, error_message, attempts, created_at
                FROM email_queue 
                WHERE status = 'failed' 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY created_at DESC
                LIMIT 5
            ")->fetchAll();
            
            $rateRow     = $this->db->query("
                SELECT COUNT(*) AS total,
                       SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent
                FROM email_queue
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                  AND status IN ('sent','failed')
            ")->fetch();
            $avgRow      = $this->db->query("
                SELECT ROUND(AVG(TIMESTAMPDIFF(SECOND, created_at, sent_at)), 1) AS avg_sec
                FROM email_queue
                WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ")->fetch();
        } catch (\Throwable $e) {
            logger()->error('Email queue stats failed', ['error' => $e->getMessage()]);
            return [
                'queued' => 0, 'sent_today' => 0, 'failed_today' => 0,
                'stuck' => 0, 'processing' => 0, 'recent_failed' => [],
                'success_rate' => 100, 'avg_delivery' => 0, 'capacity' => 500, 'capacity_pct' => 0
            ];
        }

        $total       = (int)($rateRow->total ?? 0);
        $sent        = (int)($rateRow->sent  ?? 0);
        $rate        = $total > 0 ? round($sent / $total * 100, 1) : 100.0;
        $queuedCount = (int)($queued->cnt ?? 0);
        $maxCapacity = 500;

        return [
            'queued'         => $queuedCount,
            'sent_today'     => (int)($sentToday->cnt   ?? 0),
            'failed_today'   => (int)($failedToday->cnt ?? 0),
            'stuck'          => (int)($stuckEmails->cnt ?? 0),
            'processing'     => (int)($processingEmails->cnt ?? 0),
            'recent_failed'  => array_map(fn($e) => [
                'id'            => $e->id,
                'recipient'     => $e->recipient,
                'subject'       => $e->subject,
                'error_message' => $e->error_message,
                'attempts'      => $e->attempts,
                'created_at'    => $e->created_at,
                'time_ago'      => $this->timeAgo($e->created_at),
            ], $recentFailed),
            'success_rate'   => $rate,
            'avg_delivery'   => (float)($avgRow->avg_sec ?? 0),
            'capacity'       => $maxCapacity,
            'capacity_pct'   => $maxCapacity > 0 ? round($queuedCount / $maxCapacity * 100, 1) : 0,
        ];
    }

    // ──────────────────────────────────────────────────────────
    // Private: منابع سرور
    // ──────────────────────────────────────────────────────────

    private function getServerResources(): array
    {
        // CPU
        $cpuPct = null; $cores = null; $freq = null;
        if (PHP_OS_FAMILY === 'Linux') {
            $s1 = $this->readCpuStat(); usleep(200000); $s2 = $this->readCpuStat();
            if ($s1 && $s2) {
                $dTotal = array_sum($s2) - array_sum($s1);
                $dIdle  = ($s2['idle'] + $s2['iowait']) - ($s1['idle'] + $s1['iowait']);
                $cpuPct = $dTotal > 0 ? round((1 - $dIdle / $dTotal) * 100, 1) : 0.0;
            }
            if (is_readable('/proc/cpuinfo')) {
                $info  = file_get_contents('/proc/cpuinfo');
                $cores = substr_count($info, 'processor');
                if (preg_match('/cpu MHz\s*:\s*([\d.]+)/', $info, $m)) {
                    $freq = round((float)$m[1] / 1000, 1) . ' GHz';
                }
            }
        }
        if ($cpuPct === null && function_exists('sys_getloadavg')) {
            $load   = sys_getloadavg();
            $cpuPct = min(round($load[0] * 10, 1), 100.0);
        }

        // RAM
        $ramPct = 0.0; $ramUsed = 0; $ramTotal = 0;
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/meminfo')) {
            $info    = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/',     $info, $t);
            preg_match('/MemAvailable:\s+(\d+)/', $info, $a);
            $totalKb = (int)($t[1] ?? 0);
            $availKb = (int)($a[1] ?? 0);
            $usedKb  = $totalKb - $availKb;
            $ramPct  = $totalKb > 0 ? round($usedKb / $totalKb * 100, 1) : 0.0;
            $ramUsed  = round($usedKb  / 1048576, 1);
            $ramTotal = round($totalKb / 1048576, 1);
        }

        // GPU — استفاده از روش امن بدون shell_exec
        $gpu = ['label' => 'GPU', 'pct' => null, 'model' => null,
                'vram_gb' => null, 'color' => 'purple', 'available' => false];
        if (PHP_OS_FAMILY === 'Linux') {
            $out = $this->safeGpuInfo();
            if ($out) {
                $parts = array_map('trim', explode(',', trim($out)));
                if (count($parts) >= 4) {
                    $gpu = ['label' => 'GPU', 'pct' => (float)$parts[0],
                            'vram_gb' => round((int)$parts[2] / 1024, 1),
                            'model' => htmlspecialchars(strip_tags($parts[3]), ENT_QUOTES, 'UTF-8'),
                            'color' => 'purple', 'available' => true];
                }
            }
        }

        // Disk — استفاده از توابع PHP بومی + خواندن مستقیم sysfs بدون shell_exec
        $diskPath  = defined('BASE_PATH') ? BASE_PATH : getcwd();
        $diskTotal = disk_total_space($diskPath) ?: 0;
        $diskFree  = disk_free_space($diskPath)  ?: 0;
        $diskUsed  = $diskTotal - $diskFree;
        $diskPct   = $diskTotal > 0 ? round($diskUsed / $diskTotal * 100, 1) : 0.0;
        $diskType  = 'HDD';
        if (PHP_OS_FAMILY === 'Linux') {
            // خواندن مستقیم از sysfs بدون shell_exec — ایمن در برابر command injection
            $nvme = $this->safeReadSysfs('/sys/block/nvme0n1/queue/rotational');
            $sda  = $this->safeReadSysfs('/sys/block/sda/queue/rotational');
            if (trim((string)$nvme) === '0')    $diskType = 'SSD NVMe';
            elseif (trim((string)$sda) === '0') $diskType = 'SSD';
        }

        return [
            'cpu'  => ['label' => 'CPU', 'pct' => $cpuPct ?? 0.0, 'cores' => $cores, 'freq' => $freq,
                       'color' => ($cpuPct ?? 0) > 80 ? 'red' : (($cpuPct ?? 0) > 60 ? 'orange' : 'green')],
            'ram'  => ['label' => 'RAM', 'pct' => $ramPct, 'used_gb' => $ramUsed, 'total_gb' => $ramTotal,
                       'color' => $ramPct > 80 ? 'red' : ($ramPct > 60 ? 'orange' : 'blue')],
            'gpu'  => $gpu,
            'disk' => ['label' => 'هارد', 'pct' => $diskPct,
                       'used_gb'  => round($diskUsed  / 1073741824, 1),
                       'total_gb' => round($diskTotal / 1073741824, 1),
                       'color'    => $diskPct > 85 ? 'red' : ($diskPct > 65 ? 'orange' : 'green'),
                       'type'     => $diskType],
        ];
    }

    // ──────────────────────────────────────────────────────────
    // Private: خلاصه وضعیت کاربر برای activity feed
    // ──────────────────────────────────────────────────────────

    private function buildUserSummary(array $row): array
    {
        $summary   = [];
        $type      = $row['type'];
        $kycStatus = $row['kyc_status'] ?? 'none';

        $kycMap = [
            'verified'  => ['label' => 'KYC تایید',    'color' => 'green',  'icon' => 'verified'],
            'pending'   => ['label' => 'KYC در انتظار', 'color' => 'orange', 'icon' => 'pending'],
            'reviewing' => ['label' => 'KYC در بررسی',  'color' => 'blue',   'icon' => 'search'],
            'rejected'  => ['label' => 'KYC رد شده',    'color' => 'red',    'icon' => 'cancel'],
            'none'      => ['label' => 'KYC ناقص',      'color' => 'orange', 'icon' => 'how_to_reg'],
        ];
        $summary[] = ['type' => 'kyc', ...($kycMap[$kycStatus] ?? $kycMap['none'])];

        if ((int)($row['task_count'] ?? 0) > 0)
            $summary[] = ['type' => 'tasks', 'icon' => 'task_alt',
                          'label' => number_format((int)$row['task_count']) . ' تسک', 'color' => 'default'];

        if ((float)($row['wallet_balance'] ?? 0) > 0)
            $summary[] = ['type' => 'balance', 'icon' => 'account_balance_wallet',
                          'label' => number_format((float)$row['wallet_balance']) . ' تومان', 'color' => 'green'];

        switch ($type) {
            case 'task':
                if ((int)($row['rejected_task_count'] ?? 0) > 0)
                    $summary[] = ['type' => 'rejected', 'icon' => 'block',
                                  'label' => $row['rejected_task_count'] . ' رد شده', 'color' => 'red'];
                if ((int)($row['referral_count'] ?? 0) > 0)
                    $summary[] = ['type' => 'referral', 'icon' => 'share',
                                  'label' => $row['referral_count'] . ' معرفی', 'color' => 'purple'];
                break;
            case 'ad':
                if ((int)($row['active_ads'] ?? 0) > 0)
                    $summary[] = ['type' => 'ads', 'icon' => 'campaign',
                                  'label' => $row['active_ads'] . ' تبلیغ فعال', 'color' => 'default'];
                if ($row['avg_ctr'] !== null)
                    $summary[] = ['type' => 'ctr', 'icon' => 'ads_click',
                                  'label' => 'CTR: ' . $row['avg_ctr'] . '٪', 'color' => 'green'];
                break;
            case 'withdraw':
                if ((int)($row['withdraw_count'] ?? 0) > 0)
                    $summary[] = ['type' => 'withdraw_count', 'icon' => 'payments',
                                  'label' => $row['withdraw_count'] . ' برداشت قبلی', 'color' => 'default'];
                if (!empty($row['default_card']))
                    $summary[] = ['type' => 'card', 'icon' => 'credit_card',
                                  'label' => ($row['default_bank'] ? $row['default_bank'] . ' ' : '')
                                             . '****' . substr($row['default_card'], -4), 'color' => 'blue'];
                break;
            case 'register':
                $summary[] = ['type' => 'new_user', 'icon' => 'fiber_new',
                              'label' => 'کاربر جدید', 'color' => 'green'];
                break;
            case 'kyc':
                $daysAgo   = (int)floor((time() - strtotime($row['user_created_at'])) / 86400);
                $label     = $daysAgo === 0 ? 'امروز' : ($daysAgo === 1 ? 'دیروز' : $daysAgo . ' روز پیش');
                $summary[] = ['type' => 'member', 'icon' => 'calendar_today',
                              'label' => 'عضو از ' . $label, 'color' => 'default'];
                break;
            case 'card':
                if ((int)($row['card_count'] ?? 0) > 0)
                    $summary[] = ['type' => 'card_count', 'icon' => 'credit_card',
                                  'label' => $row['card_count'] . ' کارت ثبت‌شده', 'color' => 'default'];
                break;
        }

        return array_slice($summary, 0, 4);
    }

    // ──────────────────────────────────────────────────────────
    // Private: helpers
    // ──────────────────────────────────────────────────────────

    private function timeAgo(string $datetime): string
    {
        $diff = time() - strtotime($datetime);
        if ($diff < 60)      return 'لحظاتی پیش';
        if ($diff < 3600)    return (int)($diff / 60)    . ' دقیقه پیش';
        if ($diff < 86400)   return (int)($diff / 3600)  . ' ساعت پیش';
        if ($diff < 604800)  return (int)($diff / 86400)  . ' روز پیش';
        if ($diff < 2592000) return (int)($diff / 604800) . ' هفته پیش';
        return (int)($diff / 2592000) . ' ماه پیش';
    }

    private function readCpuStat(): ?array
    {
        if (!is_readable('/proc/stat')) return null;
        $fh   = fopen('/proc/stat', 'r');
        $line = fgets($fh);
        fclose($fh);
        if (!preg_match('/cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $line, $m)) {
            return null;
        }
        return ['user' => (int)$m[1], 'nice' => (int)$m[2], 'system' => (int)$m[3],
                'idle' => (int)$m[4], 'iowait' => (int)$m[5], 'irq' => (int)$m[6], 'softirq' => (int)$m[7]];
    }

    private function pingUrl(string $url): ?int
    {
        $start = microtime(true);
        $ch    = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2,
                                CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_SSL_VERIFYPEER => 0]);
        curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($err) return 9999;
        return (int)round((microtime(true) - $start) * 1000);
    }

    /**
     * دریافت اطلاعات GPU به صورت امن — بدون shell_exec
     * از proc_open با آرگومان‌های ثابت و whitelist شده استفاده می‌کند
     */
    private function safeGpuInfo(): ?string
    {
        // مسیر ثابت nvidia-smi — هیچ ورودی کاربر وارد دستور نمی‌شود
        $nvidiaSmi = '/usr/bin/nvidia-smi';
        if (!is_executable($nvidiaSmi)) {
            // تلاش در مسیر جایگزین
            $nvidiaSmi = '/usr/local/bin/nvidia-smi';
            if (!is_executable($nvidiaSmi)) {
                return null;
            }
        }

        // استفاده از proc_open با آرگومان‌های آرایه‌ای — کاملاً ایمن از command injection
        $cmd = [$nvidiaSmi, '--query-gpu=utilization.gpu,memory.used,memory.total,name', '--format=csv,noheader,nounits'];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1], 512); // محدودیت خواندن
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        // اعتبارسنجی خروجی — فقط اعداد و کاراکترهای مجاز
        $output = trim($output);
        if (!preg_match('/^\d+,\s*\d+,\s*\d+,\s*[\w\s\-]+$/', $output)) {
            return null;
        }

        return $output;
    }

    /**
     * خواندن ایمن فایل‌های sysfs — جایگزین shell_exec('cat ...')
     * فقط مسیرهای whitelist شده مجاز هستند
     */
    private function safeReadSysfs(string $path): ?string
    {
        // whitelist مسیرهای مجاز sysfs
        $allowedPaths = [
            '/sys/block/nvme0n1/queue/rotational',
            '/sys/block/sda/queue/rotational',
            '/sys/block/sdb/queue/rotational',
        ];

        if (!in_array($path, $allowedPaths, true)) {
            return null;
        }

        if (!is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path, false, null, 0, 16); // فقط 16 بایت اول
        if ($content === false) {
            return null;
        }

        // فقط '0' یا '1' معتبر است
        $val = trim($content);
        if (!in_array($val, ['0', '1'], true)) {
            return null;
        }

        return $val;
    }
}