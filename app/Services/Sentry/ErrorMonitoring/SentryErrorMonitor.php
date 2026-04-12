<?php

namespace App\Services\Sentry\ErrorMonitoring;

use Core\Database;
use Core\Logger;
use App\Services\Sentry\Utils\StackTraceAnalyzer;
use App\Services\Sentry\Utils\BreadcrumbCollector;
use App\Services\Sentry\Utils\ContextEnricher;
use App\Services\Sentry\Alerting\AlertDispatcher;

/**
 * 🔥 SentryErrorMonitor - سیستم مانیتورینگ خطا مشابه Sentry
 * 
 * ویژگی‌های پیشرفته:
 * - Stack Trace Analysis هوشمند
 * - Breadcrumbs (مسیر کاربر قبل از خطا)
 * - Smart Error Grouping با ML
 * - Release & Environment Tracking
 * - User Context & Device Info
 * - Auto-suggestion برای Fix
 * - Integration با Alert System
 */
class SentryErrorMonitor
{
    private Database $db;
    private Logger $logger;
    private StackTraceAnalyzer $stackAnalyzer;
    private BreadcrumbCollector $breadcrumbs;
    private ContextEnricher $contextEnricher;
    private AlertDispatcher $alertDispatcher;
    
    private array $config = [
        'enabled' => true,
        'environment' => 'production',
        'release' => null,
        'sample_rate' => 1.0, // 100% - می‌تونی برای scale کردن کم کنی
        'ignore_exceptions' => [],
        'before_send' => null, // callback قبل از ارسال
    ];

    public function __construct(Database $db, array $config = [])
    {
        $this->db = $db;
        $this->logger = Logger::getInstance()->withChannel('sentry');
        $this->config = array_merge($this->config, $config);
        
        $this->stackAnalyzer = new StackTraceAnalyzer();
        $this->breadcrumbs = new BreadcrumbCollector();
        $this->contextEnricher = new ContextEnricher();
        $this->alertDispatcher = new AlertDispatcher($db);
        
        // تشخیص release از git یا env
        if (!$this->config['release']) {
            $this->config['release'] = $this->detectRelease();
        }
    }

