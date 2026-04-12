<?php

namespace App\Services\Sentry;

use Core\Database;
use App\Services\Sentry\ErrorMonitoring\SentryErrorMonitor;
use App\Services\Sentry\PerformanceMonitoring\SentryPerformanceMonitor;

/**
 * 🛡️ SentryExceptionHandler - Global Handler برای خطاها
 * 
 * این کلاس:
 * - همه Exceptionها رو capture می‌کنه
 * - Performance رو track می‌کنه
 * - Breadcrumbs خودکار اضافه می‌کنه
 * - با ExceptionHandler موجود integrate می‌شه
 */
class SentryExceptionHandler
{
    private static ?SentryExceptionHandler $instance = null;
    
    private SentryErrorMonitor $errorMonitor;
    private SentryPerformanceMonitor $performanceMonitor;
    private bool $registered = false;

    private function __construct()
    {
        $db = Database::getInstance();
        
        // تنظیمات از environment
        $config = [
            'enabled' => $_ENV['SENTRY_ENABLED'] ?? true,
            'environment' => $_ENV['APP_ENV'] ?? 'production',
            'release' => $_ENV['APP_RELEASE'] ?? null,
            'sample_rate' => (float)($_ENV['SENTRY_SAMPLE_RATE'] ?? 1.0),
        ];

        $this->errorMonitor = new SentryErrorMonitor($db, $config);
        $this->performanceMonitor = new SentryPerformanceMonitor($db, $config);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 📝 Register - ثبت handlerها
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        // Error Handler
        set_error_handler([$this, 'handleError']);
        
        // Exception Handler
        set_exception_handler([$this, 'handleException']);
        
        // Shutdown Handler (برای fatal errors)
        register_shutdown_function([$this, 'handleShutdown']);

        $this->registered = true;
    }

    /**
     * 🚨 Handle Error
     */
    public function handleError(
        int $errno,
        string $errstr,
        string $errfile = '',
        int $errline = 0
    ): bool {
        // تبدیل error به exception
        $exception = new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        
        // level مناسب
        $level = match($errno) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR => 'error',
            E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING => 'warning',
            default => 'info'
        };

        // capture
        if (in_array($errno, [E_ERROR, E_WARNING, E_USER_ERROR, E_USER_WARNING])) {
            $userId = $this->getCurrentUserId();
            $this->errorMonitor->captureException($exception, $userId, [], $level);
        }

