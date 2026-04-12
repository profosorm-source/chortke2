<?php

namespace App\Middleware;

use Core\Request;
use Core\Response;

/**
 * Middleware برای لاگ خودکار Performance و خطاها
 */
class LoggingMiddleware
{
    private static float $startTime;
    private static int $startMemory;

    /**
     * قبل از اجرای Controller
     */
    public function handle(Request $request, callable $next): Response
    {
        // شروع زمان‌سنجی
        self::$startTime = microtime(true);
        self::$startMemory = memory_get_usage();

        try {
            // اجرای درخواست
            $response = $next($request);
            
            // ثبت Performance موفق
            $this->logPerformance($request, $response->getStatusCode());
            
            return $response;

        } catch (\Throwable $e) {
            // خطا خودکار توسط ExceptionHandler لاگ میشه
            // فقط Performance رو ثبت می‌کنیم
            $this->logPerformance($request, 500);
            
            throw $e;
        }
    }

    /**
     * ثبت Performance
     */
    private function logPerformance(Request $request, int $statusCode): void
    {
        try {
            // بررسی وجود جدول
            $db = \Core\Database::getInstance();
            $tableExists = $db->query("SHOW TABLES LIKE 'performance_logs'")->fetch();
            
            if (!$tableExists) {
                return; // جدول نیست، بی‌خیال
            }

            $executionTime = (microtime(true) - self::$startTime) * 1000; // ms
            $memoryUsage = memory_get_usage() - self::$startMemory;
            $isSlow = $executionTime > 1000; // بیش از 1 ثانیه

            $userId = null;
            try {
                $session = \Core\Session::getInstance();
                $userId = $session->get('user_id');
            } catch (\Throwable $e) {}

            $db->query(
                "INSERT INTO performance_logs 
                (endpoint, method, execution_time, memory_usage, status_code, 
                 user_id, ip_address, is_slow, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $request->getPath(),
                    $request->getMethod(),
                    $executionTime,
                    $memoryUsage,
                    $statusCode,
                    $userId,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $isSlow ? 1 : 0
                ]
            );

        } catch (\Throwable $e) {
            // Silent fail
            error_log("Performance logging failed: " . $e->getMessage());
        }
    }
}
