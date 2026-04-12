<?php

namespace App\Services\Sentry\Analytics;

use Core\Database;

/**
 * 📊 DashboardService - سرویس داشبورد و آمارگیری
 * 
 * قابلیت‌ها:
 * - Real-time Metrics
 * - Trend Analysis
 * - Health Score
 * - Performance Overview
 * - Error Analytics
 */
class DashboardService
{
    private Database $db;
    
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * 📊 Get Dashboard Overview
     */
    public function getOverview(): array
    {
        return [
            'summary' => $this->getSummary(),
            'health_score' => $this->calculateHealthScore(),
            'error_stats' => $this->getErrorStatistics(),
            'performance_stats' => $this->getPerformanceStatistics(),
            'trending_issues' => $this->getTrendingIssues(),
            'recent_events' => $this->getRecentEvents(),
        ];
    }

    /**
     * 📈 Get Summary
     */
    public function getSummary(): array
    {
        $today = $this->db->query(
            "SELECT 
                COUNT(DISTINCT se.issue_id) as error_issues,
                COUNT(se.id) as error_events,
                (SELECT COUNT(*) FROM performance_transactions WHERE DATE(created_at) = CURDATE()) as transactions,
                (SELECT AVG(duration) FROM performance_transactions WHERE DATE(created_at) = CURDATE()) as avg_response_time
             FROM sentry_events se
             WHERE DATE(se.created_at) = CURDATE()"
        )->fetch(\PDO::FETCH_OBJ);

        $yesterday = $this->db->query(
            "SELECT 
                COUNT(DISTINCT issue_id) as error_issues,
                COUNT(id) as error_events
             FROM sentry_events
             WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)"
        )->fetch(\PDO::FETCH_OBJ);