        // اجازه به error handler بعدی
        return false;
    }

    /**
     * 💥 Handle Exception
     */
    public function handleException(\Throwable $exception): void
    {
        try {
            $userId = $this->getCurrentUserId();
            
            // Capture در Sentry
            $this->errorMonitor->captureException(
                $exception,
                $userId,
                ['http_code' => http_response_code()],
                'error'
            );

            // نمایش صفحه خطا به کاربر
            $this->displayErrorPage($exception);

        } catch (\Throwable $e) {
            // اگر خود Sentry خراب شد، حداقل لاگ کن
            error_log('Sentry Exception Handler failed: ' . $e->getMessage());
            
            // نمایش پیام ساده
            if ($_ENV['APP_ENV'] !== 'production') {
                echo '<h1>Error</h1>';
                echo '<p>' . htmlspecialchars($exception->getMessage()) . '</p>';
            } else {
                echo '<h1>خطایی رخ داده است</h1>';
                echo '<p>لطفاً بعداً تلاش کنید.</p>';
            }
        }
    }

    /**
     * ⚠️ Handle Shutdown (برای Fatal Errors)
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $exception = new \ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            );

            $userId = $this->getCurrentUserId();
            $this->errorMonitor->captureException($exception, $userId, [], 'fatal');
        }

        // Finish Performance Transaction
        $this->finishPerformanceTracking();
    }

    /**
     * 🏁 Finish Performance Tracking
     */
    private function finishPerformanceTracking(): void
    {
        try {
            $this->performanceMonitor->finishTransaction([
                'status_code' => http_response_code(),
                'user_id' => $this->getCurrentUserId(),
            ]);
        } catch (\Throwable $e) {
            // Silent fail
        }
    }

    /**
     * 🎨 Display Error Page
     */
    private function displayErrorPage(\Throwable $exception): void
    {
        http_response_code(500);
        
        if ($_ENV['APP_ENV'] === 'production') {
            // صفحه خطای زیبا برای production
            include dirname(__DIR__, 3) . '/views/errors/500.php';
        } else {
            // نمایش جزئیات در development
            echo '<html><head><title>Error</title>';
            echo '<style>body{font-family:sans-serif;padding:20px;background:#f5f5f5;}';
            echo '.error{background:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}';
            echo 'h1{color:#d32f2f;margin:0 0 10px;}pre{background:#f5f5f5;padding:15px;overflow:auto;}</style>';
            echo '</head><body><div class="error">';
            echo '<h1>' . htmlspecialchars(get_class($exception)) . '</h1>';
            echo '<p>' . htmlspecialchars($exception->getMessage()) . '</p>';
            echo '<p><strong>File:</strong> ' . htmlspecialchars($exception->getFile()) . ':' . $exception->getLine() . '</p>';
            echo '<h3>Stack Trace:</h3>';
            echo '<pre>' . htmlspecialchars($exception->getTraceAsString()) . '</pre>';
            echo '</div></body></html>';
        }
    }

    /**
     * 👤 Get Current User ID
     */
    private function getCurrentUserId(): ?int
    {
        try {
            $session = \Core\Session::getInstance();
            return $session->get('user_id') ? (int)$session->get('user_id') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 📊 Get Error Monitor
     */
    public function getErrorMonitor(): SentryErrorMonitor
    {
        return $this->errorMonitor;
    }

    /**
     * 🚀 Get Performance Monitor
     */
    public function getPerformanceMonitor(): SentryPerformanceMonitor
    {
        return $this->performanceMonitor;
    }

    /**
     * 🔧 Configure
     */
    public function configure(array $config): void
    {
        foreach ($config as $key => $value) {
            $this->errorMonitor->setConfig($key, $value);
        }
    }
}

/**
 * 🎯 Helper Functions
 */

/**
 * Capture Exception
 */
function sentry_capture_exception(\Throwable $exception, ?int $userId = null, array $context = []): ?string
{
    $handler = SentryExceptionHandler::getInstance();
    return $handler->getErrorMonitor()->captureException($exception, $userId, $context);
}

/**
 * Capture Message
 */
function sentry_capture_message(string $message, string $level = 'info', ?int $userId = null, array $context = []): ?string
{
    $handler = SentryExceptionHandler::getInstance();
    return $handler->getErrorMonitor()->captureMessage($message, $level, $userId, $context);
}

/**
 * Add Breadcrumb
 */
function sentry_add_breadcrumb(string $message, string $category = 'default', string $level = 'info', array $data = []): void
{
    $handler = SentryExceptionHandler::getInstance();
    $handler->getErrorMonitor()->addBreadcrumb($message, $category, $level, $data);
}

/**
 * Start Transaction
 */
function sentry_start_transaction(string $name, string $op = 'http.request', array $data = []): ?string
{
    $handler = SentryExceptionHandler::getInstance();
    return $handler->getPerformanceMonitor()->startTransaction($name, $op, $data);
}

/**
 * Start Span
 */
function sentry_start_span(string $op, string $description, array $data = []): string
{
    $handler = SentryExceptionHandler::getInstance();
    return $handler->getPerformanceMonitor()->startSpan($op, $description, $data);
}

/**
 * Finish Span
 */
function sentry_finish_span(string $spanId, array $data = []): void
{
    $handler = SentryExceptionHandler::getInstance();
    $handler->getPerformanceMonitor()->finishSpan($spanId, $data);
}

/**
 * Track Query
 */
function sentry_track_query(string $query, float $duration, ?array $params = null): void
{
    $handler = SentryExceptionHandler::getInstance();
    $handler->getPerformanceMonitor()->trackQuery($query, $duration, $params);
}
