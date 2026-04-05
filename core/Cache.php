<?php

declare(strict_types=1);
namespace Core;

/**
 * Cache System — Redis + File Fallback
 *
 * اگر Redis موجود باشد، از آن استفاده می‌کند؛
 * در غیر این صورت به‌صورت خودکار به کش فایلی
 * (همان رفتار قدیمی) سوئیچ می‌کند.
 *
 * متدها:
 *   put($key, $value, $minutes)
 *   get($key, $default)
 *   has($key)
 *   forget($key)
 *   flush()
 *   remember($key, $minutes, callable)
 *   rememberForever($key, callable)
 *   forever($key, $value)
 *   increment($key, $step)
 *   decrement($key, $step)
 *   ttl($key)             → ثانیه‌های باقی‌مانده
 *   tags(array $tags)     → TaggedCache
 *   cleanup()             → فقط در حالت فایل
 *   driver()              → 'redis' | 'file'
 */
class Cache
{
    private static ?self $instance = null;

    private string $driver = 'file';
    private ?\Redis $redis = null;
    private string $redisPrefix = 'chortke:';

    private string $cacheDir;

    // ─────────────────────────────────────────────────
    //  Bootstrap
    // ─────────────────────────────────────────────────

    private function __construct()
    {
        $this->cacheDir = __DIR__ . '/../storage/cache/app/';

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        $this->tryConnectRedis();
    }

    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    /** نوع درایور فعال: 'redis' یا 'file' */
    public function driver(): string
    {
        return $this->driver;
    }

    // ─────────────────────────────────────────────────
    //  اتصال Redis
    // ─────────────────────────────────────────────────

    private function tryConnectRedis(): void
    {
        if (!extension_loaded('redis')) {
            return;
        }

        $enabled = env('REDIS_ENABLED', 'true');
        if (in_array(strtolower((string)$enabled), ['false', '0', 'no', 'off'], true)) {
            return;
        }

        $host     = env('REDIS_HOST', '127.0.0.1');
        $port     = (int) env('REDIS_PORT', 6379);
        $password = env('REDIS_PASSWORD', '');
        $db       = (int) env('REDIS_DB', 0);
        $timeout  = (float) env('REDIS_TIMEOUT', 1.5);
        $this->redisPrefix = env('REDIS_PREFIX', 'chortke') . ':';

        try {
            $r = new \Redis();
            if (!$r->connect($host, $port, $timeout)) {
                return;
            }

            if ($password !== '') {
                $r->auth($password);
            }

            $r->select($db);
            $r->ping(); // تست واقعی اتصال

            $this->redis  = $r;
            $this->driver = 'redis';
        } catch (\Throwable) {
            $this->redis  = null;
            $this->driver = 'file';
        }
    }

    // ─────────────────────────────────────────────────
    //  عملیات اصلی
    // ─────────────────────────────────────────────────

    public function put(string $key, mixed $value, int $minutes = 60): bool
    {
        if ($this->driver === 'redis') {
            return (bool) $this->redis->setEx(
                $this->redisKey($key),
                $minutes * 60,
                serialize($value)
            );
        }

        return $this->filePut($key, $value, $minutes);
    }

    /**
     * Alias برای put (برای سازگاری)
     */
    public function set(string $key, mixed $value, int $minutes = 60): bool
    {
        return $this->put($key, $value, $minutes);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->driver === 'redis') {
            $raw = $this->redis->get($this->redisKey($key));
            if ($raw === false) {
                return $default;
            }
            return unserialize($raw);
        }

