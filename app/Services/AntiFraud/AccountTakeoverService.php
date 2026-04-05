<?php

namespace App\Services\AntiFraud;

class AccountTakeoverService
{
    private \Core\Database $db;
    private SessionAnomalyService $sessionAnomaly;
    private IPQualityService $ipQuality;
    
    public function __construct(
        \Core\Database $db,
        SessionAnomalyService $sessionAnomaly,
        IPQualityService $ipQuality)
    {
        $this->sessionAnomaly = $sessionAnomaly;
        $this->ipQuality = $ipQuality;
        $this->db = $db;
    }
    
    /**
     * تشخیص سرقت اکانت
     */
    public function detect(int $userId, string $ip, string $userAgent): array
    {
        $riskScore = 0;
        $signals = [];
        
        // 1. بررسی تغییر رمز عبور اخیر
        $passwordCheck = $this->checkRecentPasswordChange($userId);
        if ($passwordCheck['suspicious']) {
            $riskScore += 40;
            $signals[] = $passwordCheck['signal'];
        }
        
        // 2. بررسی تغییر ایمیل اخیر
        $emailCheck = $this->checkRecentEmailChange($userId);
        if ($emailCheck['suspicious']) {
            $riskScore += 35;
            $signals[] = $emailCheck['signal'];
        }
        
        // 3. بررسی IP جدید
        $ipCheck = $this->checkNewIP($userId, $ip);
        if ($ipCheck['is_new']) {
            $riskScore += 20;
            $signals[] = 'ورود از IP جدید';
            
            // اگر IP مشکوک هم باشد
            $ipQuality = $this->ipQuality->check($ip);
            if ($ipQuality['is_suspicious']) {
                $riskScore += 30;
                $signals[] = 'IP مشکوک: ' . implode(', ', $ipQuality['reasons']);
            }
        }
        
        // 4. بررسی دستگاه جدید
        $deviceCheck = $this->checkNewDevice($userId, $userAgent);
        if ($deviceCheck['is_new']) {
            $riskScore += 15;
            $signals[] = 'ورود از دستگاه جدید';
        }
        
        // 5. بررسی زمان غیرمعمول
        $hour = (int) date('H');
        if ($hour >= 2 && $hour <= 6) {
            $riskScore += 10;
            $signals[] = 'ورود در ساعت غیرمعمول';
        }
        
        // 6. بررسی تلاش‌های ناموفق قبلی
        $failedAttempts = $this->checkFailedAttempts($userId);
        if ($failedAttempts > 3) {
            $riskScore += 25;
            $signals[] = "{$failedAttempts} تلاش ناموفق قبلی";
        }
        
        return [
            'is_takeover' => $riskScore >= 70,
            'risk_score' => $riskScore,
            'signals' => $signals,
            'action' => $this->determineAction($riskScore)
        ];
    }
    
    /**
     * بررسی تغییر رمز اخیر
     */
    private function checkRecentPasswordChange(int $userId): array
    {
        $sql = "SELECT created_at FROM activity_logs 
                WHERE user_id = ? 
                AND action = 'password_changed' 
                ORDER BY created_at DESC 
                LIMIT 1";
        
        $result = $this->db->fetch($sql, [$userId]);
        
        if ($result) {
            $timeDiff = time() - strtotime($result->created_at);
            
            // اگر کمتر از 1 ساعت پیش رمز تغییر کرده
            if ($timeDiff < 3600) {
                return [
                    'suspicious' => true,
                    'signal' => 'تغییر رمز عبور در 1 ساعت اخیر'
                ];
            }
        }
        
        return ['suspicious' => false];
    }
    
    /**
     * بررسی تغییر ایمیل اخیر
     */
    private function checkRecentEmailChange(int $userId): array
    {
        $sql = "SELECT created_at FROM activity_logs 
                WHERE user_id = ? 
                AND action = 'email_changed' 
                ORDER BY created_at DESC 
                LIMIT 1";
        
        $result = $this->db->fetch($sql, [$userId]);
        
        if ($result) {
            $timeDiff = time() - strtotime($result->created_at);
            
            if ($timeDiff < 3600) {
                return [
                    'suspicious' => true,
                    'signal' => 'تغییر ایمیل در 1 ساعت اخیر'
                ];
            }
        }
        
        return ['suspicious' => false];
    }
    
    /**
     * بررسی IP جدید
     */
    private function checkNewIP(int $userId, string $ip): array
    {
        $sql = "SELECT COUNT(*) as count 
                FROM user_sessions 
                WHERE user_id = ? 
                AND ip_address = ?";
        
        $result = $this->db->fetch($sql, [$userId, $ip]);
        
        return [
            'is_new' => $result && $result->count == 0
        ];
    }
    
    /**
     * بررسی دستگاه جدید
     */
    private function checkNewDevice(int $userId, string $userAgent): array
    {
        $sql = "SELECT COUNT(*) as count 
                FROM user_sessions 
                WHERE user_id = ? 
                AND user_agent = ?";
        
        $result = $this->db->fetch($sql, [$userId, $userAgent]);
        
        return [
            'is_new' => $result && $result->count == 0
        ];
    }
    
    /**
     * بررسی تلاش‌های ناموفق
     */
    private function checkFailedAttempts(int $userId): int
    {
        $sql = "SELECT COUNT(*) as count 
                FROM activity_logs 
                WHERE user_id = ? 
                AND action = 'login_failed' 
                AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        
        $result = $this->db->fetch($sql, [$userId]);
        
        return $result ? (int) $result->count : 0;
    }
    
    /**
     * تعیین اقدام بر اساس امتیاز ریسک
     */
    private function determineAction(int $riskScore): string
    {
        if ($riskScore >= 90) {
            return 'block'; // مسدود کردن
        } elseif ($riskScore >= 70) {
            return 'challenge'; // نیاز به تأیید دو مرحله‌ای
        } elseif ($riskScore >= 50) {
            return 'notify'; // اطلاع‌رسانی به کاربر
        }
        
        return 'allow'; // اجازه دسترسی
    }
    
    /**
     * لاگ کردن تشخیص Account Takeover
     */
    public function logDetection(int $userId, string $ip, string $userAgent, array $detection): void
    {
        if ($detection['is_takeover']) {
            $sql = "INSERT INTO fraud_logs (user_id, fraud_type, risk_score, details, action_taken, ip_address, user_agent) 
                    VALUES (?, 'account_takeover', ?, ?, ?, ?, ?)";
            
            $this->db->query($sql, [
                $userId,
                $detection['risk_score'],
                json_encode($detection),
                $detection['action'],
                $ip,
                $userAgent
            ]);
        }
    }
}