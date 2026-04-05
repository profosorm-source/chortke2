<?php

namespace App\Services;

use Core\Database;
use Core\Logger;

/**
 * سرویس مدیریت و تحلیل خطاهای سیستم
 */
class ErrorLogService
{
    private Database $db;
    private Logger $logger;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->logger = Logger::getInstance();
    }

    /**
     * ثبت خطا با گروه‌بندی هوشمند
     */
    public function logError(
        string $level,
        string $message,
        ?\Throwable $exception = null,
        ?int $userId = null,
        array $context = []
    ): bool {
        try {
            // ایجاد hash برای گروه‌بندی خطاهای مشابه
            $errorHash = $this->generateErrorHash($message, $exception);
            
            // بررسی وجود خطای مشابه
            $existing = $this->db->query(
                "SELECT id, occurrence_count FROM error_logs 
                 WHERE error_hash = ? AND is_resolved = 0 
                 ORDER BY created_at DESC LIMIT 1",
                [$errorHash]
            )->fetch(\PDO::FETCH_OBJ);

            if ($existing) {
                // آپدیت تعداد تکرار
                $this->db->query(
                    "UPDATE error_logs 
                     SET occurrence_count = occurrence_count + 1,
                         last_occurred_at = NOW()
                     WHERE id = ?",
                    [$existing->id]
                );

                // اگر تعداد تکرار زیاد شد، هشدار بده
                if ($existing->occurrence_count + 1 >= 5) {
                    $this->createAlert(
                        'خطای تکراری',
                        "خطا {$existing->occurrence_count} بار تکرار شده: " . substr($message, 0, 100),
                        'high',
                        ['error_id' => $existing->id, 'count' => $existing->occurrence_count + 1]
                    );
                }

                return true;
            }

            // ثبت خطای جدید
            $filePath = $exception ? $exception->getFile() : ($context['file'] ?? null);
            $lineNumber = $exception ? $exception->getLine() : ($context['line'] ?? null);
            $trace = $exception ? $exception->getTraceAsString() : ($context['trace'] ?? null);

            $this->db->query(
                "INSERT INTO error_logs 
                (error_hash, level, message, exception_type, file_path, line_number, 
                 trace, context, user_id, ip_address, user_agent, url, method, 
                 first_occurred_at, last_occurred_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                [
                    $errorHash,
                    $level,
                    $message,
                    $exception ? get_class($exception) : null,
                    $filePath,
                    $lineNumber,
                    $trace,
                    json_encode($context, JSON_UNESCAPED_UNICODE),
                    $userId,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null,
                    $_SERVER['REQUEST_URI'] ?? null,
                    $_SERVER['REQUEST_METHOD'] ?? null
                ]
            );

            // برای خطاهای Critical فوری هشدار بده
            if (in_array($level, ['CRITICAL', 'FATAL'])) {
                $this->createAlert(
                    "خطای {$level}",
                    $message,
                    'critical',
                    ['file' => $filePath, 'line' => $lineNumber]
                );
            }

            return true;

        } catch (\Throwable $e) {
            // اگر خود سیستم لاگ خراب شد، حداقل توی فایل بنویس
            $this->logger->error('ErrorLogService failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * تولید hash منحصر به فرد برای خطا
     */
    private function generateErrorHash(string $message, ?\Throwable $exception): string
    {
        if ($exception) {
            $unique = $exception->getFile() . ':' . $exception->getLine() . ':' . get_class($exception);
        } else {
            $unique = $message;
        }
        
        return hash('sha256', $unique);
    }

    /**
     * دریافت آمار خطاها
     */
    public function getStatistics(string $period = 'today'): array
    {
        $dateCondition = match($period) {
            'today' => "DATE(created_at) = CURDATE()",
            'yesterday' => "DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)",
            'week' => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => "DATE(created_at) = CURDATE()"
        };

        // کل خطاها
        $total = $this->db->query(
            "SELECT COUNT(*) as count FROM error_logs WHERE {$dateCondition}"
        )->fetch(\PDO::FETCH_OBJ);

        // بر اساس level
        $byLevel = $this->db->query(
            "SELECT level, COUNT(*) as count, SUM(occurrence_count) as occurrences
             FROM error_logs 
             WHERE {$dateCondition}
             GROUP BY level"
        )->fetchAll(\PDO::FETCH_OBJ);

        // پرتکرارترین خطاها
        $topErrors = $this->db->query(
            "SELECT id, message, file_path, line_number, occurrence_count, level,
                    last_occurred_at
             FROM error_logs 
             WHERE {$dateCondition}
             ORDER BY occurrence_count DESC 
             LIMIT 10"
        )->fetchAll(\PDO::FETCH_OBJ);

        // خطاهای حل نشده
        $unresolved = $this->db->query(
            "SELECT COUNT(*) as count FROM error_logs 
             WHERE is_resolved = 0 AND {$dateCondition}"
        )->fetch(\PDO::FETCH_OBJ);

        return [
            'total' => $total->count ?? 0,
            'by_level' => $byLevel,
            'top_errors' => $topErrors,
            'unresolved' => $unresolved->count ?? 0
        ];
    }

    /**
     * گروه‌بندی خطاها
     */
    public function getGroupedErrors(int $page = 1, int $perPage = 20, ?string $level = null): array
    {
        $offset = ($page - 1) * $perPage;
        $where = "WHERE 1=1";
        $params = [];

        if ($level) {
            $where .= " AND level = ?";
            $params[] = $level;
        }

        $total = $this->db->query(
            "SELECT COUNT(*) FROM error_logs {$where}",
            $params
        )->fetchColumn();

        $errors = $this->db->query(
            "SELECT *, 
                    (SELECT COUNT(*) FROM error_logs e2 WHERE e2.error_hash = error_logs.error_hash) as similar_count
             FROM error_logs 
             {$where}
             ORDER BY last_occurred_at DESC 
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll(\PDO::FETCH_OBJ);

        return [
            'errors' => $errors,
            'total' => $total,
            'page' => $page,
            'totalPages' => ceil($total / $perPage)
        ];
    }

    /**
     * علامت‌گذاری خطا به عنوان حل شده
     */
    public function resolveError(int $errorId, int $resolvedBy, string $note = ''): bool
    {
        $result = $this->db->query(
            "UPDATE error_logs 
             SET is_resolved = 1, 
                 resolved_by = ?,
                 resolved_at = NOW(),
                 resolution_note = ?
             WHERE id = ?",
            [$resolvedBy, $note, $errorId]
        );

        return $result->rowCount() > 0;
    }

    /**
     * ایجاد هشدار سیستمی
     */
    private function createAlert(string $title, string $message, string $severity, array $metadata = []): void
    {
        try {
            $this->db->query(
                "INSERT INTO system_alerts 
                (alert_type, severity, title, message, metadata)
                VALUES ('custom', ?, ?, ?, ?)",
                [
                    $severity,
                    $title,
                    $message,
                    json_encode($metadata, JSON_UNESCAPED_UNICODE)
                ]
            );

            // ارسال نوتیفیکیشن
            $notificationService = new NotificationService($this->db);
            $notificationService->sendAlert($title, $message, $severity);

        } catch (\Throwable $e) {
            // سایلنت fail
            $this->logger->error('Failed to create alert: ' . $e->getMessage());
        }
    }

    /**
     * تحلیل خطاها و پیشنهاد راه‌حل
     */
    public function analyzeError(int $errorId): array
    {
        $error = $this->db->query(
            "SELECT * FROM error_logs WHERE id = ?",
            [$errorId]
        )->fetch(\PDO::FETCH_OBJ);

        if (!$error) {
            return ['error' => 'خطا یافت نشد'];
        }

        $suggestions = [];

        // تحلیل Column not found
        if (strpos($error->message, 'Column not found') !== false) {
            preg_match("/Unknown column '([^']+)'/", $error->message, $matches);
            if (isset($matches[1])) {
                $suggestions[] = "ستون '{$matches[1]}' در جدول وجود ندارد. Migration را اجرا کنید.";
            }
        }

        // تحلیل Undefined method
        if (strpos($error->message, 'Undefined method') !== false || 
            strpos($error->message, 'Call to undefined method') !== false) {
            preg_match("/method ([^(]+)/", $error->message, $matches);
            if (isset($matches[1])) {
                $suggestions[] = "متد '{$matches[1]}' تعریف نشده است. آن را در کلاس مربوطه اضافه کنید.";
            }
        }

        // تحلیل Table not found
        if (strpos($error->message, 'Table') !== false && 
            strpos($error->message, "doesn't exist") !== false) {
            preg_match("/Table '([^']+)'/", $error->message, $matches);
            if (isset($matches[1])) {
                $suggestions[] = "جدول '{$matches[1]}' وجود ندارد. Migration مربوطه را اجرا کنید.";
            }
        }

        return [
            'error' => $error,
            'suggestions' => $suggestions,
            'similar_errors' => $this->getSimilarErrors($error->error_hash, 5)
        ];
    }

    /**
     * خطاهای مشابه
     */
    private function getSimilarErrors(string $errorHash, int $limit = 5): array
    {
        return $this->db->query(
            "SELECT * FROM error_logs 
             WHERE error_hash = ? 
             ORDER BY created_at DESC 
             LIMIT {$limit}",
            [$errorHash]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * پاکسازی خطاهای قدیمی
     */
    public function cleanup(int $days = 30): int
    {
        $result = $this->db->query(
            "DELETE FROM error_logs 
             WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
             AND is_resolved = 1",
            [$days]
        );

        return $result->rowCount();
    }
}
