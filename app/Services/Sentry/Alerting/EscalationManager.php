<?php

namespace App\Services\Sentry\Alerting;

use Core\Database;
use Core\Logger;

/**
 * 📈 EscalationManager - مدیریت Escalation
 * 
 * قابلیت‌ها:
 * - افزایش خودکار اولویت
 * - Escalation Chains
 * - Time-based Escalation
 * - Multi-level Notifications
 */
class EscalationManager
{
    private Database $db;
    private Logger $logger;
    private AlertDispatcher $dispatcher;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->logger = Logger::getInstance()->withChannel('escalation');
        $this->dispatcher = new AlertDispatcher($db);
    }

    /**
     * 🔄 Process Escalations
     */
    public function processEscalations(): array
    {
        $escalated = [];

        // دریافت alertهای که نیاز به escalation دارن
        $pendingAlerts = $this->getPendingEscalations();

        foreach ($pendingAlerts as $alert) {
            if ($this->shouldEscalate($alert)) {
                $this->escalateAlert($alert);
                $escalated[] = $alert;
            }
        }

        return $escalated;
    }

    /**
     * 📋 Get Pending Escalations
     */
    private function getPendingEscalations(): array
    {
        return $this->db->query(
            "SELECT * FROM system_alerts 
             WHERE is_active = 1 
             AND acknowledged_at IS NULL
             AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY severity DESC, created_at ASC"
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * ✅ Should Escalate
     */
    private function shouldEscalate(object $alert): bool
    {
        $age = time() - strtotime($alert->created_at);
        
        // Escalation times based on severity
        $escalationTime = match($alert->severity) {
            'critical' => 5 * 60,    // 5 دقیقه
            'high' => 15 * 60,       // 15 دقیقه
            'medium' => 60 * 60,     // 1 ساعت
            'low' => 4 * 60 * 60,    // 4 ساعت
            default => 60 * 60
        };

        return $age > $escalationTime;
    }

    /**
     * 📈 Escalate Alert
     */
    private function escalateAlert(object $alert): void
    {
        try {
            // افزایش severity
            $newSeverity = $this->getNextSeverity($alert->severity);
            
            // آپدیت در database
            $this->db->query(
                "UPDATE system_alerts 
                 SET severity = ?,
                     metadata = JSON_SET(
                         COALESCE(metadata, '{}'),
                         '$.escalated',
                         true,
                         '$.escalated_at',
                         NOW(),
                         '$.previous_severity',
                         ?
                     )
                 WHERE id = ?",
                [$newSeverity, $alert->severity, $alert->id]
            );

            // ارسال notification جدید با severity بالاتر
            $this->dispatcher->dispatch([
                'type' => 'escalation',
                'severity' => $newSeverity,
                'title' => "🚨 Escalated: {$alert->title}",
                'message' => $this->formatEscalationMessage($alert, $newSeverity),
                'metadata' => [
                    'original_severity' => $alert->severity,
                    'new_severity' => $newSeverity,
                    'alert_id' => $alert->id,
                    'age_minutes' => round((time() - strtotime($alert->created_at)) / 60),
                ],
            ]);

            $this->logger->warning('Alert escalated', [
                'alert_id' => $alert->id,
                'from' => $alert->severity,
                'to' => $newSeverity,
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Escalation failed', [
                'alert_id' => $alert->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 🔺 Get Next Severity
     */
    private function getNextSeverity(string $current): string
    {
        return match($current) {
            'low' => 'medium',
            'medium' => 'high',
            'high' => 'critical',
            'critical' => 'critical', // max level
            default => 'medium'
        };
    }

    /**
     * 📝 Format Escalation Message
     */
    private function formatEscalationMessage(object $alert, string $newSeverity): string
    {
        $age = round((time() - strtotime($alert->created_at)) / 60);
        
        return sprintf(
            "⚠️ Alert escalated from %s to %s\n\n" .
            "Original Alert: %s\n" .
            "Age: %d minutes\n" .
            "Status: Unacknowledged\n\n" .
            "Please investigate immediately!",
            strtoupper($alert->severity),
            strtoupper($newSeverity),
            $alert->message,
            $age
        );
    }

    /**
     * ✅ Acknowledge Alert
     */
    public function acknowledgeAlert(int $alertId, int $userId, ?string $note = null): bool
    {
        try {
            $this->db->query(
                "UPDATE system_alerts 
                 SET acknowledged_by = ?,
                     acknowledged_at = NOW(),
                     metadata = JSON_SET(
                         COALESCE(metadata, '{}'),
                         '$.acknowledged_note',
                         ?
                     )
                 WHERE id = ?",
                [$userId, $note, $alertId]
            );

            $this->logger->info('Alert acknowledged', [
                'alert_id' => $alertId,
                'user_id' => $userId,
            ]);

            return true;

        } catch (\Throwable $e) {
            $this->logger->error('Acknowledge failed', [
                'alert_id' => $alertId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 🔕 Auto-resolve Alerts
     */
    public function autoResolveAlerts(): int
    {
        try {
            // Resolve alertهایی که مشکلشون حل شده
            $resolved = 0;

            // مثال: اگر error rate دوباره نرمال شد
            $result = $this->db->query(
                "UPDATE system_alerts 
                 SET is_active = 0,
                     metadata = JSON_SET(
                         COALESCE(metadata, '{}'),
                         '$.auto_resolved',
                         true,
                         '$.resolved_at',
                         NOW()
                     )
                 WHERE alert_type = 'error'
                 AND is_active = 1
                 AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 AND NOT EXISTS (
                     SELECT 1 FROM sentry_events 
                     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                     AND level IN ('error', 'critical', 'fatal')
                 )"
            );

            $resolved = $result->rowCount();

            if ($resolved > 0) {
                $this->logger->info("Auto-resolved {$resolved} alerts");
            }

            return $resolved;

        } catch (\Throwable $e) {
            $this->logger->error('Auto-resolve failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 📊 Get Escalation Statistics
     */
    public function getStatistics(): array
    {
        $stats = $this->db->query(
            "SELECT 
                COUNT(*) as total_alerts,
                SUM(CASE WHEN acknowledged_at IS NOT NULL THEN 1 ELSE 0 END) as acknowledged,
                SUM(CASE WHEN JSON_EXTRACT(metadata, '$.escalated') = true THEN 1 ELSE 0 END) as escalated,
                AVG(TIMESTAMPDIFF(MINUTE, created_at, COALESCE(acknowledged_at, NOW()))) as avg_response_time
             FROM system_alerts
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        )->fetch(\PDO::FETCH_OBJ);

        return [
            'total_alerts' => (int)($stats->total_alerts ?? 0),
            'acknowledged' => (int)($stats->acknowledged ?? 0),
            'escalated' => (int)($stats->escalated ?? 0),
            'avg_response_time_minutes' => round($stats->avg_response_time ?? 0, 2),
        ];
    }
}
