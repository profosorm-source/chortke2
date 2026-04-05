<?php
/**
 * Rate Limiting Helper Functions
 * 
 * توابع کمکی برای استفاده آسان از Rate Limiting
 */

if (!function_exists('get_rate_limit_config')) {
    /**
     * دریافت تنظیمات rate limit برای یک endpoint
     * 
     * @param string $group گروه (auth, financial, upload, etc.)
     * @param string $endpoint نام endpoint (login, deposit, etc.)
     * @return array تنظیمات rate limit
     */
    function get_rate_limit_config(string $group, string $endpoint = 'general'): array
    {
        $config = require __DIR__ . '/../config/rate_limits.php';
        
        // اگر گروه وجود داشت
        if (isset($config[$group][$endpoint])) {
            return $config[$group][$endpoint];
        }
        
        // اگر فقط گروه وجود داشت
        if (isset($config[$group]) && is_array($config[$group])) {
            return $config[$group];
        }
        
        // fallback به default
        return $config['default'];
    }
}

if (!function_exists('check_rate_limit')) {
    /**
     * بررسی rate limit برای یک کاربر/IP
     * 
     * @param string $key کلید منحصر به فرد (مثلاً auth:login:127.0.0.1)
     * @param array $config تنظیمات rate limit
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => int]
     */
    function check_rate_limit(string $key, array $config): array
    {
        $cacheDir = __DIR__ . '/../storage/cache/rate_limit';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $cacheFile = $cacheDir . '/' . hash("sha256", $key) . '.json';
        $now = time();
        
        // بارگذاری داده‌های قبلی
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            
            // اگر window منقضی شده، reset کن
            if ($now > $data['reset_at']) {
                $data = [
                    'attempts' => 0,
                    'reset_at' => $now + ($config['decay_minutes'] * 60)
                ];
            }
        } else {
            $data = [
                'attempts' => 0,
                'reset_at' => $now + ($config['decay_minutes'] * 60)
            ];
        }
        
        // بررسی محدودیت
        $allowed = $data['attempts'] < $config['max_attempts'];
        
        if ($allowed) {
            // افزایش تعداد تلاش
            $data['attempts']++;
            file_put_contents($cacheFile, json_encode($data));
        }
        
        return [
            'allowed' => $allowed,
            'remaining' => max(0, $config['max_attempts'] - $data['attempts']),
            'reset_at' => $data['reset_at'],
            'retry_after' => $allowed ? 0 : ($data['reset_at'] - $now),
            'message' => !$allowed ? ($config['message'] ?? 'تعداد درخواست‌ها بیش از حد مجاز است.') : null
        ];
    }
}

if (!function_exists('rate_limit')) {
    /**
     * بررسی و اعمال rate limit با throw کردن exception
     * 
     * @param string $group گروه
     * @param string $endpoint نام endpoint
     * @param string|null $identifier شناسه کاربر (اگر null باشد از IP استفاده می‌شود)
     * @throws \Exception اگر rate limit رد شود
     */
    function rate_limit(string $group, string $endpoint = 'general', ?string $identifier = null): void
    {
        $config = get_rate_limit_config($group, $endpoint);
        
        // تولید کلید منحصر به فرد
        $identifier = $identifier ?? get_client_ip();
        $key = "{$group}:{$endpoint}:{$identifier}";
        
        $result = check_rate_limit($key, $config);
        
        if (!$result['allowed']) {
            // لاگ کردن
            if (function_exists('logger')) {
                logger()->warning('Rate limit exceeded', [
                    'group' => $group,
                    'endpoint' => $endpoint,
                    'identifier' => $identifier,
                    'ip' => get_client_ip(),
                ]);
            }
            
            throw new \Exception($result['message'], 429);
        }
    }
}

