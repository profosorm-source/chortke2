<?php

namespace App\Middleware;

use App\Services\CaptchaService;
use Core\Container;
use Core\Request;
use Core\Response;

class CaptchaMiddleware
{
    private CaptchaService $captchaService;

    public function __construct()
    {
        // از Container استفاده می‌کنیم — نه new مستقیم که نیاز به DI دارد
        $this->captchaService = Container::getInstance()->make(CaptchaService::class);
    }

    public function handle(Request $request, \Closure $next)
    {
        if (!$this->captchaService->isEnabled()) {
            return $next($request);
        }

        // فقط برای POST
        if ($request->method() !== 'POST') {
            return $next($request);
        }

        if (!verify_captcha()) {
            $response = new Response();

            if ($request->isAjax()) {
                return $response->json([
                    'success' => false,
                    'message' => 'کد امنیتی اشتباه است. لطفاً دوباره تلاش کنید.',
                ]);
            }

            session()->setFlash('error', 'کد امنیتی اشتباه است.');
            session()->setFlash('old', $request->all());

            return redirect($_SERVER['HTTP_REFERER'] ?? '/');
        }

        return $next($request);
    }
}