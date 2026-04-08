<?php

namespace App\Services\Sentry\Alerting;

use Core\Database;
use Core\Logger;

/**
 * 🚨 AlertDispatcher - سیستم ارسال هوشمند Alert
 * 
 * قابلیت‌ها:
 * - Multi-channel support (Telegram, Email, Slack, Webhook)
 * - Alert Throttling (جلوگیری از spam)
 * - Alert Grouping (گروه‌بندی alertهای مشابه)
 * - Escalation Rules (افزایش اولویت)
 * - Smart Routing (ارسال به کانال مناسب)
 */
class AlertDispatcher
{
    private Database $db;
    private Logger $logger;
    
    // تنظیمات throttling
    private array $throttleConfig = [
        'critical' => 60,   // حداکثر 1 alert در دقیقه
        'high' => 300,      // حداکثر 1 alert در 5 دقیقه
        'medium' => 900,    // حداکثر 1 alert در 15 دقیقه
        'low' => 3600,      // حداکثر 1 alert در ساعت
    ];

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->logger = Logger::getInstance()->withChannel('alert');
    }

    /**
     * 📤 Dispatch Alert - ارسال alert
     */
    public function dispatch(array $alert): bool
    {
        try {
            // Normalize alert
            $alert = $this->normalizeAlert($alert);

            // بررسی throttling
            if ($this->isThrottled($alert)) {
                $this->logger->info('Alert throttled', ['alert' => $alert['title']]);
                return false;
            }

            // ذخیره در database
            $alertId = $this->storeAlert($alert);

            // دریافت کانال‌های فعال
            $channels = $this->getActiveChannels($alert['severity']);

            // ارسال به هر کانال
            $sentCount = 0;
            foreach ($channels as $channel) {
                if ($this->sendToChannel($channel, $alert)) {
                    $sentCount++;
                    $this->recordNotification($channel->id, $alertId, 'sent');
                } else {
                    $this->recordNotification($channel->id, $alertId, 'failed');
                }
            }

            // آپدیت وضعیت alert
            if ($sentCount > 0) {
                $this->markAlertAsSent($alertId);
            }

            return $sentCount > 0;

        } catch (\Throwable $e) {
            $this->logger->error('Alert dispatch failed', [
                'error' => $e->getMessage(),
                'alert' => $alert['title'] ?? 'unknown'
            ]);
            return false;
        }
    }

    /**
     * 🔄 Normalize Alert
     */
    private function normalizeAlert(array $alert): array
    {
        return array_merge([
            'type' => 'custom',
            'severity' => 'medium',
            'title' => 'Alert',
            'message' => '',
            'metadata' => [],
            'event_id' => null,
            'environment' => 'production',
        ], $alert);
    }

    /**
     * 🚦 Is Throttled - بررسی throttling
     */
    private function isThrottled(array $alert): bool
    {
        $severity = $alert['severity'];
        $throttleSeconds = $this->throttleConfig[$severity] ?? 600;

        // ایجاد fingerprint برای گروه‌بندی
        $fingerprint = $this->createAlertFingerprint($alert);

        // بررسی آخرین alert مشابه
        $lastAlert = $this->db->query(
            "SELECT created_at 
             FROM system_alerts 
             WHERE fingerprint = ? 
             AND severity = ?
             ORDER BY created_at DESC 
             LIMIT 1",
            [$fingerprint, $severity]
        )->fetch(\PDO::FETCH_OBJ);

        if (!$lastAlert) {
            return false; // اولین باره
        }

        $lastTime = strtotime($lastAlert->created_at);
        $elapsed = time() - $lastTime;

        return $elapsed < $throttleSeconds;
    }

    /**
     * 🔑 Create Alert Fingerprint
     */
    private function createAlertFingerprint(array $alert): string
    {
        $components = [
            $alert['type'],
            $alert['title'],
            $alert['environment'] ?? 'production',
        ];

        return hash('sha256', implode('|', $components));
    }

    /**
     * 💾 Store Alert
     */
    private function storeAlert(array $alert): int
    {
        $fingerprint = $this->createAlertFingerprint($alert);

        $this->db->query(
            "INSERT INTO system_alerts (
                alert_type, severity, title, message, metadata,
                fingerprint, event_id, environment, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)",
            [
                $alert['type'],
                $alert['severity'],
                $alert['title'],
                $alert['message'],
                json_encode($alert['metadata'], JSON_UNESCAPED_UNICODE),
                $fingerprint,
                $alert['event_id'],
                $alert['environment'],
            ]
        );

        return (int)$this->db->getConnection()->lastInsertId();
    }

    /**
     * 📡 Get Active Channels
     */
    private function getActiveChannels(string $severity): array
    {
        return $this->db->query(
            "SELECT * FROM notification_channels
             WHERE is_active = 1
             AND (
                 alert_levels IS NULL 
                 OR JSON_CONTAINS(alert_levels, ?)
             )",
            [json_encode($severity)]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * 📨 Send to Channel
     */
    private function sendToChannel(object $channel, array $alert): bool
    {
        $config = json_decode($channel->config, true);

        return match($channel->channel_type) {
            'telegram' => $this->sendTelegram($config, $alert),
            'email' => $this->sendEmail($config, $alert),
            'slack' => $this->sendSlack($config, $alert),
            'webhook' => $this->sendWebhook($config, $alert),
            default => false,
        };
    }

    /**
     * 📱 Send Telegram
     */
    private function sendTelegram(array $config, array $alert): bool
    {
        if (!isset($config['bot_token']) || !isset($config['chat_id'])) {
            return false;
        }

        $text = $this->formatTelegramMessage($alert);
        
        $url = "https://api.telegram.org/bot{$config['bot_token']}/sendMessage";
        
        $data = [
            'chat_id' => $config['chat_id'],
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;

        } catch (\Throwable $e) {
            $this->logger->error('Telegram send failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 📧 Send Email
     */
    private function sendEmail(array $config, array $alert): bool
    {
        if (!isset($config['email'])) {
            return false;
        }

        $subject = "[{$alert['severity']}] {$alert['title']}";
        $body = $this->formatEmailMessage($alert);

        try {
            return mail(
                $config['email'],
                $subject,
                $body,
                "From: noreply@chortke.com\r\nContent-Type: text/html; charset=UTF-8"
            );
        } catch (\Throwable $e) {
            $this->logger->error('Email send failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 💬 Send Slack
     */
    private function sendSlack(array $config, array $alert): bool
    {
        if (!isset($config['webhook_url'])) {
            return false;
        }

        $payload = [
            'text' => $alert['title'],
            'attachments' => [
                [
                    'color' => $this->getSeverityColor($alert['severity']),
                    'text' => $alert['message'],
                    'fields' => [
                        [
                            'title' => 'Severity',
                            'value' => strtoupper($alert['severity']),
                            'short' => true,
                        ],
                        [
                            'title' => 'Environment',
                            'value' => $alert['environment'],
                            'short' => true,
                        ],
                    ],
                    'footer' => 'Chortke Sentry',
                    'ts' => time(),
                ],
            ],
        ];

        try {
            $ch = curl_init($config['webhook_url']);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;

        } catch (\Throwable $e) {
            $this->logger->error('Slack send failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 🔗 Send Webhook
     */
    private function sendWebhook(array $config, array $alert): bool
    {
        if (!isset($config['url'])) {
            return false;
        }

        try {
            $ch = curl_init($config['url']);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($alert));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode >= 200 && $httpCode < 300;

        } catch (\Throwable $e) {
            $this->logger->error('Webhook send failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 📝 Format Telegram Message
     */
    private function formatTelegramMessage(array $alert): string
    {
        $emoji = match($alert['severity']) {
            'critical' => '🔴',
            'high' => '🟠',
            'medium' => '🟡',
            'low' => '🟢',
            default => '⚪',
        };

        $text = "{$emoji} <b>{$alert['title']}</b>\n\n";
        $text .= "{$alert['message']}\n\n";
        $text .= "📊 Severity: <code>{$alert['severity']}</code>\n";
        $text .= "🌍 Environment: <code>{$alert['environment']}</code>\n";
        
        if ($alert['event_id']) {
            $text .= "🔗 Event ID: <code>{$alert['event_id']}</code>\n";
        }

        return $text;
    }

    /**
     * 📧 Format Email Message
     */
    private function formatEmailMessage(array $alert): string
    {
        return <<<HTML
        <html>
        <body style="font-family: Arial, sans-serif;">
            <h2 style="color: {$this->getSeverityColor($alert['severity'])};">
                {$alert['title']}
            </h2>
            <p>{$alert['message']}</p>
            <table>
                <tr><td><strong>Severity:</strong></td><td>{$alert['severity']}</td></tr>
                <tr><td><strong>Environment:</strong></td><td>{$alert['environment']}</td></tr>
                <tr><td><strong>Time:</strong></td><td>{date('Y-m-d H:i:s')}</td></tr>
            </table>
        </body>
        </html>
        HTML;
    }

    /**
     * 🎨 Get Severity Color
     */
    private function getSeverityColor(string $severity): string
    {
        return match($severity) {
            'critical' => '#dc3545',
            'high' => '#fd7e14',
            'medium' => '#ffc107',
            'low' => '#28a745',
            default => '#6c757d',
        };
    }

    /**
     * 📝 Record Notification
     */
    private function recordNotification(int $channelId, int $alertId, string $status): void
    {
        try {
            $this->db->query(
                "INSERT INTO notification_history (
                    channel_id, alert_id, status, sent_at
                ) VALUES (?, ?, ?, NOW())",
                [$channelId, $alertId, $status]
            );
        } catch (\Throwable $e) {
            // Silent fail
        }
    }

    /**
     * ✅ Mark Alert as Sent
     */
    private function markAlertAsSent(int $alertId): void
    {
        $this->db->query(
            "UPDATE system_alerts SET is_sent = 1, sent_at = NOW() WHERE id = ?",
            [$alertId]
        );
    }
}
