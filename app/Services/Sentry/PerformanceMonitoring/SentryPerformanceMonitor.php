<?php

namespace App\Services\Sentry\PerformanceMonitoring;

use Core\Database;
use Core\Logger;

/**
 * 🚀 SentryPerformanceMonitor - مانیتورینگ عملکرد سیستم
 * 
 * قابلیت‌ها:
 * - Transaction Tracing (ردیابی کامل یک request)
 * - Real User Monitoring (RUM)
 * - Database Query Profiling
 * - N+1 Query Detection
 * - Slow Query Analysis
 * - Memory Leak Detection
 * - API Latency Tracking
 */
class SentryPerformanceMonitor
{
    private Database $db;
    private Logger $logger;
    
    private ?array $currentTransaction = null;
    private array $spans = [];
    private array $queries = [];
    private float $startTime;
    private int $startMemory;
    
    private array $config = [
        'enabled' => true,
        'sample_rate' => 1.0,
        'slow_threshold' => 1000, // ms
        'memory_threshold' => 50 * 1024 * 1024, // 50MB
    ];

    public function __construct(Database $db, array $config = [])
    {
        $this->db = $db;
        $this->logger = Logger::getInstance()->withChannel('performance');
        $this->config = array_merge($this->config, $config);
        
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);
    }

    /**
     * 🎬 Start Transaction - شروع transaction جدید
     */
    public function startTransaction(
        string $name,
        string $op = 'http.request',
        array $data = []
    ): ?string {
        if (!$this->config['enabled'] || !$this->shouldSample()) {
            return null;
        }

        $transactionId = $this->generateId();

        $this->currentTransaction = [
            'transaction_id' => $transactionId,
            'name' => $name,
            'op' => $op,
            'start_timestamp' => microtime(true),
            'data' => $data,
            'tags' => [],
        ];

        return $transactionId;
    }

    /**
     * 🏁 Finish Transaction
     */
    public function finishTransaction(array $context = []): void
    {
        if (!$this->currentTransaction) {
            return;
        }

        $duration = (microtime(true) - $this->currentTransaction['start_timestamp']) * 1000;
        $memoryUsed = memory_get_usage(true) - $this->startMemory;
        $peakMemory = memory_get_peak_usage(true);

        $transaction = array_merge($this->currentTransaction, [
            'duration' => $duration,
            'memory_used' => $memoryUsed,
            'peak_memory' => $peakMemory,
            'spans' => $this->spans,
            'queries' => $this->queries,
            'query_count' => count($this->queries),
            'slow_queries_count' => $this->countSlowQueries(),
            'status' => $this->determineStatus($context),
            'context' => $context,
        ]);

        // تشخیص مشکلات
        $issues = $this->detectPerformanceIssues($transaction);
        if (!empty($issues)) {
            $transaction['issues'] = $issues;
        }

        // ذخیره
        $this->storeTransaction($transaction);

        // Reset
        $this->reset();
    }

    /**
     * ⏱️ Start Span - شروع یک span (بخش کوچک‌تر از transaction)
     */
    public function startSpan(
        string $op,
        string $description,
        array $data = []
    ): string {
        $spanId = $this->generateId();

        $this->spans[$spanId] = [
            'span_id' => $spanId,
            'op' => $op,
            'description' => $description,
            'start_timestamp' => microtime(true),
            'data' => $data,
        ];

        return $spanId;
    }

    /**
     * ✅ Finish Span
     */
    public function finishSpan(string $spanId, array $data = []): void
    {
        if (!isset($this->spans[$spanId])) {
            return;
        }

        $duration = (microtime(true) - $this->spans[$spanId]['start_timestamp']) * 1000;
        
        $this->spans[$spanId] = array_merge($this->spans[$spanId], [
            'duration' => $duration,
            'data' => array_merge($this->spans[$spanId]['data'], $data),
        ]);

        unset($this->spans[$spanId]['start_timestamp']);
    }

    /**
     * 🗄️ Track Query - ردیابی query دیتابیس
     */
    public function trackQuery(
        string $query,
        float $duration,
        ?array $params = null
    ): void {
        $this->queries[] = [
            'query' => $this->sanitizeQuery($query),
            'duration' => $duration,
            'params_count' => $params ? count($params) : 0,
            'is_slow' => $duration > 100, // بیش از 100ms
        ];
    }

    /**
     * 🔍 Detect N+1 Queries
     */
    public function detectNPlusOneQueries(): array
    {
        $similarQueries = [];
        
        foreach ($this->queries as $query) {
            $pattern = $this->getQueryPattern($query['query']);
            
            if (!isset($similarQueries[$pattern])) {
                $similarQueries[$pattern] = [];
            }
            
            $similarQueries[$pattern][] = $query;
        }

        $nPlusOnes = [];
        foreach ($similarQueries as $pattern => $queries) {
            if (count($queries) > 5) { // بیش از 5 query مشابه
                $nPlusOnes[] = [
                    'pattern' => $pattern,
                    'count' => count($queries),
                    'total_duration' => array_sum(array_column($queries, 'duration')),
                ];
            }
        }

        return $nPlusOnes;
    }

    /**
     * 🐌 Get Slow Queries
     */
    public function getSlowQueries(float $threshold = 100): array
    {
        return array_filter(
            $this->queries,
            fn($q) => $q['duration'] > $threshold
        );
    }

    /**
     * 💾 Detect Memory Leak
     */
    public function detectMemoryLeak(): ?array
    {
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        $memoryGrowth = $currentMemory - $this->startMemory;

        if ($memoryGrowth > $this->config['memory_threshold']) {
            return [
                'type' => 'memory_leak',
                'severity' => 'high',
                'start_memory' => $this->startMemory,
                'current_memory' => $currentMemory,
                'peak_memory' => $peakMemory,
                'growth' => $memoryGrowth,
                'growth_mb' => round($memoryGrowth / 1024 / 1024, 2),
            ];
        }

        return null;
    }

    /**
     * 🚨 Detect Performance Issues
     */
    private function detectPerformanceIssues(array $transaction): array
    {
        $issues = [];

        // Slow Transaction
        if ($transaction['duration'] > $this->config['slow_threshold']) {
            $issues[] = [
                'type' => 'slow_transaction',
                'severity' => $transaction['duration'] > 3000 ? 'high' : 'medium',
                'message' => 'Transaction took ' . round($transaction['duration']) . 'ms',
                'threshold' => $this->config['slow_threshold'],
                'actual' => $transaction['duration'],
            ];
        }

        // N+1 Queries
        $nPlusOnes = $this->detectNPlusOneQueries();
        if (!empty($nPlusOnes)) {
            foreach ($nPlusOnes as $nPlusOne) {
                $issues[] = [
                    'type' => 'n_plus_one_query',
                    'severity' => 'high',
                    'message' => "Detected {$nPlusOne['count']} similar queries",
                    'pattern' => $nPlusOne['pattern'],
                    'count' => $nPlusOne['count'],
                    'total_duration' => $nPlusOne['total_duration'],
                ];
            }
        }

        // Slow Queries
        $slowQueries = $this->getSlowQueries(100);
        if (!empty($slowQueries)) {
            $issues[] = [
                'type' => 'slow_queries',
                'severity' => 'medium',
                'message' => count($slowQueries) . ' slow queries detected',
                'count' => count($slowQueries),
                'queries' => array_slice($slowQueries, 0, 5), // فقط 5 تای اول
            ];
        }

        // Memory Leak
        $memoryLeak = $this->detectMemoryLeak();
        if ($memoryLeak) {
            $issues[] = $memoryLeak;
        }

        // Too Many Queries
        if (count($this->queries) > 50) {
            $issues[] = [
                'type' => 'too_many_queries',
                'severity' => 'medium',
                'message' => count($this->queries) . ' queries in single request',
                'count' => count($this->queries),
            ];
        }

        return $issues;
    }

    /**
     * 💾 Store Transaction
     */
    private function storeTransaction(array $transaction): void
    {
        try {
            $this->db->query(
                "INSERT INTO performance_transactions (
                    transaction_id, name, op, duration, memory_used,
                    peak_memory, query_count, slow_queries_count,
                    status, spans, queries, issues, context, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $transaction['transaction_id'],
                    $transaction['name'],
                    $transaction['op'],
                    $transaction['duration'],
                    $transaction['memory_used'],
                    $transaction['peak_memory'],
                    $transaction['query_count'],
                    $transaction['slow_queries_count'],
                    $transaction['status'],
                    json_encode($transaction['spans']),
                    json_encode($transaction['queries']),
                    json_encode($transaction['issues'] ?? []),
                    json_encode($transaction['context']),
                ]
            );

            // اگر issue داشت، alert بفرست
            if (!empty($transaction['issues'])) {
                $this->handlePerformanceAlert($transaction);
            }

        } catch (\Throwable $e) {
            $this->logger->error('Failed to store transaction', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 🚨 Handle Performance Alert
     */
    private function handlePerformanceAlert(array $transaction): void
    {
        foreach ($transaction['issues'] as $issue) {
            if ($issue['severity'] === 'high') {
                // ارسال alert فقط برای issueهای مهم
                $alertDispatcher = new \App\Services\Sentry\Alerting\AlertDispatcher($this->db);
                
                $alertDispatcher->dispatch([
                    'type' => 'performance',
                    'severity' => $issue['severity'],
                    'title' => 'Performance Issue: ' . $issue['type'],
                    'message' => $issue['message'],
                    'metadata' => [
                        'transaction' => $transaction['name'],
                        'duration' => $transaction['duration'],
                        'issue_type' => $issue['type'],
                    ],
                ]);
            }
        }
    }

    /**
     * 🧹 Sanitize Query
     */
    private function sanitizeQuery(string $query): string
    {
        // حذف parametersها
        $sanitized = preg_replace('/\bVALUES\s*\([^)]+\)/i', 'VALUES (?)', $query);
        $sanitized = preg_replace('/= ?\?/', '= ?', $sanitized);
        
        return substr($sanitized, 0, 500);
    }

    /**
     * 🔍 Get Query Pattern
     */
    private function getQueryPattern(string $query): string
    {
        // حذف مقادیر برای pattern matching
        $pattern = preg_replace('/\d+/', 'N', $query);
        $pattern = preg_replace('/\'[^\']*\'/', '?', $pattern);
        $pattern = preg_replace('/\s+/', ' ', $pattern);
        
        return trim($pattern);
    }

    /**
     * 🎲 Generate ID
     */
    private function generateId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * 🎯 Should Sample
     */
    private function shouldSample(): bool
    {
        return (mt_rand() / mt_getrandmax()) <= $this->config['sample_rate'];
    }

    /**
     * 📊 Count Slow Queries
     */
    private function countSlowQueries(): int
    {
        return count(array_filter($this->queries, fn($q) => $q['is_slow']));
    }

    /**
     * 🚦 Determine Status
     */
    private function determineStatus(array $context): string
    {
        $statusCode = $context['status_code'] ?? 200;
        
        if ($statusCode >= 500) return 'internal_error';
        if ($statusCode >= 400) return 'invalid_argument';
        if ($statusCode >= 300) return 'redirect';
        
        return 'ok';
    }

    /**
     * 🔄 Reset
     */
    private function reset(): void
    {
        $this->currentTransaction = null;
        $this->spans = [];
        $this->queries = [];
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
                COUNT(*) as total_transactions,
                AVG(duration) as avg_duration,
                MAX(duration) as max_duration,
                AVG(query_count) as avg_queries,
                SUM(CASE WHEN slow_queries_count > 0 THEN 1 ELSE 0 END) as transactions_with_slow_queries,
                AVG(memory_used) as avg_memory
             FROM performance_transactions
             WHERE {$dateCondition}",
            []
        )->fetch(\PDO::FETCH_OBJ);

        return [
            'total_transactions' => $stats->total_transactions ?? 0,
            'avg_duration' => round($stats->avg_duration ?? 0, 2),
            'max_duration' => round($stats->max_duration ?? 0, 2),
            'avg_queries' => round($stats->avg_queries ?? 0, 2),
            'transactions_with_slow_queries' => $stats->transactions_with_slow_queries ?? 0,
            'avg_memory_mb' => round(($stats->avg_memory ?? 0) / 1024 / 1024, 2),
        ];
    }

    /**
     * 🐌 Get Slowest Transactions
     */
    public function getSlowestTransactions(int $limit = 10): array
    {
        return $this->db->query(
            "SELECT name, AVG(duration) as avg_duration, COUNT(*) as count
             FROM performance_transactions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY name
             ORDER BY avg_duration DESC
             LIMIT {$limit}",
            []
        )->fetchAll(\PDO::FETCH_OBJ);
    }
}
