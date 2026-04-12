<?php

namespace App\Services;

use App\Contracts\LoggerInterface;
use Core\Database;

/**
 * Logger Service - سیستم یکپارچه لاگ
 * 
 * جایگزین کامل logger() و log_activity() و log_security_event()
 */
class Logger implements LoggerInterface
{
    private Database $db;
    private string $logDir;
    private bool $logToFile;
    private bool $logToDatabase;
    private string $minLevel;
    private ?int $userId = null;

    private const LEVELS = [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7,
    ];

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->logDir = dirname(__DIR__, 2) . '/storage/logs/';
        $this->logToFile = true;
        $this->logToDatabase = true;
        $this->minLevel = 'debug';

        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
    }

    // PSR-3 Methods
    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * متد اصلی log
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);

        if (!$this->shouldLog($level)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');

        // لاگ به فایل
        if ($this->logToFile) {
            $this->writeToFile($level, $message, $context, $timestamp);
        }

        // لاگ خطاهای مهم به دیتابیس
        if ($this->logToDatabase && in_array($level, ['emergency', 'alert', 'critical', 'error'])) {
            $this->writeToDatabase($level, $message, $context, $timestamp);
        }
    }

    /**
     * Set User Context
     */
    public function setUserId(?int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * بررسی min level
     */
    private function shouldLog(string $level): bool
    {
        if (!isset(self::LEVELS[$level])) {
            return true;
        }
        return self::LEVELS[$level] <= (self::LEVELS[$this->minLevel] ?? 7);
    }

    /**
     * نوشتن به فایل
     */
    private function writeToFile(string $level, string $message, array $context, string $timestamp): void
    {
        try {
            $logFile = $this->logDir . date('Y-m-d') . '.log';
            $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            $userStr = $this->userId ? " [user:{$this->userId}]" : '';
            $line = "[{$timestamp}] [" . strtoupper($level) . "]{$userStr} {$message}{$contextStr}" . PHP_EOL;
            
            @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            error_log("Logger::writeToFile failed: " . $e->getMessage());
        }
    }

    /**
     * نوشتن به دیتابیس
     */
    private function writeToDatabase(string $level, string $message, array $context, string $timestamp): void
    {
        try {
            $userId = $this->userId ?? $this->getUserId();
            
            $stmt = $this->db->query(
                "INSERT INTO system_logs (level, message, context, user_id, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $level,
                    mb_substr($message, 0, 1000),
                    json_encode($context, JSON_UNESCAPED_UNICODE),
                    $userId,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
                    $timestamp
                ]
            );
        } catch (\Throwable $e) {
            error_log("Logger::writeToDatabase failed: " . $e->getMessage());
        }
    }

    /**
     * دریافت User ID
     */
    private function getUserId(): ?int
    {
        try {
            if (function_exists('user_id')) {
                return user_id();
            }
            $session = \Core\Session::getInstance();
            return $session->get('user_id') ? (int)$session->get('user_id') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * پاکسازی لاگ‌های قدیمی
     */
    public function cleanOldLogs(int $daysToKeep = 30): int
    {
        $deleted = 0;
        $cutoffDate = date('Y-m-d', strtotime("-{$daysToKeep} days"));

        try {
            $files = glob($this->logDir . '*.log');
            foreach ($files as $file) {
                if (preg_match('/^(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $m)) {
                    if ($m[1] < $cutoffDate && @unlink($file)) {
                        $deleted++;
                    }
                }
            }

            // حذف از دیتابیس
            $stmt = $this->db->query(
                "DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
                [$daysToKeep]
            );

            if ($stmt instanceof \PDOStatement) {
                $deleted += $stmt->rowCount();
            }
        } catch (\Throwable $e) {
            $this->error('Failed to clean old logs', ['error' => $e->getMessage()]);
        }

        return $deleted;
    }

    /**
     * دریافت لاگ‌های سیستم
     */
    public function getSystemLogs(
        int $page = 1,
        int $perPage = 50,
        ?string $level = null,
        ?int $userId = null
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];

        if ($level) {
            $where[] = 'level = ?';
            $params[] = $level;
        }
        if ($userId) {
            $where[] = 'user_id = ?';
            $params[] = $userId;
        }

        $whereClause = implode(' AND ', $where);

        try {
            // Count
            $countStmt = $this->db->query(
                "SELECT COUNT(*) FROM system_logs WHERE {$whereClause}",
                $params
            );
            $total = $countStmt instanceof \PDOStatement ? (int)$countStmt->fetchColumn() : 0;

            // Data
            $dataStmt = $this->db->query(
                "SELECT sl.*, u.full_name, u.email
                 FROM system_logs sl
                 LEFT JOIN users u ON sl.user_id = u.id
                 WHERE {$whereClause}
                 ORDER BY sl.created_at DESC
                 LIMIT ? OFFSET ?",
                [...$params, $perPage, $offset]
            );

            $logs = $dataStmt instanceof \PDOStatement ? $dataStmt->fetchAll(\PDO::FETCH_OBJ) : [];

            return [
                'logs' => $logs,
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => (int)ceil($total / $perPage),
            ];
        } catch (\Throwable) {
            return ['logs' => [], 'total' => 0, 'page' => 1, 'perPage' => $perPage, 'totalPages' => 1];
        }
    }
}