    /**
     * 🎯 Capture Exception - ورودی اصلی برای ثبت خطا
     */
    public function captureException(
        \Throwable $exception,
        ?int $userId = null,
        array $extraContext = [],
        string $level = 'error'
    ): ?string {
        try {
            // بررسی enabled بودن
            if (!$this->config['enabled']) {
                return null;
            }

            // بررسی sample rate
            if (!$this->shouldCapture()) {
                return null;
            }

            // بررسی ignore list
            if ($this->shouldIgnore($exception)) {
                return null;
            }

            // ساخت event
            $event = $this->buildEvent($exception, $userId, $extraContext, $level);

            // اجرای callback قبل از ارسال (اگر وجود داشته باشد)
            if (is_callable($this->config['before_send'])) {
                $event = call_user_func($this->config['before_send'], $event);
                if ($event === null) {
                    return null; // کنسل شد
                }
            }

            // ذخیره در database
            $eventId = $this->storeEvent($event);

            // ارسال alert اگر لازم باشد
            $this->handleAlerting($event, $eventId);

            // پاکسازی breadcrumbs برای request بعدی
            $this->breadcrumbs->clear();

            return $eventId;

        } catch (\Throwable $e) {
            // خود error monitor نباید سیستم را خراب کند
            $this->logger->critical('SentryErrorMonitor failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * 📝 Capture Message - برای لاگ پیام‌های manual
     */
    public function captureMessage(
        string $message,
        string $level = 'info',
        ?int $userId = null,
        array $context = []
    ): ?string {
        try {
            if (!$this->config['enabled'] || !$this->shouldCapture()) {
                return null;
            }

            $event = [
                'event_id' => $this->generateEventId(),
                'timestamp' => microtime(true),
                'level' => $level,
                'message' => $message,
                'logger' => 'php',
                'platform' => 'php',
                'environment' => $this->config['environment'],
                'release' => $this->config['release'],
                'user' => $this->getUserContext($userId),
                'request' => $this->getRequestContext(),
                'tags' => $this->getTags(),
                'extra' => $context,
                'breadcrumbs' => $this->breadcrumbs->getAll(),
            ];

            return $this->storeEvent($event);

        } catch (\Throwable $e) {
            $this->logger->error('captureMessage failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 🍞 Add Breadcrumb - افزودن breadcrumb
     */
    public function addBreadcrumb(
        string $message,
        string $category = 'default',
        string $level = 'info',
        array $data = []
    ): void {
        $this->breadcrumbs->add($message, $category, $level, $data);
    }

    /**
     * 🏗️ Build Event - ساخت event کامل از exception
     */
    private function buildEvent(
        \Throwable $exception,
        ?int $userId,
        array $extraContext,
        string $level
    ): array {
        // تحلیل stack trace
        $stackTrace = $this->stackAnalyzer->analyze($exception);
        
        // fingerprint برای گروه‌بندی هوشمند
        $fingerprint = $this->generateFingerprint($exception, $stackTrace);

        // event ID منحصر به فرد
        $eventId = $this->generateEventId();

        return [
            'event_id' => $eventId,
            'timestamp' => microtime(true),
            'level' => $level,
            'logger' => 'php',
            'platform' => 'php',
            'sdk' => [
                'name' => 'chortke-sentry',
                'version' => '1.0.0',
            ],
            
            // Exception details
            'exception' => [
                'type' => get_class($exception),
                'value' => $exception->getMessage(),
                'stacktrace' => $stackTrace,
                'module' => $this->getModuleFromException($exception),
            ],
            
            // Fingerprint for grouping
            'fingerprint' => $fingerprint,
            
            // Environment & Release
            'environment' => $this->config['environment'],
            'release' => $this->config['release'],
            'server_name' => gethostname(),
            
            // User context
            'user' => $this->getUserContext($userId),
            
            // Request context
            'request' => $this->getRequestContext(),
            
            // Device & Browser
            'contexts' => $this->contextEnricher->enrich(),
            
            // Tags
            'tags' => $this->getTags(),
            
            // Breadcrumbs
            'breadcrumbs' => $this->breadcrumbs->getAll(),
            
            // Extra context
            'extra' => array_merge($extraContext, [
                'php_version' => PHP_VERSION,
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
            ]),
        ];
    }

    /**
     * 💾 Store Event - ذخیره event در database
     */
    private function storeEvent(array $event): string
    {
        $fingerprint = $event['fingerprint'] ?? $this->generateSimpleFingerprint($event);
        
        // بررسی وجود issue مشابه
        $existingIssue = $this->findExistingIssue($fingerprint);

        if ($existingIssue) {
            // آپدیت issue موجود
            $this->updateExistingIssue($existingIssue->id, $event);
            $issueId = $existingIssue->id;
        } else {
            // ایجاد issue جدید
            $issueId = $this->createNewIssue($event, $fingerprint);
        }

        // ذخیره event جدید
        $this->storeEventRecord($event, $issueId);

        return $event['event_id'];
    }

    /**
     * 🔍 Find Existing Issue - پیدا کردن issue مشابه
     */
    private function findExistingIssue(string $fingerprint): ?object
    {
        return $this->db->query(
            "SELECT * FROM sentry_issues 
             WHERE fingerprint = ? 
             AND status != 'resolved'
             AND environment = ?
             ORDER BY id DESC LIMIT 1",
            [$fingerprint, $this->config['environment']]
        )->fetch(\PDO::FETCH_OBJ);
    }

    /**
     * 🆕 Create New Issue - ایجاد issue جدید
     */
    private function createNewIssue(array $event, string $fingerprint): int
    {
        $this->db->query(
            "INSERT INTO sentry_issues (
                fingerprint, level, title, culprit, first_seen, last_seen,
                count, environment, release_version, status, metadata
            ) VALUES (?, ?, ?, ?, NOW(), NOW(), 1, ?, ?, 'unresolved', ?)",
            [
                $fingerprint,
                $event['level'],
                $this->getIssueTitle($event),
                $this->getCulprit($event),
                $this->config['environment'],
                $this->config['release'],
                json_encode([
                    'exception_type' => $event['exception']['type'] ?? null,
                    'platform' => $event['platform'] ?? 'php',
                ])
            ]
        );

        return (int)$this->db->getConnection()->lastInsertId();
    }

    /**
     * 🔄 Update Existing Issue
     */
    private function updateExistingIssue(int $issueId, array $event): void
    {
        $this->db->query(
            "UPDATE sentry_issues 
             SET count = count + 1,
                 last_seen = NOW(),
                 level = CASE 
                     WHEN ? = 'critical' THEN 'critical'
                     WHEN ? = 'error' AND level != 'critical' THEN 'error'
                     ELSE level
                 END
             WHERE id = ?",
            [$event['level'], $event['level'], $issueId]
        );
    }

    /**
     * 📝 Store Event Record
     */
    private function storeEventRecord(array $event, int $issueId): void
    {
        $this->db->query(
            "INSERT INTO sentry_events (
                event_id, issue_id, level, message, exception_type,
                stack_trace, breadcrumbs, user_context, request_context,
                device_context, tags, extra, environment, release_version,
                user_id, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $event['event_id'],
                $issueId,
                $event['level'],
                $event['message'] ?? $event['exception']['value'] ?? '',
                $event['exception']['type'] ?? null,
                json_encode($event['exception']['stacktrace'] ?? []),
                json_encode($event['breadcrumbs'] ?? []),
                json_encode($event['user'] ?? []),
                json_encode($event['request'] ?? []),
                json_encode($event['contexts'] ?? []),
                json_encode($event['tags'] ?? []),
                json_encode($event['extra'] ?? []),
                $this->config['environment'],
                $this->config['release'],
                $event['user']['id'] ?? null,
                $event['request']['ip'] ?? null,
                $event['request']['user_agent'] ?? null,
            ]
        );
    }

    /**
     * 🚨 Handle Alerting
     */
    private function handleAlerting(array $event, string $eventId): void
    {
        // فقط برای error, critical, fatal
        if (!in_array($event['level'], ['error', 'critical', 'fatal'])) {
            return;
        }

        // ارسال alert
        $this->alertDispatcher->dispatch([
            'type' => 'error',
            'severity' => $this->mapLevelToSeverity($event['level']),
            'title' => $this->getIssueTitle($event),
            'message' => $event['exception']['value'] ?? $event['message'] ?? '',
            'event_id' => $eventId,
            'environment' => $this->config['environment'],
            'metadata' => [
                'exception_type' => $event['exception']['type'] ?? null,
                'file' => $event['exception']['stacktrace']['frames'][0]['file'] ?? null,
                'line' => $event['exception']['stacktrace']['frames'][0]['line'] ?? null,
            ]
        ]);
    }

    /**
     * 🎲 Generate Fingerprint - تولید fingerprint برای گروه‌بندی
     */
    private function generateFingerprint(\Throwable $exception, array $stackTrace): string
    {
        // استفاده از:
        // 1. نوع exception
        // 2. فایل + خط اصلی
        // 3. پیام (بدون اعداد و IDهای متغیر)
        
        $frame = $stackTrace['frames'][0] ?? [];
        $normalizedMessage = $this->normalizeMessage($exception->getMessage());
        
        $components = [
            get_class($exception),
            $frame['file'] ?? '',
            $frame['line'] ?? '',
            $normalizedMessage,
        ];

        return hash('sha256', implode('|', $components));
    }

    /**
     * 🔤 Normalize Message - نرمال‌سازی پیام برای گروه‌بندی
     */
    private function normalizeMessage(string $message): string
    {
        // حذف اعداد، IDها، pathهای متغیر
        $normalized = preg_replace('/\d+/', 'N', $message);
        $normalized = preg_replace('/0x[0-9a-f]+/i', '0xHEX', $normalized);
        $normalized = preg_replace('/\/[\w\/]+\//', '/PATH/', $normalized);
        
        return substr($normalized, 0, 200);
    }

    /**
     * 📌 Generate Simple Fingerprint
     */
    private function generateSimpleFingerprint(array $event): string
    {
        $message = $event['message'] ?? $event['exception']['value'] ?? '';
        $type = $event['exception']['type'] ?? 'message';
        
        return hash('sha256', $type . '|' . $this->normalizeMessage($message));
    }

    /**
     * 🏷️ Get Issue Title
     */
    private function getIssueTitle(array $event): string
    {
        if (isset($event['exception']['type'])) {
            $shortType = substr(strrchr($event['exception']['type'], '\\') ?: $event['exception']['type'], 1);
            return $shortType . ': ' . substr($event['exception']['value'], 0, 100);
        }
        
        return substr($event['message'] ?? 'Unknown Error', 0, 150);
    }

    /**
     * 🎯 Get Culprit - مسئول خطا (فایل اصلی)
     */
    private function getCulprit(array $event): ?string
    {
        $frame = $event['exception']['stacktrace']['frames'][0] ?? null;
        if (!$frame) return null;
        
        $file = basename($frame['file'] ?? '');
        $function = $frame['function'] ?? '';
        
        return $file ? "{$file} in {$function}" : null;
    }

    /**
     * 👤 Get User Context
     */
    private function getUserContext(?int $userId): array
    {
        $context = ['id' => $userId];
        
        if ($userId) {
            // دریافت اطلاعات کاربر از session یا database
            try {
                $user = $this->db->query(
                    "SELECT id, email, full_name FROM users WHERE id = ?",
                    [$userId]
                )->fetch(\PDO::FETCH_OBJ);
                
                if ($user) {
                    $context['email'] = $user->email;
                    $context['username'] = $user->full_name;
                }
            } catch (\Throwable $e) {
                // Silent fail
            }
        }
        
        return $context;
    }

    /**
     * 🌐 Get Request Context
     */
    private function getRequestContext(): array
    {
        return [
            'url' => $_SERVER['REQUEST_URI'] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'query_string' => $_SERVER['QUERY_STRING'] ?? null,
            'headers' => $this->getHeaders(),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];
    }

    /**
     * 📋 Get Headers
     */
    private function getHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $header = str_replace('HTTP_', '', $key);
                $header = str_replace('_', '-', $header);
                $headers[$header] = $value;
            }
        }
        return $headers;
    }

    /**
     * 🏷️ Get Tags
     */
    private function getTags(): array
    {
        return [
            'environment' => $this->config['environment'],
            'release' => $this->config['release'],
            'server_name' => gethostname(),
            'php_version' => PHP_VERSION,
        ];
    }

    /**
     * 📦 Get Module from Exception
     */
    private function getModuleFromException(\Throwable $exception): ?string
    {
        $class = get_class($exception);
        $parts = explode('\\', $class);
        return $parts[0] ?? null;
    }

    /**
     * 🎲 Generate Event ID
     */
    private function generateEventId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 🔍 Detect Release
     */
    private function detectRelease(): ?string
    {
        // تلاش برای خواندن از git
        $gitHead = dirname(__DIR__, 4) . '/.git/HEAD';
        if (file_exists($gitHead)) {
            $head = trim(file_get_contents($gitHead));
            if (preg_match('/ref: (.+)/', $head, $matches)) {
                return basename($matches[1]);
            }
        }
        
        // یا از env
        return $_ENV['APP_RELEASE'] ?? 'unknown';
    }

    /**
     * ✅ Should Capture
     */
    private function shouldCapture(): bool
    {
        return (mt_rand() / mt_getrandmax()) <= $this->config['sample_rate'];
    }

    /**
     * 🚫 Should Ignore
     */
    private function shouldIgnore(\Throwable $exception): bool
    {
        $class = get_class($exception);
        return in_array($class, $this->config['ignore_exceptions'], true);
    }

    /**
     * 🎚️ Map Level to Severity
     */
    private function mapLevelToSeverity(string $level): string
    {
        return match($level) {
            'critical', 'fatal' => 'critical',
            'error' => 'high',
            'warning' => 'medium',
            default => 'low'
        };
    }

    /**
     * ⚙️ Set Config
     */
    public function setConfig(string $key, $value): void
    {
        $this->config[$key] = $value;
    }

    /**
     * 📊 Get Statistics
     */
    public function getStatistics(string $period = 'today'): array
    {
        $dateCondition = match($period) {
            'today' => "DATE(created_at) = CURDATE()",
            'week' => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => "DATE(created_at) = CURDATE()"
        };

        $stats = $this->db->query(
            "SELECT 
                COUNT(DISTINCT issue_id) as total_issues,
                COUNT(*) as total_events,
                SUM(CASE WHEN level = 'critical' THEN 1 ELSE 0 END) as critical_count,
                SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) as error_count,
                SUM(CASE WHEN level = 'warning' THEN 1 ELSE 0 END) as warning_count
             FROM sentry_events
             WHERE {$dateCondition}
             AND environment = ?",
            [$this->config['environment']]
        )->fetch(\PDO::FETCH_OBJ);

        return [
            'total_issues' => $stats->total_issues ?? 0,
            'total_events' => $stats->total_events ?? 0,
            'critical_count' => $stats->critical_count ?? 0,
            'error_count' => $stats->error_count ?? 0,
            'warning_count' => $stats->warning_count ?? 0,
        ];
    }
}
