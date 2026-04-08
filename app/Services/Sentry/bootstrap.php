<?php

/**
 * 🚀 Sentry Bootstrap
 * 
 * این فایل باید در ابتدای app.php یا bootstrap فراخوانی بشه
 * 
 * Usage:
 * require_once __DIR__ . '/app/Services/Sentry/bootstrap.php';
 */

use App\Services\Sentry\SentryExceptionHandler;
use App\Services\Sentry\PerformanceMonitoring\SentryPerformanceMonitor;

// ==========================================
// 1. Initialize Sentry Exception Handler
// ==========================================

$sentryHandler = SentryExceptionHandler::getInstance();

// تنظیمات (اختیاری - می‌تونی از .env بخونی)
$sentryHandler->configure([
    'enabled' => $_ENV['SENTRY_ENABLED'] ?? true,
    'environment' => $_ENV['APP_ENV'] ?? 'production',
    'release' => $_ENV['APP_RELEASE'] ?? null,
    'sample_rate' => (float)($_ENV['SENTRY_SAMPLE_RATE'] ?? 1.0),
    'ignore_exceptions' => [
        // Exceptionهایی که نمی‌خوای track بشن
        // 'App\\Exceptions\\NotFoundException',
    ],
]);

// ثبت handlerها
$sentryHandler->register();

// ==========================================
// 2. Start Performance Transaction
// ==========================================

// شروع transaction برای این request
$transactionName = $_SERVER['REQUEST_URI'] ?? 'unknown';
$transactionId = sentry_start_transaction($transactionName, 'http.request', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'host' => $_SERVER['HTTP_HOST'] ?? '',
]);

// ==========================================
// 3. Auto Breadcrumbs
// ==========================================

// Breadcrumb خودکار برای navigation
if (isset($_SERVER['HTTP_REFERER'])) {
    sentry_add_breadcrumb(
        'Page loaded',
        'navigation',
        'info',
        [
            'from' => $_SERVER['HTTP_REFERER'],
            'to' => $_SERVER['REQUEST_URI'] ?? '',
        ]
    );
}

// ==========================================
// 4. Database Query Tracking (Hook)
// ==========================================

/**
 * این بخش برای hook کردن به Database class
 * تا queryها رو به طور خودکار track کنه
 */
if (class_exists('Core\Database')) {
    // می‌تونی یک wrapper روی Database بزنی که queryها رو track کنه
    // یا از event dispatcher استفاده کنی
    
    // مثال:
    // Database::onQuery(function($query, $duration) {
    //     sentry_track_query($query, $duration);
    // });
}

// ==========================================
// 5. Custom Error Messages
// ==========================================

/**
 * سفارشی‌سازی پیام‌های خطا
 */
if (!function_exists('sentry_error_page')) {
    function sentry_error_page(string $title, string $message, int $code = 500): void
    {
        http_response_code($code);
        
        if ($_ENV['APP_ENV'] === 'production') {
            // صفحه خطای کاربرپسند
            echo <<<HTML
            <!DOCTYPE html>
            <html lang="fa" dir="rtl">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>{$title}</title>
                <style>
                    * { margin: 0; padding: 0; box-sizing: border-box; }
                    body { 
                        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        min-height: 100vh;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                    }
                    .error-container {
                        background: white;
                        padding: 3rem 2rem;
                        border-radius: 20px;
                        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                        max-width: 500px;
                        text-align: center;
                    }
                    h1 {
                        color: #667eea;
                        font-size: 2.5rem;
                        margin-bottom: 1rem;
                    }
                    p {
                        color: #666;
                        font-size: 1.1rem;
                        line-height: 1.6;
                        margin-bottom: 2rem;
                    }
                    .btn {
                        display: inline-block;
                        padding: 12px 30px;
                        background: #667eea;
                        color: white;
                        text-decoration: none;
                        border-radius: 25px;
                        transition: all 0.3s;
                    }
                    .btn:hover {
                        background: #764ba2;
                        transform: translateY(-2px);
                        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
                    }
                    .error-code {
                        font-size: 6rem;
                        font-weight: bold;
                        color: #f0f0f0;
                        margin-bottom: 1rem;
                    }
                </style>
            </head>
            <body>
                <div class="error-container">
                    <div class="error-code">{$code}</div>
                    <h1>{$title}</h1>
                    <p>{$message}</p>
                    <a href="/" class="btn">بازگشت به صفحه اصلی</a>
                </div>
            </body>
            </html>
            HTML;
        } else {
            // Development mode - نمایش جزئیات
            echo "<h1>{$code} - {$title}</h1>";
            echo "<p>{$message}</p>";
        }
        
        exit;
    }
}

// ==========================================
// 6. Shutdown Hook
// ==========================================

/**
 * در پایان request، performance transaction رو finish کن
 */
register_shutdown_function(function() use ($sentryHandler) {
    try {
        $sentryHandler->getPerformanceMonitor()->finishTransaction([
            'status_code' => http_response_code(),
            'user_id' => $_SESSION['user_id'] ?? null,
        ]);
    } catch (\Throwable $e) {
        // Silent fail
    }
});

// ==========================================
// 7. Helper: Measure Performance
// ==========================================

/**
 * Helper برای اندازه‌گیری performance یک تابع
 * 
 * Usage:
 * $result = sentry_measure('heavy_calculation', function() {
 *     // کد شما
 *     return $result;
 * });
 */
if (!function_exists('sentry_measure')) {
    function sentry_measure(string $name, callable $callback, string $op = 'function')
    {
        $spanId = sentry_start_span($op, $name);
        
        try {
            $result = $callback();
            sentry_finish_span($spanId, ['status' => 'ok']);
            return $result;
        } catch (\Throwable $e) {
            sentry_finish_span($spanId, ['status' => 'error', 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}

// ==========================================
// 8. Context Helpers
// ==========================================

/**
 * Set User Context
 */
if (!function_exists('sentry_set_user')) {
    function sentry_set_user(?int $userId, ?string $email = null, ?string $username = null): void
    {
        // این اطلاعات در errorها capture می‌شه
        if ($userId) {
            $_ENV['SENTRY_USER_ID'] = $userId;
            $_ENV['SENTRY_USER_EMAIL'] = $email;
            $_ENV['SENTRY_USER_USERNAME'] = $username;
        }
    }
}

/**
 * Set Tag
 */
if (!function_exists('sentry_set_tag')) {
    function sentry_set_tag(string $key, string $value): void
    {
        if (!isset($_ENV['SENTRY_TAGS'])) {
            $_ENV['SENTRY_TAGS'] = [];
        }
        $_ENV['SENTRY_TAGS'][$key] = $value;
    }
}

// ==========================================
// SUCCESS
// ==========================================

// Log که سیستم Sentry راه‌اندازی شد
if ($_ENV['APP_DEBUG'] ?? false) {
    error_log('✅ Sentry Monitoring System Initialized');
}

return $sentryHandler;
