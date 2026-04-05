<?php

namespace App\Services\AntiFraud;

use App\Services\RiskPolicyService;

class IPQualityService
{
    private \Core\Database $db;
    private RiskPolicyService $policy;

    public function __construct(\Core\Database $db, RiskPolicyService $policy)
    {
        $this->db = $db;
        $this->policy = $policy;
    }

    public function check(string $ip): array
    {
        $score = 0;
        $reasons = [];
        $details = [];

        if ($this->isPrivateIP($ip)) {
            $score += $this->policy->getInt('fraud', 'ip.private_range_points', 50);
            $reasons[] = 'استفاده از IP خصوصی';
            $details['is_private'] = true;
        }

        if ($this->isSuspiciousRange($ip)) {
            $score += $this->policy->getInt('fraud', 'ip.suspicious_range_points', 30);
            $reasons[] = 'محدوده IP مشکوک (Datacenter/VPN)';
            $details['suspicious_range'] = true;
        }

        if ($this->isTorNode($ip)) {
            $score += $this->policy->getInt('fraud', 'ip.tor_points', 80);
            $reasons[] = 'استفاده از شبکه Tor';
            $details['is_tor'] = true;
        }

        $userCount = $this->getUserCountByIP($ip);
        $sharedThreshold = $this->policy->getInt('fraud', 'ip.shared_ip_user_threshold', 5);
        if ($userCount > $sharedThreshold) {
            $score += $this->policy->getInt('fraud', 'ip.shared_ip_points', 40);
            $reasons[] = "استفاده مشترک توسط {$userCount} کاربر";
            $details['user_count'] = $userCount;
        }

        $velocityCheck = $this->checkIPVelocity($ip);
        if ($velocityCheck['suspicious']) {
            $score += 25;
            $reasons[] = $velocityCheck['reason'];
            $details['velocity'] = $velocityCheck;
        }

        $score = min($score, 100);

        return [
            'score' => $score,
            'is_suspicious' => $score >= 60,
            'reasons' => $reasons,
            'details' => $details,
        ];
    }

    private function isPrivateIP(string $ip): bool
    {
        $privateRanges = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8'];
        foreach ($privateRanges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function isSuspiciousRange(string $ip): bool
    {
        $ranges = $this->db->fetchAll('SELECT ip_range FROM vpn_ranges');
        foreach ($ranges as $range) {
            if ($this->ipInRange($ip, (string) $range->ip_range)) {
                return true;
            }
        }

        return false;
    }

    private function isTorNode(string $ip): bool
    {
        $result = $this->db->fetch('SELECT COUNT(*) as count FROM tor_exit_nodes WHERE ip_address = ?', [$ip]);
        return $result && (int) $result->count > 0;
    }

    private function getUserCountByIP(string $ip): int
    {
        $sql = 'SELECT COUNT(DISTINCT user_id) as count FROM user_sessions WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)';
        $result = $this->db->fetch($sql, [$ip]);
        return $result ? (int) $result->count : 0;
    }

    private function checkIPVelocity(string $ip): array
    {
        $sql = 'SELECT us.user_id, COUNT(DISTINCT us.ip_address) as ip_count\n'
             . 'FROM user_sessions us\n'
             . 'WHERE us.ip_address = ? AND us.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)\n'
             . 'GROUP BY us.user_id HAVING ip_count > 5 LIMIT 1';

        $result = $this->db->fetch($sql, [$ip]);
        if ($result) {
            return [
                'suspicious' => true,
                'reason' => 'الگوی سرعت تغییر IP مشکوک است',
            ];
        }

        return ['suspicious' => false];
    }

    private function ipInRange(string $ip, string $range): bool
    {
        [$subnet, $mask] = explode('/', $range);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - (int) $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    public function getGeolocation(string $ip): ?array
    {
        if ($this->isPrivateIP($ip)) {
            return null;
        }

        return [
            'country' => 'IR',
            'city' => 'Tehran',
            'latitude' => 35.6892,
            'longitude' => 51.3890,
        ];
    }

    public function isIPBlacklisted(string $ip): bool
    {
        $sql = 'SELECT COUNT(*) as count FROM ip_blacklist WHERE ip_address = ? AND (expires_at IS NULL OR expires_at > NOW())';
        $result = $this->db->fetch($sql, [$ip]);
        return $result && (int) $result->count > 0;
    }

    public function blacklistIP(string $ip, string $reason, ?int $duration = null): void
    {
        $expiresAt = $duration ? date('Y-m-d H:i:s', time() + $duration) : null;
        $sql = 'INSERT INTO ip_blacklist (ip_address, reason, auto_blocked, expires_at) VALUES (?, ?, TRUE, ?)\n'
             . 'ON DUPLICATE KEY UPDATE reason = VALUES(reason), expires_at = VALUES(expires_at)';

        $this->db->query($sql, [$ip, $reason, $expiresAt]);
    }

    public function logIPCheck(int $userId, string $ip, array $checkResult): void
    {
        if (!$checkResult['is_suspicious']) {
            return;
        }

        $sql = 'INSERT INTO fraud_logs (user_id, fraud_type, risk_score, details, ip_address) VALUES (?, ?, ?, ?, ?)';
        $this->db->query($sql, [
            $userId,
            'ip_suspicious',
            $checkResult['score'],
            json_encode($checkResult, JSON_UNESCAPED_UNICODE),
            $ip,
        ]);
    }
}