        return $this->fileGet($key, $default);
    }

    public function has(string $key): bool
    {
        if ($this->driver === 'redis') {
            return (bool) $this->redis->exists($this->redisKey($key));
        }

        return $this->fileHas($key);
    }

    public function forget(string $key): bool
    {
        if ($this->driver === 'redis') {
            return (bool) $this->redis->del($this->redisKey($key));
        }

        return $this->fileForget($key);
    }

    public function flush(): bool
    {
        if ($this->driver === 'redis') {
            // فقط کلیدهای این پروژه را پاک می‌کند (نه همه Redis)
            $keys = $this->redis->keys($this->redisPrefix . '*');
            if (!empty($keys)) {
                $this->redis->del($keys);
            }
            return true;
        }

        return $this->fileFlush();
    }

    public function remember(string $key, int $minutes, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->put($key, $value, $minutes);
        return $value;
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->forever($key, $value);
        return $value;
    }

    public function forever(string $key, mixed $value): bool
    {
        if ($this->driver === 'redis') {
            return (bool) $this->redis->set(
                $this->redisKey($key),
                serialize($value)
            );
        }

        return $this->filePut($key, $value, 525_600); // ~1 سال
    }

    // ─────────────────────────────────────────────────
    //  Counter (atomic در Redis)
    // ─────────────────────────────────────────────────

    public function increment(string $key, int $step = 1): int|false
    {
        if ($this->driver === 'redis') {
            return $step === 1
                ? $this->redis->incr($this->redisKey($key))
                : $this->redis->incrBy($this->redisKey($key), $step);
        }

        $current = (int) $this->get($key, 0);
        $new     = $current + $step;
        $this->forever($key, $new);
        return $new;
    }

    public function decrement(string $key, int $step = 1): int|false
    {
        return $this->increment($key, -$step);
    }

    // ─────────────────────────────────────────────────
    //  TTL باقی‌مانده (ثانیه)
    // ─────────────────────────────────────────────────

    public function ttl(string $key): int
    {
        if ($this->driver === 'redis') {
            return (int) $this->redis->ttl($this->redisKey($key));
        }

        $file = $this->cacheFile($key);
        if (!file_exists($file)) {
            return -2;
        }
        $data = @unserialize(file_get_contents($file));
        if ($data === false) {
            return -2;
        }
        $left = $data['expire_at'] - time();
        return max(-1, $left);
    }

    // ─────────────────────────────────────────────────
    //  Tagged Cache
    // ─────────────────────────────────────────────────

    /**
     * کش با تگ — امکان flush گروهی
     *
     * مثال:
     *   cache()->tags(['users'])->put('user:1', $data, 10);
     *   cache()->tags(['users'])->flush();
     */
    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache($this, $tags);
    }

    // ─────────────────────────────────────────────────
    //  Redis Raw (برای RateLimiter و سایر موارد خاص)
    // ─────────────────────────────────────────────────

    public function redis(): ?\Redis
    {
        return $this->redis;
    }

    // ─────────────────────────────────────────────────
    //  Cleanup — فقط در حالت فایل
    // ─────────────────────────────────────────────────

    public function cleanup(): int
    {
        if ($this->driver === 'redis') {
            logger('info', 'Cache cleanup skipped — Redis manages TTL automatically');
            return 0;
        }

        $files   = glob($this->cacheDir . '*.cache') ?: [];
        $cleaned = 0;

        foreach ($files as $file) {
            $data = @unserialize(file_get_contents($file));
            if ($data === false || $data['expire_at'] < time()) {
                @unlink($file);
                $cleaned++;
            }
        }

        logger('info', "File cache cleanup: {$cleaned} files removed");
        return $cleaned;
    }

    // ─────────────────────────────────────────────────
    //  پشتیبان فایل
    // ─────────────────────────────────────────────────

    private function filePut(string $key, mixed $value, int $minutes): bool
    {
        $data = [
            'expire_at' => time() + ($minutes * 60),
            'value'     => $value,
        ];
        return (bool) file_put_contents($this->cacheFile($key), serialize($data));
    }

    private function fileGet(string $key, mixed $default): mixed
    {
        $file = $this->cacheFile($key);
        if (!file_exists($file)) {
            return $default;
        }

        $data = @unserialize(file_get_contents($file));
        if ($data === false || $data['expire_at'] < time()) {
            @unlink($file);
            return $default;
        }

        return $data['value'];
    }

    private function fileHas(string $key): bool
    {
        $file = $this->cacheFile($key);
        if (!file_exists($file)) {
            return false;
        }
        $data = @unserialize(file_get_contents($file));
        if ($data === false || $data['expire_at'] < time()) {
            @unlink($file);
            return false;
        }
        return true;
    }

    private function fileForget(string $key): bool
    {
        $file = $this->cacheFile($key);
        if (file_exists($file)) {
            return unlink($file);
        }
        return false;
    }

    private function fileFlush(): bool
    {
        foreach (glob($this->cacheDir . '*.cache') ?: [] as $file) {
            @unlink($file);
        }
        return true;
    }

    private function cacheFile(string $key): string
    {
        return $this->cacheDir . md5($key) . '.cache';
    }

    private function redisKey(string $key): string
    {
        return $this->redisPrefix . $key;
    }

    private function __clone() {}

    public function __wakeup(): never
    {
        throw new \RuntimeException('Cannot unserialize singleton Cache');
    }
}


