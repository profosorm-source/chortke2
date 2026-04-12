<?php

namespace App\Middleware;

use Core\Request;
use Core\Response;

/**
 * HTTPS Enforcement Middleware
 *
 * در محیط production هر درخواست HTTP را به HTTPS هدایت می‌کند
 * و هدرهای امنیتی ضروری را اضافه می‌کند.
 */
class HttpsMiddleware
{
    public function handle(Request $request, Response $response): bool
    {
        $env = config('app.env', env('APP_ENV', 'production'));

        if ($env === 'production' && !$this->isSecure($request)) {
            $host     = $_SERVER['HTTP_HOST'] ?? '';
            $uri      = $_SERVER['REQUEST_URI'] ?? '/';

            // sanitize برای جلوگیری از header injection
            $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $host);
            $uri  = preg_replace('/[\r\n]/', '', $uri);

            $httpsUrl = 'https://' . $host . $uri;

            header('Location: ' . $httpsUrl, true, 301);
            exit;
        }

        // افزودن هدرهای امنیتی HTTPS در production
        if ($env === 'production') {
            // HSTS — مرورگر را مجبور می‌کند همیشه HTTPS استفاده کند
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }

        return true;
    }

    /**
     * بررسی اینکه درخواست از طریق HTTPS آمده یا نه
     */
    private function isSecure(Request $request): bool
    {
        // بررسی مستقیم HTTPS
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            return true;
        }

        // بررسی پورت 443
        if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            return true;
        }

        // بررسی X-Forwarded-Proto فقط از IP‌های معتبر proxy
        $trustedProxies = array_filter(array_map('trim', explode(',', (string)env('TRUSTED_PROXIES', ''))));
        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($trustedProxies) && in_array($remoteIp, $trustedProxies, true)) {
            $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
            if (strtolower($forwardedProto) === 'https') {
                return true;
            }
        }

        return false;
    }
}
