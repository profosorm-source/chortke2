<?php

namespace App\Services\AntiFraud;

use App\Services\RiskPolicyService;

class AccountTakeoverService
{
    private \Core\Database $db;
    private SessionAnomalyService $sessionAnomaly;
    private IPQualityService $ipQuality;
    private RiskPolicyService $policy;

    public function __construct(
        \Core\Database $db,
        SessionAnomalyService $sessionAnomaly,
        IPQualityService $ipQuality,
        RiskPolicyService $policy
    ) {
        $this->db = $db;
        $this->sessionAnomaly = $sessionAnomaly;
        $this->ipQuality = $ipQuality;
        $this->policy = $policy;
    }

    public function detect(int $userId, string $ip, string $userAgent): array
    {
        $riskScore = 0;
        $signals = [];

        $passwordCheck = $this->checkRecentPasswordChange($userId);
        if ($passwordCheck['suspicious']) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.password_change_points', 40);
            $signals[] = $passwordCheck['signal'];
        }

        $emailCheck = $this->checkRecentEmailChange($userId);
        if ($emailCheck['suspicious']) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.email_change_points', 35);
            $signals[] = $emailCheck['signal'];
        }

        $ipCheck = $this->checkNewIP($userId, $ip);
        if ($ipCheck['is_new']) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.new_ip_points', 20);
            $signals[] = 'ورود از IP جدید';

            $ipQuality = $this->ipQuality->check($ip);
            if ($ipQuality['is_suspicious']) {
                $riskScore += $this->policy->getInt('fraud', 'takeover.suspicious_ip_bonus_points', 30);
                $signals[] = 'IP مشکوک: ' . implode(', ', $ipQuality['reasons']);
            }
        }

        $deviceCheck = $this->checkNewDevice($userId, $userAgent);
        if ($deviceCheck['is_new']) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.new_device_points', 15);
            $signals[] = 'ورود از دستگاه جدید';
        }

        $hour = (int) date('H');
        if ($hour >= 2 && $hour <= 6) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.odd_hour_points', 10);
            $signals[] = 'ورود در ساعت غیرمعمول';
        }

        $failedAttempts = $this->checkFailedAttempts($userId);
        $failedThreshold = $this->policy->getInt('fraud', 'takeover.failed_attempts_threshold', 3);
        if ($failedAttempts > $failedThreshold) {
            $riskScore += $this->policy->getInt('fraud', 'takeover.failed_attempts_points', 25);
            $signals[] = "{$failedAttempts} تلاش ناموفق قبلی";
        }

        $riskScore = min($riskScore, 100);

        return [
            'is_takeover' => $riskScore >= 70,
            'risk_score' => $riskScore,
            'signals' => $signals,
            'action' => $this->determineAction($riskScore),
        ];
    }

    private function checkRecentPasswordChange(int $userId): array
    {
        $sql = "SELECT created_at FROM activity_logs WHERE user_id = ? AND action = 'password_changed' ORDER BY created_at DESC LIMIT 1";
        $result = $this->db->fetch($sql, [$userId]);
        if ($result && (time() - strtotime($result->created_at)) < 3600) {
            return ['suspicious' => true, 'signal' => 'تغییر رمز عبور در 1 ساعت اخیر'];
        }

        return ['suspicious' => false];
    }

    private function checkRecentEmailChange(int $userId): array
    {
        $sql = "SELECT created_at FROM activity_logs WHERE user_id = ? AND action = 'email_changed' ORDER BY created_at DESC LIMIT 1";
        $result = $this->db->fetch($sql, [$userId]);
        if ($result && (time() - strtotime($result->created_at)) < 3600) {
            return ['suspicious' => true, 'signal' => 'تغییر ایمیل در 1 ساعت اخیر'];
        }

        return ['suspicious' => false];
    }

    private function checkNewIP(int $userId, string $ip): array
    {
        $sql = 'SELECT COUNT(*) as count FROM user_sessions WHERE user_id = ? AND ip_address = ?';
        $result = $this->db->fetch($sql, [$userId, $ip]);
        return ['is_new' => $result && (int) $result->count === 0];
    }

    private function checkNewDevice(int $userId, string $userAgent): array
    {
        $sql = 'SELECT COUNT(*) as count FROM user_sessions WHERE user_id = ? AND user_agent = ?';
        $result = $this->db->fetch($sql, [$userId, $userAgent]);
        return ['is_new' => $result && (int) $result->count === 0];
    }

    private function checkFailedAttempts(int $userId): int
    {
        $sql = "SELECT COUNT(*) as count FROM activity_logs WHERE user_id = ? AND action = 'login_failed' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        $result = $this->db->fetch($sql, [$userId]);
        return $result ? (int) $result->count : 0;
    }

    private function determineAction(int $riskScore): string
    {
        if ($riskScore >= 90) {
            return 'block';
        }
        if ($riskScore >= 70) {
            return 'challenge';
        }
        if ($riskScore >= 50) {
            return 'notify';
        }
        return 'allow';
    }

    public function logDetection(int $userId, string $ip, string $userAgent, array $detection): void
    {
        if (!$detection['is_takeover']) {
            return;
        }

        $sql = "INSERT INTO fraud_logs (user_id, fraud_type, risk_score, details, action_taken, ip_address, user_agent) VALUES (?, 'account_takeover', ?, ?, ?, ?, ?)";
        $this->db->query($sql, [
            $userId,
            $detection['risk_score'],
            json_encode($detection, JSON_UNESCAPED_UNICODE),
            $detection['action'],
            $ip,
            $userAgent,
        ]);
    }
}