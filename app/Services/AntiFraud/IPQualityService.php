<?php

namespace App\Services\AntiFraud;

class IPQualityService
{
    private \Core\Database $db;

    public function __construct(\Core\Database $db)
    {
        $this->db = $db;
    }

    /**
     * بررسی کیفیت IP
     */
    public function check(string $ip): array
    {
        $score = 0;
        $reasons = [];
        $details = [];
        
        // 1. بررسی Private IP
        if ($this->isPrivateIP($ip)) {
            $score += 50;
            $reasons[] = 'استفاده از IP خصوصی';
            $details['is_private'] = true;
        }
        
        // 2. بررسی محدوده‌های مشکوک
        if ($this->isSuspiciousRange($ip)) {
            $score += 30;
            $reasons[] = 'محدوده IP مشکوک (Datacenter/VPN)';
            $details['suspicious_range'] = true;
        }
        
        // 3. بررسی Tor Exit Node (لیست ساده)
        if ($this->isTorNode($ip)) {
            $score += 80;
            $reasons[] = 'استفاده از شبکه Tor';
            $details['is_tor'] = true;
        }
        
        // 4. بررسی تعداد کاربران با این IP
        $userCount = $this->getUserCountByIP($ip);
        if ($userCount > 5) {
            $score += 40;
            $reasons[] = "استفاده مشترک توسط {$userCount} کاربر";
            $details['user_count'] = $userCount;
        }
        
        // 5. بررسی سرعت تغییر IP (Velocity)
        $velocityCheck = $this->checkIPVelocity($ip);
        if ($velocityCheck['suspicious']) {
            $score += 25;
            $reasons[] = $velocityCheck['reason'];
            $details['velocity'] = $velocityCheck;
        }
        
        return [
            'score' => min($score, 100),
            'is_suspicious' => $score >= 60,
            'reasons' => $reasons,
            'details' => $details
        ];
    }
    
    /**
     * بررسی IP خصوصی
     */
    private function isPrivateIP(string $ip): bool
    {
        $privateRanges = [
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '127.0.0.0/8'
        ];
        
        foreach ($privateRanges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * بررسی محدوده‌های مشکوک (VPN/Datacenter) از دیتابیس
     */
    private function isSuspiciousRange(string $ip): bool
    {
        $sql = "SELECT ip_range, risk_level FROM vpn_ranges";
        $ranges = $this->db->fetchAll($sql);
        
        foreach ($ranges as $range) {
            if ($this->ipInRange($ip, $range->ip_range)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * بررسی Tor Node (از دیتابیس)
     */
    private function isTorNode(string $ip): bool
    {
        $sql = "SELECT COUNT(*) as count FROM tor_exit_nodes WHERE ip_address = ?";
        $result = $this->db->fetch($sql, [$ip]);
        
        return $result && $result->count > 0;
    }
    
    /**
     * شمارش کاربران با این IP
     */
    private function getUserCountByIP(string $ip): int
    {
        $sql = "SELECT COUNT(DISTINCT user_id) as count 
                FROM user_sessions 
                WHERE ip_address = ? 
                AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)";
        
        $result = $this->db->fetch($sql, [$ip]);
        return $result ? (int) $result->count : 0;
    }
    
    /**
     * بررسی سرعت تغییر IP (برای یک کاربر خاص)
     */
    private function checkIPVelocity(string $ip): array
    {
        // بررسی IP جدید برای کاربران موجود در session
        $sql = "SELECT us.user_id, COUNT(DISTINCT us.ip_address) as ip_count 
                FROM user_sessions us
                WHERE us.ip_address = ?
                AND us.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                GROUP BY us.user_id
                HAVING ip_count > 5
                LIMIT 1";
        
        $result = $this->db->fetch($sql, [$ip]);
        
        if ($result) {
            $ipCount = (int) $result->ip_count;
            return [
                'suspicious' => true,
                'reason' => "کاربر {$ipCount} IP مختلف در 1 ساعت استفاده کرده"
            ];
        }
        
        return ['suspicious' => false];
    }
    
    /**
     * بررسی IP در محدوده
     */
    private function ipInRange(string $ip, string $range): bool
    {
        list($subnet, $mask) = explode('/', $range);
        
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask_long = -1 << (32 - (int)$mask);
        
        return ($ip_long & $mask_long) === ($subnet_long & $mask_long);
    }
    
    /**
     * دریافت اطلاعات Geolocation (ساده)
     */
    public function getGeolocation(string $ip): ?array
    {
        // در پروداکشن باید از API استفاده شود (مثل MaxMind)
        // فعلاً mock
        
        if ($this->isPrivateIP($ip)) {
            return null;
        }
        
        return [
            'country' => 'IR',
            'city' => 'Tehran',
            'latitude' => 35.6892,
            'longitude' => 51.3890
        ];
    }
    
    /**
     * بررسی IP در لیست سیاه
     */
    public function isIPBlacklisted(string $ip): bool
    {
        $sql = "SELECT COUNT(*) as count FROM ip_blacklist 
                WHERE ip_address = ? 
                AND (expires_at IS NULL OR expires_at > NOW())";
        $result = $this->db->fetch($sql, [$ip]);
        
        return $result && $result->count > 0;
    }
    
    /**
     * اضافه کردن IP به لیست سیاه
     */
    public function blacklistIP(string $ip, string $reason, ?int $duration = null): void
    {
        $expiresAt = $duration ? date('Y-m-d H:i:s', time() + $duration) : null;
        
        $sql = "INSERT INTO ip_blacklist (ip_address, reason, auto_blocked, expires_at) 
                VALUES (?, ?, TRUE, ?)
                ON DUPLICATE KEY UPDATE 
                reason = VALUES(reason), 
                expires_at = VALUES(expires_at)";
        
        $this->db->query($sql, [$ip, $reason, $expiresAt]);
    }
    
    /**
     * لاگ کردن بررسی IP
     */
    public function logIPCheck(int $userId, string $ip, array $checkResult): void
    {
        if ($checkResult['is_suspicious']) {
            $sql = "INSERT INTO fraud_logs (user_id, fraud_type, risk_score, details, ip_address) 
                    VALUES (?, 'ip_suspicious', ?, ?, ?)";
            
            $this->db->query($sql, [
                $userId,
                $checkResult['score'],
                json_encode($checkResult),
                $ip
            ]);
        }
    }
}