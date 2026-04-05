<?php
namespace Core;

/**
 * Rate Limiter — Redis + File Fallback
 *
 * اگر Redis در دسترس باشد از INCR + EXPIRE (atomic) استفاده می‌کند.
 * در غیر این صورت به فایل JSON سوئیچ می‌کند (رفتار قدیمی).
 *
 * تغییرات نسبت به نسخه قدیمی:
 *   - حذف Database وابستگی (دیگر نیازی به DB نیست)
 *   - Redis atomic INCR برای جلوگیری از race condition
 *   - API مشابه قبل (سازگار با RateLimitMiddleware موجود)
 */
class RateLimiter
{
    private ?Cache $cacheInstance;
    private bool   $useRedis = false;
    private string $cacheDir;

    public function __construct()
    {
        $this->cacheDir = __DIR__ . '/../storage/cache/rate_limit/';

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        // استفاده از همان Cache singleton
        $this->cacheInstance = Cache::getInstance();
        $this->useRedis      = $this->cacheInstance->driver() === 'redis';
    }

    // ─────────────────────────────────────────────────
    //  عملیات اصلی
    // ─────────────────────────────────────────────────

    /**
     * یک تلاش ثبت می‌کند. اگر از حد مجاز بگذرد false برمی‌گرداند.
     */
    public function attempt(string $key, ?int $maxAttempts = null, ?int $decayMinutes = null): bool
    {
        $maxAttempts  = $maxAttempts  ?? (int) config('rate_limit.max_attempts', 5);
        $decayMinutes = $decayMinutes ?? (int) config('rate_limit.decay_minutes', 1);

        $attempts = $this->getAttempts($key);

        if ($attempts >= $maxAttempts) {
            return false;
        }

        $this->incrementAttempts($key, $decayMinutes);
        return true;
    }

    /**
     * تعداد تلاش‌های فعلی
     */
    public function getAttempts(string $key): int
    {
        if ($this->useRedis) {
            $redis = $this->cacheInstance->redis();
            $val   = $redis->get($this->redisKey($key));
            return $val === false ? 0 : (int) $val;
        }

        return $this->fileGetAttempts($key);
    }

    /**
     * Alias برای getAttempts
     */
    public function hits(string $key): int
    {
        return $this->getAttempts($key);
    }

    /**
     * افزایش شمارنده
     */
    public function incrementAttempts(string $key, int $decayMinutes): void
    {
        if ($this->useRedis) {
            $redis   = $this->cacheInstance->redis();
            $rKey    = $this->redisKey($key);
            $ttl     = $decayMinutes * 60;

            // Lua script برای atomic INCR + EXPIRE
            $script = <<<LUA
local current = redis.call('INCR', KEYS[1])
if current == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return current
LUA;
            
            $redis->eval($script, [$rKey, $ttl], 1);
            return;
        }

        $this->fileIncrementAttempts($key, $decayMinutes);
    }

    /**
     * ریست کامل کلید
     */
    public function clear(string $key): void
    {
        if ($this->useRedis) {
            $this->cacheInstance->redis()->del($this->redisKey($key));
            return;
        }

        $file = $this->getCacheFile($key);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * ثانیه‌های باقی‌مانده تا ریست
     */
    public function availableIn(string $key): int
    {
        if ($this->useRedis) {
            $ttl = $this->cacheInstance->redis()->ttl($this->redisKey($key));
            return max(0, (int) $ttl);
        }

        return $this->fileAvailableIn($key);
    }

    // ─────────────────────────────────────────────────
    //  متدهای کاربردی آماده
    // ─────────────────────────────────────────────────

    /** بررسی تلاش‌های ورود */
    public function checkLoginAttempt(string $identifier): array
    {
        $key = 'login:' . $identifier;

        if (!$this->attempt($key, 5, 15)) {
            $seconds = $this->availableIn($key);
            $minutes = (int) ceil($seconds / 60);

            logger('security', 'Too many login attempts', [
                'identifier' => $identifier,
                'ip'         => get_client_ip(),
            ]);

            return [
                'allowed'     => false,
                'message'     => "تعداد تلاش‌های شما بیش از حد مجاز است. لطفاً {$minutes} دقیقه دیگر امتحان کنید.",
                'retry_after' => $seconds,
            ];
        }

        return ['allowed' => true];
    }

    /** پاک کردن بعد از ورود موفق */
    public function clearLoginAttempts(string $identifier): void
    {
        $this->clear('login:' . $identifier);
    }

    /** بررسی لیمیت API */
    public function checkApiLimit(int $userId, int $maxRequests = 60, int $perMinutes = 1): array
    {
        $key = 'api:' . $userId;

        if (!$this->attempt($key, $maxRequests, $perMinutes)) {
            return [
                'allowed'     => false,
                'message'     => 'Too many requests',
                'retry_after' => $this->availableIn($key),
            ];
        }

        return ['allowed' => true];
    }

    /** پاکسازی فایل‌های منقضی — فقط در حالت فایل */
    public function cleanup(): int
    {
        if ($this->useRedis) {
            return 0; // Redis خودش TTL می‌زند
        }

        $files   = glob($this->cacheDir . '*.json') ?: [];
        $now     = time();
        $cleaned = 0;

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && $data['expire_at'] < $now) {
                unlink($file);
                $cleaned++;
            }
        }

        logger('info', "Rate limit cleanup: {$cleaned} files removed");
        return $cleaned;
    }

    // ─────────────────────────────────────────────────
    //  پشتیبان فایل
    // ─────────────────────────────────────────────────

    private function fileGetAttempts(string $key): int
    {
        $file = $this->getCacheFile($key);
        if (!file_exists($file)) {
            return 0;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data || $data['expire_at'] < time()) {
            $this->clear($key);
            return 0;
        }

        return (int) $data['attempts'];
    }

    private function fileIncrementAttempts(string $key, int $decayMinutes): void
    {
        $file     = $this->getCacheFile($key);
        $attempts = $this->fileGetAttempts($key) + 1;

        file_put_contents($file, json_encode([
            'attempts'  => $attempts,
            'expire_at' => time() + ($decayMinutes * 60),
        ]));
    }

    private function fileAvailableIn(string $key): int
    {
        $file = $this->getCacheFile($key);
        if (!file_exists($file)) {
            return 0;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!$data || $data['expire_at'] < time()) {
            return 0;
        }

        return max(0, $data['expire_at'] - time());
    }

    // ─────────────────────────────────────────────────
    //  کمکی‌ها
    // ─────────────────────────────────────────────────

    private function redisKey(string $key): string
    {
        $prefix = env('REDIS_PREFIX', 'chortke');
        return $prefix . ':rl:' . $key;
    }

    private function getCacheFile(string $key): string
    {
        return $this->cacheDir . md5($key) . '.json';
    }
}
