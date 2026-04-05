<?php

declare(strict_types=1);

namespace Core;

use Throwable;
use ErrorException;

class ExceptionHandler
{
    /**
     * ثبت Handler برای خطاها و Exception ها
     */
    public static function register(): void
    {
        // تبدیل خطاهای PHP به Exception
        set_error_handler([self::class, 'handleError']);
        
        // گرفتن Exception های catch نشده
        set_exception_handler([self::class, 'handle']);
        
        // گرفتن Fatal Errors
        register_shutdown_function([self::class, 'handleShutdown']);
    }
    
    /**
     * مدیریت Exception ها
     */
    public static function handle(Throwable $exception): void
    {
        // ثبت در سیستم لاگ پیشرفته
        self::logToAdvancedSystem($exception);

        // ثبت در لاگ معمولی (Backup)
        if (function_exists('logger')) {
            logger('error', $exception->getMessage(), [
                'exception' => get_class($exception),
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
                'trace'     => $exception->getTraceAsString(),
            ]);
        } else {
            error_log('[ERROR] ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
        }

        if (config('app.debug', false)) {
            self::renderDebugPage($exception);
        } else {
            self::renderProductionPage($exception);
        }

        exit(1);
    }

    /**
     * ثبت در سیستم لاگ پیشرفته
     */
    private static function logToAdvancedSystem(Throwable $exception): void
    {
        try {
            // فقط اگر جداول وجود داشتن
            $db = Database::getInstance();
            
            // بررسی وجود جدول error_logs
            $tableExists = $db->query(
                "SHOW TABLES LIKE 'error_logs'"
            )->fetch();

            if (!$tableExists) {
                return; // جدول نیست، بی‌خیال
            }

            // استفاده از سرویس
            require_once __DIR__ . '/../app/Services/ErrorLogService.php';
            $errorService = new \App\Services\ErrorLogService($db);

            // تعیین سطح
            $level = self::determineErrorLevel($exception);

            // دریافت user_id
            $userId = null;
            try {
                $session = Session::getInstance();
                $userId = $session->get('user_id');
            } catch (\Throwable $e) {
                // بی‌خیال
            }

            $errorService->logError(
                $level,
                $exception->getMessage(),
                $exception,
                $userId,
                [
                    'url' => $_SERVER['REQUEST_URI'] ?? '',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? ''
                ]
            );

        } catch (\Throwable $e) {
            // اگر سیستم لاگ پیشرفته خراب بود، بی‌خیال
            error_log("Advanced logging failed: " . $e->getMessage());
        }
    }

    /**
     * تعیین سطح خطا
     */
    private static function determineErrorLevel(Throwable $exception): string
    {
        $message = $exception->getMessage();

        // خطاهای بحرانی
        if (
            $exception instanceof \Error ||
            $exception instanceof \ParseError ||
            str_contains($message, 'SQLSTATE') ||
            str_contains($message, 'Table') && str_contains($message, "doesn't exist") ||
            str_contains($message, 'Column not found')
        ) {
            return 'CRITICAL';
        }

        // خطاهای مهم
        if (
            str_contains($message, 'Undefined method') ||
            str_contains($message, 'Undefined variable') ||
            str_contains($message, 'Undefined array key')
        ) {
            return 'ERROR';
        }

        return 'WARNING';
    }
    
    /**
     * تبدیل Error به Exception
     */
    public static function handleError(
        int $level,
        string $message,
        string $file = '',
        int $line = 0
    ): bool {
        if (error_reporting() & $level) {
            throw new ErrorException($message, 0, $level, $file, $line);
        }
        
        return false;
    }
    
    /**
     * گرفتن Fatal Errors
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            // ثبت در سیستم پیشرفته
            self::logFatalError($error);

            // ثبت معمولی
            if (function_exists('logger')) {
                logger('error', 'Fatal Error: ' . $error['message'], [
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'type' => $error['type'],
                ]);
            } else {
                error_log('[FATAL] ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
            }

            if (config('app.debug', false)) {
                echo '<h1>Fatal Error</h1>';
                echo '<pre>' . print_r($error, true) . '</pre>';
            } else {
                echo '<h1>خطای سیستمی</h1><p>لطفاً بعداً تلاش کنید.</p>';
            }
        }

        // ثبت Performance
        self::logPerformance();
    }

    /**
     * ثبت Fatal Error
     */
    private static function logFatalError(array $error): void
    {
        try {
            $db = Database::getInstance();
            
            $tableExists = $db->query("SHOW TABLES LIKE 'error_logs'")->fetch();
            if (!$tableExists) return;

            require_once __DIR__ . '/../app/Services/ErrorLogService.php';
            $errorService = new \App\Services\ErrorLogService($db);

            $errorService->logError(
                'FATAL',
                $error['message'],
                null,
                null,
                [
                    'file' => $error['file'],
                    'line' => $error['line'],
                    'type' => $error['type']
                ]
            );
        } catch (\Throwable $e) {
            // Silent
        }
    }

    /**
     * ثبت Performance
     */
    private static function logPerformance(): void
    {
        try {
            $db = Database::getInstance();
            
            $tableExists = $db->query("SHOW TABLES LIKE 'performance_logs'")->fetch();
            if (!$tableExists) return;

            require_once __DIR__ . '/../app/Services/PerformanceMonitorService.php';
            $perfService = new \App\Services\PerformanceMonitorService($db);

            $endpoint = $_SERVER['REQUEST_URI'] ?? '/';
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $statusCode = http_response_code() ?: 200;

            $userId = null;
            try {
                $session = Session::getInstance();
                $userId = $session->get('user_id');
            } catch (\Throwable $e) {
                // بی‌خیال
            }

            $perfService->logRequest($endpoint, $method, $statusCode, $userId);

        } catch (\Throwable $e) {
            // Silent
        }
    }
    
    /**
     * نمایش صفحه خطا در Debug Mode
     */
    private static function renderDebugPage(Throwable $exception): void
    {
        http_response_code(500);
        ?>
        <!DOCTYPE html>
        <html lang="fa" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <title>خطای سیستم</title>
            <style>
                body { font-family: Tahoma; background: #f5f5f5; padding: 20px; }
                .error-box { background: #fff; border: 3px solid #f44336; border-radius: 8px; padding: 20px; max-width: 900px; margin: 0 auto; }
                h1 { color: #f44336; margin: 0 0 15px; }
                .message { background: #ffebee; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .trace { background: #263238; color: #aed581; padding: 15px; border-radius: 5px; overflow-x: auto; font-family: monospace; font-size: 12px; }
                .meta { color: #666; font-size: 13px; margin-top: 10px; }
            </style>
        </head>
        <body>
            <div class="error-box">
                <h1>⚠️ خطای سیستم</h1>
                <div class="message">
                    <strong><?= get_class($exception) ?>:</strong><br>
                    <?= htmlspecialchars($exception->getMessage()) ?>
                </div>
                <div class="meta">
                    📁 <strong>فایل:</strong> <?= htmlspecialchars($exception->getFile()) ?><br>
                    📍 <strong>خط:</strong> <?= $exception->getLine() ?>
                </div>
                <h3>Stack Trace:</h3>
                <div class="trace"><?= htmlspecialchars($exception->getTraceAsString()) ?></div>
            </div>
        </body>
        </html>
        <?php
    }
    
    /**
     * نمایش صفحه خطا در Production
     */
    private static function renderProductionPage(Throwable $exception): void
    {
        http_response_code(500);
        ?>
        <!DOCTYPE html>
        <html lang="fa" dir="rtl">
        <head>
            <meta charset="UTF-8">
            <title>خطای سیستمی</title>
            <style>
                body { font-family: Tahoma; background: #f5f5f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
                .error-container { text-align: center; background: #fff; padding: 40px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                h1 { color: #f44336; font-size: 72px; margin: 0; }
                p { color: #666; font-size: 18px; }
                a { display: inline-block; margin-top: 20px; padding: 10px 30px; background: #4fc3f7; color: #fff; text-decoration: none; border-radius: 5px; }
            </style>
        </head>
        <body>
            <div class="error-container">
                <h1>500</h1>
                <p>متأسفانه خطای سیستمی رخ داده است</p>
                <p>لطفاً چند لحظه دیگر مجدداً تلاش کنید</p>
                <a href="<?= url('/') ?>">بازگشت به صفحه اصلی</a>
            </div>
        </body>
        </html>
        <?php
    }
}