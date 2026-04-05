<?php

namespace App\Services\AntiFraud;

use App\Services\RiskPolicyService;
use Core\Database;

class SessionAnomalyService
{
    private Database $db;
    private RiskPolicyService $policy;

    public function __construct(Database $db, RiskPolicyService $policy)
    {
        $this->db = $db;
        $this->policy = $policy;
    }

    public function analyze(int $userId, string $sessionId): array
    {
        $anomalies = [];
        $score = 0;

        $concurrentCheck = $this->checkConcurrentSessions($userId);
        if ($concurrentCheck['anomaly']) {
            $score += $this->policy->getInt('fraud', 'session.concurrent_points', 30);
            $anomalies[] = $concurrentCheck['reason'];
        }

        $uaCheck = $this->checkUserAgentChange($userId);
        if ($uaCheck['anomaly']) {
            $score += $this->policy->getInt('fraud', 'session.ua_change_points', 40);
            $anomalies[] = $uaCheck['reason'];
        }

        $geoCheck = $this->checkGeolocationChange($userId);
        if ($geoCheck['anomaly']) {
            $score += $this->policy->getInt('fraud', 'session.geo_change_points', 35);
            $anomalies[] = $geoCheck['reason'];
        }

        $timeCheck = $this->checkActivityTime($userId);
        if ($timeCheck['anomaly']) {
            $score += $this->policy->getInt('fraud', 'session.activity_time_points', 15);
            $anomalies[] = $timeCheck['reason'];
        }

        $velocityCheck = $this->checkActionVelocity($userId);
        if ($velocityCheck['anomaly']) {
            $score += $this->policy->getInt('fraud', 'session.velocity_points', 25);
            $anomalies[] = $velocityCheck['reason'];
        }

        return [
            'is_anomaly' => $score >= 50,
            'score' => min($score, 100),
            'anomalies' => $anomalies,
        ];
    }

    private function checkConcurrentSessions(int $userId): array
    {
        $threshold = $this->policy->getInt('fraud', 'session.concurrent_threshold', 3);
        $sql = 'SELECT COUNT(*) as count FROM user_sessions WHERE user_id = ? AND is_active = TRUE AND last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)';
        $result = $this->db->fetch($sql, [$userId]);
        $count = $result ? (int) $result->count : 0;

        if ($count > $threshold) {
            return ['anomaly' => true, 'reason' => "{$count} Session همزمان فعال"];
        }

        return ['anomaly' => false];
    }

    private function checkUserAgentChange(int $userId): array
    {
        $sql = 'SELECT user_agent, created_at FROM user_sessions WHERE user_id = ? ORDER BY created_at DESC LIMIT 2';
        $sessions = $this->db->fetchAll($sql, [$userId]);

        if (count($sessions) < 2) {
            return ['anomaly' => false];
        }

        $timeDiff = strtotime($sessions[0]->created_at) - strtotime($sessions[1]->created_at);
        if ($timeDiff < 300 && $sessions[0]->user_agent !== $sessions[1]->user_agent) {
            return ['anomaly' => true, 'reason' => 'تغییر ناگهانی User-Agent در کمتر از 5 دقیقه'];
        }

        return ['anomaly' => false];
    }

    private function checkGeolocationChange(int $userId): array
    {
        $sql = 'SELECT country, city, created_at FROM user_sessions WHERE user_id = ? AND country IS NOT NULL ORDER BY created_at DESC LIMIT 2';
        $sessions = $this->db->fetchAll($sql, [$userId]);

        if (count($sessions) < 2) {
            return ['anomaly' => false];
        }

        $timeDiff = strtotime($sessions[0]->created_at) - strtotime($sessions[1]->created_at);
        if ($timeDiff < 3600 && $sessions[0]->country !== $sessions[1]->country) {
            return [
                'anomaly' => true,
                'reason' => "تغییر موقعیت از {$sessions[1]->country} به {$sessions[0]->country} در کمتر از 1 ساعت",
            ];
        }

        return ['anomaly' => false];
    }

    private function checkActivityTime(int $userId): array
    {
        $hour = (int) date('H');
        if ($hour < 2 || $hour > 6) {
            return ['anomaly' => false];
        }

        $sql = 'SELECT COUNT(*) as count FROM user_sessions WHERE user_id = ? AND HOUR(created_at) BETWEEN 2 AND 6 AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)';
        $result = $this->db->fetch($sql, [$userId]);
        $count = $result ? (int) $result->count : 0;

        if ($count > 5) {
            return ['anomaly' => true, 'reason' => 'فعالیت مکرر در ساعات غیرمعمول (2-6 صبح)'];
        }

        return ['anomaly' => false];
    }

    private function checkActionVelocity(int $userId): array
    {
        $sql = 'SELECT COUNT(*) as count FROM activity_logs WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)';
        $result = $this->db->fetch($sql, [$userId]);
        $count = $result ? (int) $result->count : 0;

        if ($count > 20) {
            return ['anomaly' => true, 'reason' => "{$count} اقدام در 1 دقیقه (سرعت غیرطبیعی)"];
        }

        return ['anomaly' => false];
    }

    public function logAnomaly(int $userId, string $sessionId, array $analysis): void
    {
        if (!$analysis['is_anomaly']) {
            return;
        }

        $sql = 'INSERT INTO fraud_logs (user_id, session_id, fraud_type, risk_score, details) VALUES (?, ?, ?, ?, ?)';
        $this->db->query($sql, [
            $userId,
            $sessionId,
            'session_anomaly',
            $analysis['score'],
            json_encode($analysis, JSON_UNESCAPED_UNICODE),
        ]);
    }
}