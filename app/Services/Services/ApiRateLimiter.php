<?php

namespace App\Services;

use Core\RateLimiter;
use Core\Response;

/**
 * ApiRateLimiter - Rate limiting تخصصی برای endpoint های حساس
 *
 * نحوه استفاده در Controller:
 *
 *   $limiter = new ApiRateLimiter();
 *   $limiter->forWithdrawal(user_id()) || die; // یا redirect
 *
 *   // یا در یک خط:
 *   ApiRateLimiter::check('withdrawal', user_id(), 3, 60); // 3 بار در ساعت
 */
class ApiRateLimiter
{
    private RateLimiter $limiter;

    /** تعریف محدودیت‌های از پیش تعریف‌شده [maxAttempts, decayMinutes] */
    private const PRESETS = [
        // مالی
        'withdrawal'         => [3,  60],   // 3 بار در ساعت
        'manual_deposit'     => [5,  60],   // 5 بار در ساعت
        'crypto_deposit'     => [10, 60],   // 10 بار در ساعت
        'bank_card_add'      => [3,  1440], // 3 بار در روز

        // تسک
        'task_submit'        => [30, 60],   // 30 تسک در ساعت
        'task_dispute'       => [5,  1440], // 5 اعتراض در روز
        'custom_task_submit' => [10, 60],   // 10 تسک سفارشی در ساعت

        // حساب کاربری
        'kyc_submit'         => [3,  1440], // 3 بار در روز
        'profile_update'     => [10, 60],   // 10 بار در ساعت
        'password_change'    => [3,  60],   // 3 بار در ساعت

        // لاتاری
        'lottery_vote'       => [5,  60],   // 5 رأی در ساعت
        'lottery_participate'=> [3,  1440], // 3 بار شرکت در روز

        // تیکت
        'ticket_create'      => [5,  1440], // 5 تیکت در روز
        'ticket_reply'       => [20, 60],   // 20 پاسخ در ساعت

        // سوشال
        'social_account_add' => [5,  1440], // 5 حساب در روز

        // سرمایه‌گذاری
        'investment_create'  => [3,  1440], // 3 سرمایه‌گذاری در روز
    ];

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * بررسی با preset از پیش تعریف‌شده
     *
     * @return bool  true = مجاز، false = محدود شده
     */
    public function check(string $preset, int $userId, ?int $max = null, ?int $decayMinutes = null): bool
    {
        [$defaultMax, $defaultDecay] = self::PRESETS[$preset] ?? [60, 1];

        $max          = $max          ?? $defaultMax;
        $decayMinutes = $decayMinutes ?? $defaultDecay;

        $key = "rl_{$preset}_user_{$userId}";

        return $this->limiter->attempt($key, $max, $decayMinutes);
    }

    /**
     * بررسی بر اساس IP (برای کاربران مهمان)
     */
    public function checkByIp(string $preset, string $ip, ?int $max = null, ?int $decayMinutes = null): bool
    {
        [$defaultMax, $defaultDecay] = self::PRESETS[$preset] ?? [60, 1];

        $max          = $max          ?? $defaultMax;
        $decayMinutes = $decayMinutes ?? $defaultDecay;

        $key = "rl_{$preset}_ip_" . sha1($ip);

        return $this->limiter->attempt($key, $max, $decayMinutes);
    }

    /**
     * باقی‌مانده تلاش‌ها
     */
    public function remaining(string $preset, int $userId): int
    {
        [$max] = self::PRESETS[$preset] ?? [60, 1];
        $key   = "rl_{$preset}_user_{$userId}";
        $used  = $this->limiter->getAttempts($key);
        return max(0, $max - $used);
    }

    /**
     * زمان تا بازنشینی (ثانیه)
     */
    public function retryAfter(string $preset, int $userId): int
    {
        $key = "rl_{$preset}_user_{$userId}";
        return $this->limiter->availableIn($key) ?? 0;
    }

    /**
     * پاسخ 429 استاندارد (JSON یا HTML)
     */
    public function tooManyResponse(string $preset, int $userId, bool $isAjax = false): never
    {
        $retryAfter  = $this->retryAfter($preset, $userId);
        $retryMins   = (int)ceil($retryAfter / 60);

        http_response_code(429);
        header('Retry-After: ' . $retryAfter);

        if ($isAjax || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'     => false,
                'message'     => "تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً {$retryMins} دقیقه دیگر تلاش کنید.",
                'retry_after' => $retryAfter,
                'error_code'  => 'RATE_LIMITED',
            ]);
        } else {
            echo "<h1>429 - تعداد درخواست‌ها بیش از حد</h1>";
            echo "<p>لطفاً {$retryMins} دقیقه دیگر تلاش کنید.</p>";
        }

        exit;
    }

    /**
     * Static shorthand - بررسی و در صورت محدودیت، خروج خودکار
     *
     * مثال: ApiRateLimiter::enforce('withdrawal', user_id());
     */
    public static function enforce(string $preset, int $userId, bool $isAjax = false): void
    {
        $instance = \Core\Container::getInstance()->make(self::class);
        if (!$instance->check($preset, $userId)) {
            $instance->tooManyResponse($preset, $userId, $isAjax);
        }
    }
}
