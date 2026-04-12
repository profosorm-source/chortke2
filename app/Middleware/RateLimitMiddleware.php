<?php
namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\RateLimiter;

/**
 * Rate Limit Middleware
 *
 * محدودسازی تعداد درخواست‌ها با تنظیمات per-route
 */
class RateLimitMiddleware
{
    private $rateLimiter;
    private $maxAttempts;
    private $decayMinutes;

    /**
     * محدودیت‌های سختگیرانه برای endpoints حساس
     * کلید: بخشی از URI — مقدار: [max_attempts, decay_minutes]
     */
    private const ROUTE_LIMITS = [
        '/login'            => [5,  5],   // 5 تلاش در 5 دقیقه
        '/admin/login'      => [3,  10],  // 3 تلاش در 10 دقیقه
        '/register'         => [3,  30],  // 3 ثبت‌نام در 30 دقیقه
        '/forgot-password'  => [3,  60],  // 3 درخواست در 1 ساعت
        '/reset-password'   => [3,  60],
        '/payment'          => [10, 1],   // 10 تراکنش در دقیقه
        '/withdrawal'       => [5,  60],  // 5 برداشت در ساعت
        '/deposit'          => [10, 60],
        '/kyc'              => [5,  60],
        '/api/'             => [100, 1],  // 100 API call در دقیقه
    ];

    public function __construct($maxAttempts = 60, $decayMinutes = 1)
    {
        $this->rateLimiter = new RateLimiter();
        $this->maxAttempts = $maxAttempts;
        $this->decayMinutes = $decayMinutes;
    }

    public function handle(Request $request, \Closure $next)
{
    try {
        $ip  = get_client_ip();
        $uri = $request->uri();
        $key = "rate_limit:{$ip}:{$uri}";

        $maxAttempts   = 60;
        $decayMinutes  = 1;

        $redis = null;
        try {
            $redis = \Core\Cache::getRedis();
        } catch (\Exception $e) {
            logger('warning', 'Redis not available for rate limiting', [
                'error' => $e->getMessage()
            ]);
            return $next($request);
        }

        if (!$redis) {
            logger('warning', 'Rate limiting skipped - Redis unavailable');
            return $next($request);
        }

        $limiter = new \Core\RateLimiter($redis);

        if (!$limiter->attempt($key, $maxAttempts, $decayMinutes)) {
            $retryAfter = $limiter->availableIn($key);
            
            logger('warning', 'Rate limit exceeded', [
                'ip'          => $ip,
                'uri'         => $uri,
                'retry_after' => $retryAfter
            ]);

            return \Core\Response::json([
                'success' => false,
                'message' => 'تعداد درخواست‌های شما بیش از حد مجاز است',
                'retry_after' => $retryAfter
            ], 429)
            ->header('Retry-After', $retryAfter)
            ->header('X-RateLimit-Limit', $maxAttempts)
            ->header('X-RateLimit-Remaining', 0);
        }

        $remaining = $maxAttempts - $limiter->hits($key);
        
        $response = $next($request);
        
        if ($response instanceof \Core\Response) {
            $response->header('X-RateLimit-Limit', $maxAttempts)
                     ->header('X-RateLimit-Remaining', max(0, $remaining));
        }

        return $response;

    } catch (\Throwable $e) {
        logger('error', 'Rate limit middleware failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        return $next($request);
    }
}

    /**
     * تعیین محدودیت بر اساس URI درخواست
     */
    private function resolveLimit(Request $request): array
    {
        $uri = $request->uri();

        foreach (self::ROUTE_LIMITS as $pattern => $limits) {
            if (str_contains($uri, $pattern)) {
                return $limits;
            }
        }

        return [$this->maxAttempts, $this->decayMinutes];
    }

    /**
     * ایجاد کلید منحصر به فرد برای درخواست
     * کلید شامل URI هم هست تا limits مختلف برای routes مختلف اعمال شود
     */
    private function resolveRequestSignature(Request $request)
    {
        $userId = app()->session->get('user_id');
        $uri    = $request->uri();

        if ($userId) {
            return 'rate_limit_user_' . $userId . '_' . md5($uri);
        }

        return 'rate_limit_ip_' . sha1(get_client_ip()) . '_' . md5($uri);
    }
}