        return [
            'today' => [
                'error_issues' => (int)$today->error_issues,
                'error_events' => (int)$today->error_events,
                'transactions' => (int)$today->transactions,
                'avg_response_time' => round($today->avg_response_time ?? 0, 2),
            ],
            'yesterday' => [
                'error_issues' => (int)$yesterday->error_issues,
                'error_events' => (int)$yesterday->error_events,
            ],
            'change' => [
                'error_issues' => $this->calculateChange(
                    (int)$today->error_issues, 
                    (int)$yesterday->error_issues
                ),
                'error_events' => $this->calculateChange(
                    (int)$today->error_events, 
                    (int)$yesterday->error_events
                ),
            ],
        ];
    }

    /**
     * 💚 Calculate Health Score (0-100)
     */
    public function calculateHealthScore(): array
    {
        $weights = [
            'error_rate' => 0.35,
            'performance' => 0.25,
            'uptime' => 0.20,
            'response_time' => 0.20,
        ];

        // 1. Error Rate Score (کمتر بهتر)
        $errorCount = $this->getErrorCount(60); // آخرین ساعت
        $errorScore = max(0, 100 - ($errorCount * 2));

        // 2. Performance Score
        $avgDuration = $this->getAverageResponseTime(60);
        $performanceScore = max(0, 100 - ($avgDuration / 20)); // <2000ms = 100

        // 3. Uptime Score
        $uptime = $this->getUptime();
        $uptimeScore = $uptime;

        // 4. Response Time Score
        $p95Duration = $this->getP95ResponseTime(60);
        $responseScore = max(0, 100 - ($p95Duration / 30));

        $totalScore = 
            ($errorScore * $weights['error_rate']) +
            ($performanceScore * $weights['performance']) +
            ($uptimeScore * $weights['uptime']) +
            ($responseScore * $weights['response_time']);

        return [
            'score' => round($totalScore, 1),
            'grade' => $this->getHealthGrade($totalScore),
            'status' => $this->getHealthStatus($totalScore),
            'components' => [
                'error_rate' => round($errorScore, 1),
                'performance' => round($performanceScore, 1),
                'uptime' => round($uptimeScore, 1),
                'response_time' => round($responseScore, 1),
            ],
        ];
    }

    /**
     * 🚨 Get Error Statistics
     */
    public function getErrorStatistics(): array
    {
        $stats = $this->db->query(
            "SELECT 
                level,
                COUNT(DISTINCT issue_id) as issues,
                COUNT(*) as events
             FROM sentry_events
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY level"
        )->fetchAll(\PDO::FETCH_OBJ);

        $result = [
            'total_issues' => 0,
            'total_events' => 0,
            'by_level' => [],
        ];

        foreach ($stats as $stat) {
            $result['total_issues'] += $stat->issues;
            $result['total_events'] += $stat->events;
            $result['by_level'][$stat->level] = [
                'issues' => (int)$stat->issues,
                'events' => (int)$stat->events,
            ];
        }

        return $result;
    }

    /**
     * 🚀 Get Performance Statistics
     */
    public function getPerformanceStatistics(): array
    {
        $stats = $this->db->query(
            "SELECT 
                COUNT(*) as total_transactions,
                AVG(duration) as avg_duration,
                MAX(duration) as max_duration,
                AVG(query_count) as avg_queries,
                SUM(CASE WHEN duration > 1000 THEN 1 ELSE 0 END) as slow_count
             FROM performance_transactions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        )->fetch(\PDO::FETCH_OBJ);

        return [
            'total_transactions' => (int)$stats->total_transactions,
            'avg_duration' => round($stats->avg_duration ?? 0, 2),
            'max_duration' => round($stats->max_duration ?? 0, 2),
            'avg_queries' => round($stats->avg_queries ?? 0, 2),
            'slow_count' => (int)$stats->slow_count,
            'slow_percentage' => $stats->total_transactions > 0 
                ? round(($stats->slow_count / $stats->total_transactions) * 100, 2)
                : 0,
        ];
    }

    /**
     * 🔥 Get Trending Issues
     */
    public function getTrendingIssues(int $limit = 10): array
    {
        return $this->db->query(
            "SELECT 
                i.id,
                i.title,
                i.level,
                i.count,
                i.last_seen,
                COUNT(e.id) as events_24h,
                i.environment
             FROM sentry_issues i
             LEFT JOIN sentry_events e ON e.issue_id = i.id 
                AND e.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             WHERE i.status = 'unresolved'
             GROUP BY i.id
             ORDER BY events_24h DESC, i.count DESC
             LIMIT ?",
            [$limit]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * 🕐 Get Recent Events
     */
    public function getRecentEvents(int $limit = 20): array
    {
        return $this->db->query(
            "SELECT 
                e.*,
                i.title as issue_title,
                u.full_name as user_name
             FROM sentry_events e
             INNER JOIN sentry_issues i ON i.id = e.issue_id
             LEFT JOIN users u ON u.id = e.user_id
             ORDER BY e.created_at DESC
             LIMIT ?",
            [$limit]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * 📈 Get Time Series Data
     */
    public function getTimeSeriesData(string $metric, string $period = '24h', string $interval = '1h'): array
    {
        $intervalMinutes = match($interval) {
            '5m' => 5,
            '15m' => 15,
            '30m' => 30,
            '1h' => 60,
            '6h' => 360,
            '1d' => 1440,
            default => 60
        };

        $periodHours = match($period) {
            '1h' => 1,
            '6h' => 6,
            '12h' => 12,
            '24h' => 24,
            '7d' => 168,
            '30d' => 720,
            default => 24
        };

        if ($metric === 'errors') {
            return $this->getErrorTimeSeries($periodHours, $intervalMinutes);
        } elseif ($metric === 'performance') {
            return $this->getPerformanceTimeSeries($periodHours, $intervalMinutes);
        }

        return [];
    }

    /**
     * 📉 Get Error Time Series
     */
    private function getErrorTimeSeries(int $hours, int $intervalMinutes): array
    {
        $data = $this->db->query(
            "SELECT 
                DATE_FORMAT(
                    FROM_UNIXTIME(
                        FLOOR(UNIX_TIMESTAMP(created_at) / (? * 60)) * (? * 60)
                    ),
                    '%Y-%m-%d %H:%i:00'
                ) as time_bucket,
                COUNT(*) as count,
                level
             FROM sentry_events
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY time_bucket, level
             ORDER BY time_bucket ASC",
            [$intervalMinutes, $intervalMinutes, $hours]
        )->fetchAll(\PDO::FETCH_OBJ);

        return $this->formatTimeSeriesData($data);
    }

    /**
     * 📊 Get Performance Time Series
     */
    private function getPerformanceTimeSeries(int $hours, int $intervalMinutes): array
    {
        $data = $this->db->query(
            "SELECT 
                DATE_FORMAT(
                    FROM_UNIXTIME(
                        FLOOR(UNIX_TIMESTAMP(created_at) / (? * 60)) * (? * 60)
                    ),
                    '%Y-%m-%d %H:%i:00'
                ) as time_bucket,
                AVG(duration) as avg_duration,
                COUNT(*) as count
             FROM performance_transactions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY time_bucket
             ORDER BY time_bucket ASC",
            [$intervalMinutes, $intervalMinutes, $hours]
        )->fetchAll(\PDO::FETCH_OBJ);

        return array_map(function($item) {
            return [
                'timestamp' => $item->time_bucket,
                'value' => round($item->avg_duration, 2),
                'count' => (int)$item->count,
            ];
        }, $data);
    }

    /**
     * 🔧 Format Time Series Data
     */
    private function formatTimeSeriesData(array $data): array
    {
        $formatted = [];
        foreach ($data as $item) {
            $formatted[] = [
                'timestamp' => $item->time_bucket,
                'value' => (int)$item->count,
                'level' => $item->level ?? null,
            ];
        }
        return $formatted;
    }

    /**
     * 🏆 Get Top Slowest Endpoints
     */
    public function getTopSlowestEndpoints(int $limit = 10): array
    {
        return $this->db->query(
            "SELECT 
                name as endpoint,
                COUNT(*) as request_count,
                AVG(duration) as avg_duration,
                MAX(duration) as max_duration,
                AVG(query_count) as avg_queries
             FROM performance_transactions
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY name
             ORDER BY avg_duration DESC
             LIMIT ?",
            [$limit]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * 🐛 Get Top Error Sources
     */
    public function getTopErrorSources(int $limit = 10): array
    {
        return $this->db->query(
            "SELECT 
                JSON_EXTRACT(stack_trace, '$[0].filename') as source_file,
                COUNT(*) as error_count,
                level
             FROM sentry_events
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             AND JSON_EXTRACT(stack_trace, '$[0].filename') IS NOT NULL
             GROUP BY source_file, level
             ORDER BY error_count DESC
             LIMIT ?",
            [$limit]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    private function getErrorCount(int $minutes): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM sentry_events 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        )->fetch(\PDO::FETCH_OBJ);
        
        return (int)($result->count ?? 0);
    }

    private function getAverageResponseTime(int $minutes): float
    {
        $result = $this->db->query(
            "SELECT AVG(duration) as avg FROM performance_transactions 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        )->fetch(\PDO::FETCH_OBJ);
        
        return (float)($result->avg ?? 0);
    }

    private function getP95ResponseTime(int $minutes): float
    {
        // محاسبه پرسنتایل 95
        $result = $this->db->query(
            "SELECT duration FROM performance_transactions 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
             ORDER BY duration DESC
             LIMIT 1 OFFSET (
                 SELECT FLOOR(COUNT(*) * 0.05) 
                 FROM performance_transactions 
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
             )",
            [$minutes, $minutes]
        )->fetch(\PDO::FETCH_OBJ);
        
        return (float)($result->duration ?? 0);
    }

    private function getUptime(): float
    {
        // فرض: اگر در 5 دقیقه اخیر transaction داشتیم، سیستم UP بوده
        $recentTransactions = $this->db->query(
            "SELECT COUNT(*) as count FROM performance_transactions 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        )->fetch(\PDO::FETCH_OBJ);
        
        return $recentTransactions->count > 0 ? 100.0 : 0.0;
    }

    private function calculateChange(int $current, int $previous): array
    {
        if ($previous == 0) {
            return [
                'value' => $current > 0 ? 100 : 0,
                'direction' => $current > 0 ? 'up' : 'stable',
            ];
        }

        $change = (($current - $previous) / $previous) * 100;
        
        return [
            'value' => round(abs($change), 1),
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'stable'),
        ];
    }

    private function getHealthGrade(float $score): string
    {
        return match(true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F'
        };
    }

    private function getHealthStatus(float $score): string
    {
        return match(true) {
            $score >= 90 => 'excellent',
            $score >= 80 => 'good',
            $score >= 70 => 'fair',
            $score >= 60 => 'poor',
            default => 'critical'
        };
    }
}
