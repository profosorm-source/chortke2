<?php

namespace Core;

class Logger
{
    private static ?Logger $instance = null;
    private string $logPath;
    
    private function __construct()
    {
        $this->logPath = dirname(__DIR__) . '/storage/logs/';
        
        if (!is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }
    }
    
    public static function getInstance(): Logger
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function log(string $level, string $message, array $context = []): void
    {
        $date = date('Y-m-d');
        $logFile = $this->logPath . $date . '.log';

        // پاک‌سازی اطلاعات حساس قبل از لاگ کردن
        $context = $this->maskSensitiveData($context);

        $time = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '';

        $logLine = "[{$time}] [{$level}] {$message}";
        if ($contextStr) {
            $logLine .= " | Context: {$contextStr}";
        }
        $logLine .= PHP_EOL;

        file_put_contents($logFile, $logLine, FILE_APPEND);
    }

    /**
     * پوشاندن اطلاعات حساس در context لاگ
     * از لو رفتن شماره کارت، رمز، توکن و سایر داده‌های حساس جلوگیری می‌کند
     */
    private function maskSensitiveData(array $data): array
    {
        // فیلدهایی که باید کاملاً پوشانده شوند
        $sensitiveFields = [
            'password', 'pass', 'secret', 'token', 'api_key', 'api_secret',
            'private_key', 'auth_token', 'access_token', 'refresh_token',
            'remember_token', 'csrf_token', 'two_factor_code', 'otp',
        ];

        // فیلدهایی که فقط آخر ۴ کاراکتر نمایش داده می‌شود
        $partialMaskFields = [
            'card_number', 'bank_card', 'account_number', 'sheba',
            'national_id', 'national_code', 'phone', 'mobile',
            'wallet_address', 'crypto_address',
        ];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string)$key);

            if (is_array($value)) {
                $data[$key] = $this->maskSensitiveData($value);
                continue;
            }

            // پوشاندن کامل فیلدهای حساس
            foreach ($sensitiveFields as $field) {
                if (str_contains($lowerKey, $field)) {
                    $data[$key] = '[REDACTED]';
                    continue 2;
                }
            }

            // پوشاندن جزئی فیلدهای نیمه‌حساس
            foreach ($partialMaskFields as $field) {
                if (str_contains($lowerKey, $field) && is_string($value) && strlen($value) > 4) {
                    $len = strlen($value);
                    $data[$key] = str_repeat('*', max(0, $len - 4)) . substr($value, -4);
                    continue 2;
                }
            }
        }

        return $data;
    }
    
    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }
    
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }
    
    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }
    
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }
    
    public function critical(string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }
}