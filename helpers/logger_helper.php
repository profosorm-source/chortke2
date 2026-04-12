<?php

/**
 * logger_helper.php — توابع کمکی لاگ
 *
 * ─── سلسله‌مراتب صحیح ────────────────────────────────────────────────────────
 *  Core\Logger (کلاس اصلی، DI-injected در سرویس‌ها)
 *      └─→ logger()  (shortcut برای کدهایی که DI ندارند: helper، cron، controller)
 *
 * ─── قانون ───────────────────────────────────────────────────────────────────
 *  در سرویس‌ها: همیشه $this->logger->xxx() — inject شده از Container
 *  در helper / controller / cron: logger()->xxx() قابل قبول است
 *
 * ─── log_activity ─────────────────────────────────────────────────────────────
 *  ثبت فعالیت کاربر در جدول activity_logs (برای audit بالاتر از AuditTrail)
 *  امضا: log_activity(string $action, string $description, ?int $userId, array $metadata)
 */

if (!function_exists('logger')) {
    /**
     * دسترسی سریع به Logger singleton
     * @return \Core\Logger
     */
    function logger(): \Core\Logger
    {
        return \Core\Logger::getInstance();
    }
}

if (!function_exists('log_activity')) {
    /**
     * ثبت فعالیت کاربر در جدول activity_logs
     *
     * @param string   $action      کلید عمل (مثل 'wallet.deposit')
     * @param string   $description توضیح فارسی
     * @param int|null $userId      شناسه کاربر
     * @param array    $metadata    اطلاعات اضافی
     */
    function log_activity(
        string $action,
        string $description,
        ?int   $userId   = null,
        array  $metadata = []
    ): void {
        try {
            $ipAddress = get_client_ip();
            if (!filter_var($ipAddress, FILTER_VALIDATE_IP)) {
                $ipAddress = null;
            }
            $userAgent = get_user_agent() ?: ($_SERVER['HTTP_USER_AGENT'] ?? '');

            db()->query(
                "INSERT INTO activity_logs
                    (user_id, action, description, metadata, ip_address, user_agent, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $userId,
                    $action,
                    $description,
                    json_encode($metadata, JSON_UNESCAPED_UNICODE),
                    $ipAddress,
                    mb_substr((string) $userAgent, 0, 300),
                    date('Y-m-d H:i:s'),
                    date('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $e) {
            error_log('log_activity failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('log_error_advanced')) {
    /**
     * ثبت خطا در جدول error_logs با گروه‌بندی هوشمند
     *
     * @param string          $message
     * @param string          $level    ERROR|WARNING|CRITICAL
     * @param \Throwable|null $exception
     * @param array           $context
     */
    function log_error_advanced(
        string     $message,
        string     $level     = 'ERROR',
        ?\Throwable $exception = null,
        array      $context   = []
    ): void {
        try {
            $db          = db();
            $tableExists = $db->query("SHOW TABLES LIKE 'error_logs'")->fetch();
            if (!$tableExists) {
                return;
            }

            $userId = null;
            try {
                $userId = \Core\Session::getInstance()->get('user_id');
            } catch (\Throwable) {}

            $errorService = new \App\Services\ErrorLogService($db);
            $errorService->logError($level, $message, $exception, $userId, $context);
        } catch (\Throwable $e) {
            error_log('log_error_advanced failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('send_alert')) {
    /**
     * ارسال هشدار به ادمین‌ها
     */
    function send_alert(string $title, string $message, string $severity = 'medium'): void
    {
        try {
            $notificationService = new \App\Services\LogNotificationService(db());
            $notificationService->sendAlert($title, $message, $severity);
        } catch (\Throwable $e) {
            error_log('send_alert failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('dd')) {
    function dd(mixed ...$vars): never
    {
        echo '<pre style="background:#1e1e1e;color:#ddd;padding:20px;direction:ltr;text-align:left;">';
        foreach ($vars as $var) {
            var_dump($var);
        }
        echo '</pre>';
        exit(1);
    }
}
