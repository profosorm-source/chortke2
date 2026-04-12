<?php

namespace App\Services\Sentry\Alerting;

use Core\Database;
use Core\Logger;

/**
 * 🎯 AlertRulesEngine - موتور پردازش قوانین هشدار
 * 
 * قابلیت‌ها:
 * - Evaluation قوانین به صورت Real-time
 * - Threshold Management
 * - Time Window Analysis
 * - Auto-trigger Alerts
 * - Rule Optimization
 */
class AlertRulesEngine
{
    private Database $db;
    private Logger $logger;
    private AlertDispatcher $dispatcher;
    
    private array $cache = [];

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->logger = Logger::getInstance()->withChannel('alert-rules');
        $this->dispatcher = new AlertDispatcher($db);
    }

    /**
     * ✅ Evaluate All Rules - ارزیابی همه قوانین
     */
    public function evaluateAllRules(): array
    {
        $activeRules = $this->getActiveRules();
        $triggered = [];

        foreach ($activeRules as $rule) {
            if ($this->evaluateRule($rule)) {
                $triggered[] = $rule;
                $this->triggerRule($rule);
            }
        }

        return $triggered;
    }

    /**
     * 🔍 Evaluate Rule - ارزیابی یک قانون
     */
    public function evaluateRule(object $rule): bool
    {
        try {
            $condition = json_decode($rule->condition, true);
            $metric = $condition['metric'] ?? null;
            $operator = $condition['operator'] ?? '>';

            if (!$metric) {
                return false;
            }

            // دریافت مقدار فعلی metric
            $currentValue = $this->getMetricValue($metric, $rule->time_window);

            // مقایسه با threshold
            return $this->compareValues($currentValue, $operator, $rule->threshold);

        } catch (\Throwable $e) {
            $this->logger->error('Rule evaluation failed', [
                'rule_id' => $rule->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 📊 Get Metric Value
     */
    private function getMetricValue(string $metric, int $timeWindow): float
    {
        // Cache key
        $cacheKey = "{$metric}_{$timeWindow}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $value = match($metric) {
            'error_count' => $this->getErrorCount($timeWindow),
            'critical_errors' => $this->getCriticalErrorCount($timeWindow),
            'slow_requests' => $this->getSlowRequestCount($timeWindow),
            'avg_response_time' => $this->getAverageResponseTime($timeWindow),
            'memory_usage' => $this->getMemoryUsage($timeWindow),
            'query_count' => $this->getQueryCount($timeWindow),
            'similar_queries' => $this->getSimilarQueriesCount($timeWindow),
            'failed_login' => $this->getFailedLoginCount($timeWindow),
            'active_users' => $this->getActiveUsersCount($timeWindow),
            default => 0
        };

        // Cache برای 1 دقیقه
        $this->cache[$cacheKey] = $value;
        
        return $value;
    }

    /**
     * 🔢 Compare Values
     */
    private function compareValues(float $current, string $operator, float $threshold): bool
    {
        return match($operator) {
            '>' => $current > $threshold,
            '>=' => $current >= $threshold,
            '<' => $current < $threshold,
            '<=' => $current <= $threshold,
            '==' => abs($current - $threshold) < 0.01,
            '!=' => abs($current - $threshold) >= 0.01,
            default => false
        };
    }

    /**
     * 🚨 Trigger Rule
     */
    private function triggerRule(object $rule): void
    {
        try {
            // بررسی آخرین trigger
            if ($this->wasRecentlyTriggered($rule->id, $rule->time_window)) {
                $this->logger->info('Rule recently triggered, skipping', ['rule_id' => $rule->id]);
                return;
            }

            // ارسال Alert
            $this->dispatcher->dispatch([
                'type' => $rule->rule_type,
                'severity' => $rule->severity,
                'title' => "Alert: {$rule->rule_name}",
                'message' => $this->formatRuleMessage($rule),
                'metadata' => [
                    'rule_id' => $rule->id,
                    'rule_name' => $rule->rule_name,
                    'threshold' => $rule->threshold,
                    'time_window' => $rule->time_window,
                ],
            ]);

            // آپدیت last_triggered_at
            $this->updateLastTriggered($rule->id);

        } catch (\Throwable $e) {
            $this->logger->error('Failed to trigger rule', [
                'rule_id' => $rule->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 📝 Format Rule Message
     */
    private function formatRuleMessage(object $rule): string
    {
        $condition = json_decode($rule->condition, true);
        $metric = $condition['metric'] ?? 'unknown';
        $operator = $condition['operator'] ?? '>';
        
        $currentValue = $this->getMetricValue($metric, $rule->time_window);
        
        return sprintf(
            "Rule '%s' triggered: %s %s %.2f (threshold: %.2f) in last %d minutes",
            $rule->rule_name,
            $metric,
            $operator,
            $currentValue,
            $rule->threshold,
            $rule->time_window
        );
    }

    /**
     * ⏰ Was Recently Triggered
     */
    private function wasRecentlyTriggered(int $ruleId, int $timeWindow): bool
    {
        $rule = $this->db->query(
            "SELECT last_triggered_at FROM alert_rules WHERE id = ?",
            [$ruleId]
        )->fetch(\PDO::FETCH_OBJ);

        if (!$rule || !$rule->last_triggered_at) {
            return false;
        }

        $lastTriggered = strtotime($rule->last_triggered_at);
        $cooldown = $timeWindow * 60 * 2; // 2x time window

        return (time() - $lastTriggered) < $cooldown;
    }

    /**
     * 🔄 Update Last Triggered
     */
    private function updateLastTriggered(int $ruleId): void
    {
        $this->db->query(
            "UPDATE alert_rules SET last_triggered_at = NOW() WHERE id = ?",
            [$ruleId]
        );
    }

    /**
     * 📋 Get Active Rules
     */
    private function getActiveRules(): array
    {
        return $this->db->query(
            "SELECT * FROM alert_rules WHERE is_active = 1 ORDER BY severity DESC"
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    // ==========================================
    // Metric Getters
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

    private function getCriticalErrorCount(int $minutes): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM sentry_events 
             WHERE level IN ('critical', 'fatal')
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        )->fetch(\PDO::FETCH_OBJ);
        
        return (int)($result->count ?? 0);
    }

    private function getSlowRequestCount(int $minutes): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM performance_transactions 
             WHERE duration > 1000
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        )->fetch(\PDO::FETCH_OBJ);
        
        return (int)($result->count ?? 0);
    }

    private function getAverageResponseTime(int $minutes): float
    {
        $result = $this->db->query(
            "SELECT AVG(duration) as avg_time FROM performance_transactions 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        )->fetch(\PDO::FETCH_OBJ);
        
        return (float)($result->avg_time ?? 0);
    }

    private function getMemoryUsage(int $minutes): float
    {
        $result = $this->db->query(
            "SELECT AVG(memory_used) as avg_memory FROM performance_transactions 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        )->fetch(\PDO::FETCH_OBJ);
        
        return (float)($result->avg_memory ?? 0);
    }

    private function getQueryCount(int $minutes): float
    {
        $result = $this->db->query(
            "SELECT AVG(query_count) as avg_queries FROM performance_transactions 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        )->fetch(\PDO::FETCH_OBJ);
        
        return (float)($result->avg_queries ?? 0);
    }

    private function getSimilarQueriesCount(int $minutes): int
    {
        // تعداد transactionهایی که N+1 query داشتن
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM performance_transactions 
             WHERE JSON_LENGTH(issues) > 0
             AND JSON_SEARCH(issues, 'one', 'n_plus_one_query', null, '$[*].type') IS NOT NULL
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        )->fetch(\PDO::FETCH_OBJ);
        
        return (int)($result->count ?? 0);
    }

    private function getFailedLoginCount(int $minutes): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM security_logs 
             WHERE event_type = 'login_attempt'
             AND severity = 'danger'
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        )->fetch(\PDO::FETCH_OBJ);
        
        return (int)($result->count ?? 0);
    }

    private function getActiveUsersCount(int $minutes): int
    {
        $result = $this->db->query(
            "SELECT COUNT(DISTINCT user_id) as count FROM user_sessions 
             WHERE last_activity >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        )->fetch(\PDO::FETCH_OBJ);
        
        return (int)($result->count ?? 0);
    }

    /**
     * 🧹 Clear Cache
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }
}
