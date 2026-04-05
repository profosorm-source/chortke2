<?php

namespace App\Services\AntiFraud;

use Core\Database;

class BrowserFingerprintService
{
    private Database $db;
    
    public function __construct(Database $db){
        $this->db = $db;}
    
    /**
     * ایجاد Fingerprint کامل
     */
    public function generate(array $data): string
    {
        $components = [
            'user_agent' => $data['user_agent'] ?? '',
            'language' => $data['language'] ?? '',
            'timezone' => $data['timezone'] ?? '',
            'screen' => $data['screen'] ?? '', // width x height x depth
            'canvas' => $data['canvas'] ?? '',
            'webgl' => $data['webgl'] ?? '',
            'audio' => $data['audio'] ?? '',
            'fonts' => $data['fonts'] ?? '',
            'plugins' => $data['plugins'] ?? '',
            'touch_support' => $data['touch_support'] ?? '',
            'hardware_concurrency' => $data['hardware_concurrency'] ?? '',
            'device_memory' => $data['device_memory'] ?? ''
        ];
        
        // ایجاد Hash
        $fingerprint = hash('sha256', json_encode($components));
        
        return $fingerprint;
    }
    
    /**
     * ذخیره Fingerprint
     */
    public function store(int $userId, string $fingerprint, array $metadata): void
    {
        $sql = "INSERT INTO user_fingerprints 
                (user_id, fingerprint, metadata, created_at) 
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                last_seen = NOW(), 
                seen_count = seen_count + 1";
        
        $this->db->query($sql, [
            $userId,
            $fingerprint,
            json_encode($metadata)
        ]);
    }
    
    /**
     * بررسی Fingerprint مشکوک
     */
    public function analyze(int $userId, string $fingerprint): array
    {
        $suspicionScore = 0;
        $reasons = [];
        
        // 1. بررسی تعداد کاربران با همین Fingerprint
        $userCountSql = "SELECT COUNT(DISTINCT user_id) as user_count 
                        FROM user_fingerprints 
                        WHERE fingerprint = ? 
                        AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)";
        
        $result = $this->db->fetch($userCountSql, [$fingerprint]);
        
        if ($result && $result->user_count > 3) {
            $suspicionScore += 40;
            $reasons[] = "Fingerprint مشترک با {$result->user_count} کاربر";
        }
        
        // 2. بررسی تغییر ناگهانی Fingerprint
        $changeCheckSql = "SELECT fingerprint, created_at 
                          FROM user_fingerprints 
                          WHERE user_id = ? 
                          ORDER BY created_at DESC 
                          LIMIT 2";
        
        $fingerprints = $this->db->fetchAll($changeCheckSql, [$userId]);
        
        if (count($fingerprints) > 1) {
            $timeDiff = strtotime($fingerprints[0]->created_at) - strtotime($fingerprints[1]->created_at);
            
            // اگر کمتر از 1 ساعت تغییر کرده
            if ($timeDiff < 3600 && $fingerprints[0]->fingerprint !== $fingerprints[1]->fingerprint) {
                $suspicionScore += 25;
                $reasons[] = "تغییر ناگهانی Fingerprint در کمتر از 1 ساعت";
            }
        }
        
        return [
            'suspicious' => $suspicionScore >= 50,
            'score' => $suspicionScore,
            'reasons' => $reasons
        ];
    }
    
    /**
     * دریافت Fingerprint های کاربر
     */
    public function getUserFingerprints(int $userId, int $limit = 10): array
    {
        $sql = "SELECT * FROM user_fingerprints 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?";
        
        return $this->db->fetchAll($sql, [$userId, $limit]);
    }
    
    /**
     * بررسی Fingerprint در لیست سیاه
     */
    public function isFingerprintBlacklisted(string $fingerprint): bool
    {
        $sql = "SELECT COUNT(*) as count FROM device_blacklist 
                WHERE fingerprint = ? 
                AND (expires_at IS NULL OR expires_at > NOW())";
        $result = $this->db->fetch($sql, [$fingerprint]);
        
        return $result && $result->count > 0;
    }
    
    /**
     * اضافه کردن Fingerprint به لیست سیاه
     */
    public function blacklistFingerprint(string $fingerprint, string $reason, ?int $duration = null): void
    {
        $expiresAt = $duration ? date('Y-m-d H:i:s', time() + $duration) : null;
        
        $sql = "INSERT INTO device_blacklist (fingerprint, reason, auto_blocked, expires_at) 
                VALUES (?, ?, TRUE, ?)
                ON DUPLICATE KEY UPDATE 
                reason = VALUES(reason), 
                expires_at = VALUES(expires_at)";
        
        $this->db->query($sql, [$fingerprint, $reason, $expiresAt]);
    }
    
    /**
     * لاگ کردن تحلیل Fingerprint
     */
    public function logAnalysis(int $userId, string $fingerprint, array $analysis): void
    {
        if ($analysis['suspicious']) {
            $sql = "INSERT INTO fraud_logs (user_id, fraud_type, risk_score, details) 
                    VALUES (?, 'fingerprint_suspicious', ?, ?)";
            
            $this->db->query($sql, [
                $userId,
                $analysis['score'],
                json_encode($analysis)
            ]);
        }
    }
}