// ═══════════════════════════════════════════════════════════════
//  Tagged Cache
// ═══════════════════════════════════════════════════════════════

/**
 * کش تگ‌دار — امکان flush گروهی کلیدها
 *
 * Redis: از Sets استفاده می‌کند (سریع و atomic)
 * File:  کلیدهای هر تگ را در یک فایل JSON ذخیره می‌کند
 */
class TaggedCache
{
    public function __construct(
        private Cache $cache,
        private array $tags
    ) {}

    public function put(string $key, mixed $value, int $minutes = 60): bool
    {
        $taggedKey = $this->taggedKey($key);
        $this->registerKey($taggedKey);
        return $this->cache->put($taggedKey, $value, $minutes);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->taggedKey($key), $default);
    }

    public function has(string $key): bool
    {
        return $this->cache->has($this->taggedKey($key));
    }

    public function forget(string $key): bool
    {
        $taggedKey = $this->taggedKey($key);
        $this->unregisterKey($taggedKey);
        return $this->cache->forget($taggedKey);
    }

    public function remember(string $key, int $minutes, callable $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }
        $value = $callback();
        $this->put($key, $value, $minutes);
        return $value;
    }

    /** حذف تمام کلیدهای این تگ */
    public function flush(): void
    {
        if ($this->cache->driver() === 'redis') {
            $redis = $this->cache->redis();
            foreach ($this->tags as $tag) {
                $setKey  = 'tag:' . $tag;
                $members = $redis->sMembers($setKey);
                if (!empty($members)) {
                    $redis->del($members);
                }
                $redis->del($setKey);
            }
            return;
        }

        // File mode
        foreach ($this->tags as $tag) {
            $indexFile = $this->tagIndexFile($tag);
            if (!file_exists($indexFile)) {
                continue;
            }
            $keys = json_decode(file_get_contents($indexFile), true) ?? [];
            foreach ($keys as $k) {
                $this->cache->forget($k);
            }
            @unlink($indexFile);
        }
    }

    // ─────────────────────────────────────────────────

    private function taggedKey(string $key): string
    {
        return implode('|', $this->tags) . ':' . $key;
    }

    private function registerKey(string $taggedKey): void
    {
        if ($this->cache->driver() === 'redis') {
            $redis = $this->cache->redis();
            foreach ($this->tags as $tag) {
                $redis->sAdd('tag:' . $tag, $taggedKey);
            }
            return;
        }

        // FIX C-8: TaggedCache در حالت File بدون قفل بود.
        // دو درخواست همزمان هر دو فایل را می‌خواندند، یکی را می‌نوشتند
        // و کلید دیگری گم می‌شد. فلاک مانع این race condition می‌شود.
        foreach ($this->tags as $tag) {
            $indexFile = $this->tagIndexFile($tag);
            $fh = fopen($indexFile, 'c+');
            if (!$fh) {
                continue;
            }
            if (flock($fh, LOCK_EX)) {
                $content  = stream_get_contents($fh);
                $existing = $content ? (json_decode($content, true) ?? []) : [];
                if (!in_array($taggedKey, $existing, true)) {
                    $existing[] = $taggedKey;
                    ftruncate($fh, 0);
                    rewind($fh);
                    fwrite($fh, json_encode($existing));
                }
                flock($fh, LOCK_UN);
            }
            fclose($fh);
        }
    }

    private function unregisterKey(string $taggedKey): void
    {
        if ($this->cache->driver() === 'redis') {
            $redis = $this->cache->redis();
            foreach ($this->tags as $tag) {
                $redis->sRem('tag:' . $tag, $taggedKey);
            }
            return;
        }

        foreach ($this->tags as $tag) {
            $indexFile = $this->tagIndexFile($tag);
            if (!file_exists($indexFile)) {
                continue;
            }
            $existing = json_decode(file_get_contents($indexFile), true) ?? [];
            $existing = array_values(array_filter($existing, fn($k) => $k !== $taggedKey));
            file_put_contents($indexFile, json_encode($existing));
        }
    }

    private function tagIndexFile(string $tag): string
    {
        $dir = __DIR__ . '/../storage/cache/tags/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir . md5($tag) . '.json';
    }
}
