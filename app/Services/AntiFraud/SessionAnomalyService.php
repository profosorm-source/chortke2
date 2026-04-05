<?php

namespace App\Services\AntiFraud;

use Core\Database;

class SessionAnomalyService
{
    private Database $db;
    
    public function __construct(Database $db){
        $this->db = $db;}
    
    /**
     * تحلیل ناهنجاری در Session
     */
    public function analyze(int $userId, string $sessionId): array
    {
        $anomalies = [];
        $score = 0;
        
        // 1. بررسی تعداد Session های همزمان
        $concurrentCheck = $this->checkConcurrentSessions($userId);
        if ($concurrentCheck['anomaly']) {
            $score += 30;
            $anomalies[] = $concurrentCheck['reason'];
        }
        
        // 2. بررسی تغییر ناگهانی User-Agent
        $uaCheck = $this->checkUserAgentChange($userId);
        if ($uaCheck['anomaly']) {
            $score += 40;
            $anomalies[] = $uaCheck['reason'];
        }
        
        // 3. بررسی تغییر موقعیت جغرافیایی
        $geoCheck = $this->checkGeolocationChange($userId);
        if ($geoCheck['anomaly']) {
            $score += 35;
            $anomalies[] = $geoCheck['reason'];
        }
        
        // 4. بررسی زمان فعالیت غیرمعمول
        $timeCheck = $this->checkActivityTime($userId);
        if ($timeCheck['anomaly']) {
            $score += 15;
            $anomalies[] = $timeCheck['reason'];
        }
        
        // 5. بررسی سرعت اقدامات (Velocity)
        $velocityCheck = $this->checkActionVelocity($userId);
        if ($velocityCheck['anomaly']) {
            $score += 25;
            $anomalies[] = $velocityCheck['reason'];
        }
        
        return [
            'is_anomaly' => $score >= 50,
            'score' => $score,
            'anomalies' => $anomalies
        ];
    }
    
    /**
     * بررسی Session های همزمان
     */
    private function checkConcurrentSessions(int $userId): array
    {
        $sql = "SELECT COUNT(*) as count 
                FROM user_sessions 
                WHERE user_id = ? 
                AND is_active = TRUE 
                AND last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)";
        
        $result = $this->db->fetch($sql, [$userId]);
        $count = $result ? (int) $result->count : 0;
        
        if ($count > 3) {
            return [
                'anomaly' => true,
                'reason' => "{$count} Session همزمان فعال"
            ];
        }
        
        return ['anomaly' => false];
    }
    
    /**
     * بررسی تغییر User-Agent
     */
    private function checkUserAgentChange(int $userId): array
    {
        $sql = "SELECT user_agent, created_at 
                FROM user_sessions 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 2";
        
        $sessions = $this->db->fetchAll($sql, [$userId]);
        
        if (count($sessions) < 2) {
            return ['anomaly' => false];
        }
        
        $timeDiff = strtotime($sessions[0]->created_at) - strtotime($sessions[1]->created_at);
        
        // اگر در کمتر از 5 دقیقه User-Agent تغییر کرده
        if ($timeDiff < 300 && $sessions[0]->user_agent !== $sessions[1]->user_agent) {
            return [
                'anomaly' => true,
                'reason' => 'تغییر ناگهانی User-Agent در کمتر از 5 دقیقه'
            ];
        }
        
        return ['anomaly' => false];
    }
    
    /**
     * بررسی تغییر موقعیت جغرافیایی
     */
    private function checkGeolocationChange(int $userId): array
    {
        $sql = "SELECT country, city, created_at 
                FROM user_sessions 
                WHERE user_id = ? 
                AND country IS NOT NULL 
                ORDER BY created_at DESC 
                LIMIT 2";
        
        $sessions = $this->db->fetchAll($sql, [$userId]);
        
        if (count($sessions) < 2) {
            return ['anomaly' => false];
        }
        
        $timeDiff = strtotime($sessions[0]->created_at) - strtotime($sessions[1]->created_at);
        
        // اگر در کمتر از 1 ساعت کشور تغییر کرده
        if ($timeDiff < 3600 && $sessions[0]->country !== $sessions[1]->country) {
            return [
                'anomaly' => true,
                'reason' => "تغییر موقعیت از {$sessions[1]->country} به {$sessions[0]->country} در کمتر از 1 ساعت"
            ];
        }
        
        return ['anomaly' => false];
    }
    
    /**
     * بررسی زمان فعالیت
     */
    private function checkActivityTime(int $userId): array
    {
        $hour = (int) date('H');
        
        // فعالیت بین 2 تا 6 صبح مشکوک است
        if ($hour >= 2 && $hour <= 6) {
            $sql = "SELECT COUNT(*) as count 
                    FROM user_sessions 
                    WHERE user_id = ? 
                    AND HOUR(created_at) BETWEEN 2 AND 6 
                    AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)";
            
            $result = $this->db->fetch($sql, [$userId]);
            $count = $result ? (int) $result->count : 0;
            
            if ($count > 5) {
                return [
                    'anomaly' => true,
                    'reason' => 'فعالیت مکرر در ساعات غیرمعمول (2-6 صبح)'
                ];
            }
        }
        
        return ['anomaly' => false];
    }
    
    /**
     * بررسی سرعت اقدامات
     */
    private function checkActionVelocity(int $userId): array
    {
        // بررسی تعداد اقدامات در 1 دقیقه اخیر
        $sql = "SELECT COUNT(*) as count 
                FROM activity_logs 
                WHERE user_id = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)";
        
        $result = $this->db->fetch($sql, [$userId]);
        $count = $result ? (int) $result->count : 0;
        
        if ($count > 20) {
            return [
                'anomaly' => true,
                'reason' => "{$count} اقدام در 1 دقیقه (سرعت غیرطبیعی)"
            ];
        }
        
        return ['anomaly' => false];
    }
    
    /**
     * لاگ کردن ناهنجاری Session
     */
    public function logAnomaly(int $userId, string $sessionId, array $analysis): void
    {
        if ($analysis['is_anomaly']) {
            $sql = "INSERT INTO fraud_logs (user_id, session_id, fraud_type, risk_score, details) 
                    VALUES (?, ?, 'session_anomaly', ?, ?)";
            
            $this->db->query($sql, [
                $userId,
                $sessionId,
                $analysis['score'],
                json_encode($analysis)
            ]);
        }
    }
}