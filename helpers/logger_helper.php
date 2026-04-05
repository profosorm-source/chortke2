<?php

if (!function_exists('dd')) {
    function dd(...$vars)
    {
        echo '<pre style="background: #1e1e1e; color: #ddd; padding: 20px; direction: ltr; text-align: left;">';
        foreach ($vars as $var) {
            var_dump($var);
        }
        echo '</pre>';
        die(1);
    }
}

function logger($a = null, $b = [], $c = 'INFO')
{
    static $logger = null;

    if ($logger === null) {
        $logger = new class {
            private string $logDir;

            public function __construct()
            {
                $this->logDir = dirname(__DIR__) . '/storage/logs/';
                if (!is_dir($this->logDir)) {
                    mkdir($this->logDir, 0755, true);
                }
            }

            public function info(string $message, array $context = []): void
            {
                $this->log('INFO', $message, $context);
            }

            public function warning(string $message, array $context = []): void
            {
                $this->log('WARNING', $message, $context);
            }

            public function error(string $message, array $context = []): void
            {
                $this->log('ERROR', $message, $context);
            }

            public function debug(string $message, array $context = []): void
            {
                $this->log('DEBUG', $message, $context);
            }

            public function log(string $level, string $message, array $context = []): void
            {
                $level = strtoupper($level);
                $timestamp = date('Y-m-d H:i:s');
                $logFile = $this->logDir . date('Y-m-d') . '.log';

                $contextStr = '';
                if (!empty($context)) {
                    $contextStr = ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                $line = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;
                file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
            }
        };
    }

    if ($a === null) {
        return $logger;
    }

    $levels = ['INFO','ERROR','WARNING','DEBUG'];

    if (is_string($a) && in_array(strtoupper($a), $levels, true)) {
        $level = strtoupper($a);
        $message = is_string($b) ? $b : 'No message';

        if (is_array($c)) {
            $context = $c;
        } else {
            $context = ['context' => (string)$c];
        }

        $logger->log($level, $message, $context);
        return null;
    }

    if (is_string($a) && is_array($b)) {
        $message = $a;
        $context = $b;
        $level = is_string($c) ? strtoupper($c) : 'INFO';

        $logger->log($level, $message, $context);
        return null;
    }

    if (is_string($a) && $b === []) {
        $logger->log('INFO', $a, []);
        return null;
    }

    $logger->log('WARNING', 'Unknown logger call signature', [
        'args' => func_get_args()
    ]);
    return null;
}

if (!function_exists('log_error_advanced')) {
    function log_error_advanced(
        string $message,
        string $level = 'ERROR',
        ?\Throwable $exception = null,
        array $context = []
    ): void {
        try {
            $db = \Core\Database::getInstance();
            $tableExists = $db->query("SHOW TABLES LIKE 'error_logs'")->fetch();
            if (!$tableExists) return;

            require_once __DIR__ . '/../app/Services/ErrorLogService.php';
            $errorService = new \App\Services\ErrorLogService($db);
            
            $userId = null;
            try {
                $session = \Core\Session::getInstance();
                $userId = $session->get('user_id');
            } catch (\Throwable $e) {}

            $errorService->logError($level, $message, $exception, $userId, $context);
        } catch (\Throwable $e) {
            error_log("Advanced logging failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('send_alert')) {
    function send_alert(string $title, string $message, string $severity = 'medium'): void
    {
        try {
            $db = \Core\Database::getInstance();
            require_once __DIR__ . '/../app/Services/LogNotificationService.php';
            $notificationService = new \App\Services\LogNotificationService($db);
            $notificationService->sendAlert($title, $message, $severity);
        } catch (\Throwable $e) {
            error_log("Alert send failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('log_performance_start')) {
    function log_performance_start(): float
    {
        return microtime(true);
    }
}

if (!function_exists('log_performance_end')) {
    function log_performance_end(float $startTime, string $operationName): void
    {
        $duration = (microtime(true) - $startTime) * 1000;
        
        if ($duration > 1000) {
            log_error_advanced(
                "عملیات کند: {$operationName}",
                'WARNING',
                null,
                ['duration_ms' => $duration]
            );
        }
    }
}

if (!function_exists('log_activity')) {
    function log_activity(
        string $action,
        string $description,
        ?int $userId = null,
        array $metadata = [],
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): void {
        try {
            \Core\Session::getInstance();

            $ipAddress = $ipAddress ?: get_client_ip();
            $ipAddress = (string)$ipAddress;

            if (!\filter_var($ipAddress, FILTER_VALIDATE_IP)) {
                $ipAddress = get_client_ip();
            }

            $userAgent = $userAgent ?: (get_user_agent() ?: ($_SERVER['HTTP_USER_AGENT'] ?? ''));
            $userAgent = (string)$userAgent;

            db()->query(
                "INSERT INTO activity_logs (user_id, action, description, metadata, ip_address, user_agent, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $userId,
                    $action,
                    $description,
                    \json_encode($metadata, JSON_UNESCAPED_UNICODE),
                    $ipAddress,
                    $userAgent,
                    \date('Y-m-d H:i:s'),
                    \date('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $e) {
            \error_log('log_activity failed: ' . $e->getMessage());
        }
    }
}
