<?php

namespace App\Services;

use Core\Database;

/**
 * سرویس ارسال نوتیفیکیشن‌ها
 */
class LogNotificationService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * ارسال هشدار به تمام کانال‌های فعال
     */
    public function sendAlert(string $title, string $message, string $severity = 'medium'): void
    {
        // دریافت کانال‌های فعال که این سطح خطا رو دریافت می‌کنن
        $channels = $this->db->query(
            "SELECT * FROM notification_channels 
             WHERE is_active = 1 
             AND JSON_CONTAINS(alert_levels, ?)",
            [json_encode($severity)]
        )->fetchAll(\PDO::FETCH_OBJ);

        foreach ($channels as $channel) {
            try {
                $config = json_decode($channel->config, true);
                
                $sent = match($channel->channel_type) {
                    'telegram' => $this->sendTelegram($config, $title, $message, $severity),
                    'email' => $this->sendEmail($config, $title, $message),
                    'sms' => $this->sendSMS($config, $title, $message),
                    'webhook' => $this->sendWebhook($config, $title, $message, $severity),
                    default => false
                };

                // ثبت تاریخچه
                $this->logNotification(
                    $channel->id,
                    'alert',
                    $title,
                    $message,
                    $sent ? 'sent' : 'failed'
                );

            } catch (\Throwable $e) {
                // لاگ خطا
                error_log("Notification failed for channel {$channel->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * ارسال پیام تلگرام
     */
    private function sendTelegram(array $config, string $title, string $message, string $severity): bool
    {
        if (empty($config['bot_token']) || empty($config['chat_id'])) {
            return false;
        }

        $emoji = match($severity) {
            'low' => '🔵',
            'medium' => '🟡',
            'high' => '🟠',
            'critical' => '🔴',
            default => '⚪'
        };

        $text = "{$emoji} *{$title}*\n\n{$message}\n\n⏰ " . date('Y-m-d H:i:s');

        $url = "https://api.telegram.org/bot{$config['bot_token']}/sendMessage";
        
        $data = [
            'chat_id' => $config['chat_id'],
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;

        } catch (\Throwable $e) {
            error_log("Telegram send failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ارسال ایمیل
     */
    private function sendEmail(array $config, string $title, string $message): bool
    {
        if (empty($config['email'])) {
            return false;
        }

        $subject = "🔔 {$title}";
        $body = "
        <html>
        <body style='font-family: Tahoma, Arial; direction: rtl;'>
            <h2 style='color: #d32f2f;'>{$title}</h2>
            <p>{$message}</p>
            <hr>
            <small>زمان: " . date('Y-m-d H:i:s') . "</small>
        </body>
        </html>
        ";

        $headers = [
            'From: System Alert <noreply@chortke.com>',
            'Content-Type: text/html; charset=UTF-8',
            'MIME-Version: 1.0'
        ];

        return mail($config['email'], $subject, $body, implode("\r\n", $headers));
    }

    /**
     * ارسال SMS
     */
    private function sendSMS(array $config, string $title, string $message): bool
    {
        return false; // فعلا غیرفعال
    }

    /**
     * ارسال به Webhook
     */
    private function sendWebhook(array $config, string $title, string $message, string $severity): bool
    {
        if (empty($config['url'])) {
            return false;
        }

        $payload = json_encode([
            'title' => $title,
            'message' => $message,
            'severity' => $severity,
            'timestamp' => time()
        ]);

        try {
            $ch = curl_init($config['url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode >= 200 && $httpCode < 300;

        } catch (\Throwable $e) {
            error_log("Webhook send failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ثبت تاریخچه نوتیفیکیشن
     */
    private function logNotification(
        int $channelId,
        string $type,
        string $title,
        string $message,
        string $status
    ): void {
        try {
            $this->db->query(
                "INSERT INTO notification_history 
                (channel_id, notification_type, title, message, status, sent_at)
                VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $channelId,
                    $type,
                    $title,
                    $message,
                    $status,
                    $status === 'sent' ? date('Y-m-d H:i:s') : null
                ]
            );
        } catch (\Throwable $e) {
            // Silent fail
        }
    }

    /**
     * تست کانال نوتیفیکیشن
     */
    public function testChannel(int $channelId): array
    {
        $channel = $this->db->query(
            "SELECT * FROM notification_channels WHERE id = ?",
            [$channelId]
        )->fetch(\PDO::FETCH_OBJ);

        if (!$channel) {
            return ['success' => false, 'message' => 'کانال یافت نشد'];
        }

        $config = json_decode($channel->config, true);
        
        $success = match($channel->channel_type) {
            'telegram' => $this->sendTelegram(
                $config, 
                'تست سیستم', 
                'این یک پیام تست است', 
                'low'
            ),
            'email' => $this->sendEmail($config, 'تست سیستم', 'این یک ایمیل تست است'),
            default => false
        };

        return [
            'success' => $success,
            'message' => $success ? 'پیام با موفقیت ارسال شد' : 'ارسال پیام ناموفق بود'
        ];
    }

    /**
     * بررسی و اجرای قوانین هشدار
     */
    public function checkAlertRules(): void
    {
        $rules = $this->db->query(
            "SELECT * FROM alert_rules WHERE is_active = 1"
        )->fetchAll(\PDO::FETCH_OBJ);

        foreach ($rules as $rule) {
            try {
                $condition = json_decode($rule->condition, true);
                $triggered = $this->evaluateRule($rule, $condition);

                if ($triggered) {
                    // جلوگیری از spam
                    $lastTrigger = $rule->last_triggered_at ? 
                        strtotime($rule->last_triggered_at) : 0;
                    
                    if (time() - $lastTrigger < 3600) {
                        continue;
                    }

                    $this->sendAlert(
                        $rule->rule_name,
                        "قانون '{$rule->rule_name}' فعال شد",
                        $rule->severity
                    );

                    $this->db->query(
                        "UPDATE alert_rules SET last_triggered_at = NOW() WHERE id = ?",
                        [$rule->id]
                    );
                }
            } catch (\Throwable $e) {
                error_log("Alert rule {$rule->id} check failed: " . $e->getMessage());
            }
        }
    }

    /**
     * ارزیابی قانون هشدار
     */
    private function evaluateRule(object $rule, array $condition): bool
    {
        $metric = $condition['metric'] ?? '';
        $operator = $condition['operator'] ?? '>';
        
        $value = match($metric) {
            'error_count' => $this->getErrorCount($rule->time_window),
            'critical_errors' => $this->getCriticalErrorCount($rule->time_window),
            'slow_requests' => $this->getSlowRequestCount($rule->time_window),
            'failed_login' => $this->getFailedLoginCount($rule->time_window),
            default => 0
        };

        return match($operator) {
            '>' => $value > $rule->threshold,
            '>=' => $value >= $rule->threshold,
            '<' => $value < $rule->threshold,
            '<=' => $value <= $rule->threshold,
            '==' => $value == $rule->threshold,
            default => false
        };
    }

    private function getErrorCount(int $minutes): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM error_logs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        )->fetch(\PDO::FETCH_OBJ);

        return $result->count ?? 0;
    }

    private function getCriticalErrorCount(int $minutes): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM error_logs 
             WHERE level IN ('CRITICAL', 'FATAL')
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        )->fetch(\PDO::FETCH_OBJ);

        return $result->count ?? 0;
    }

    private function getSlowRequestCount(int $minutes): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM performance_logs 
             WHERE is_slow = 1
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        )->fetch(\PDO::FETCH_OBJ);

        return $result->count ?? 0;
    }

    private function getFailedLoginCount(int $minutes): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM security_logs 
             WHERE event_type = 'login_failed'
             AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$minutes]
        )->fetch(\PDO::FETCH_OBJ);

        return $result->count ?? 0;
    }
}
