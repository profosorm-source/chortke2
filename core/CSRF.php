<?php
namespace Core;

/**
 * CSRF Protection
 *
 * از Container می‌خواند — نه app() مستقیم
 */
class CSRF
{
    private static function session(): Session
    {
        return Container::getInstance()->make(Session::class);
    }

    private static function request(): Request
    {
        return Container::getInstance()->make(Request::class);
    }

    public static function generateToken(): string
    {
        $session = self::session();
        if (!$session->has('_csrf_token')) {
            $session->set('_csrf_token', bin2hex(random_bytes(32)));
        }
        return $session->get('_csrf_token');
    }

    public static function getToken(): ?string
    {
        return self::session()->get('_csrf_token');
    }

    public static function verify(?string $token): bool
    {
        $sessionToken = self::getToken();
        if (!$sessionToken || !$token) return false;
        return hash_equals($sessionToken, $token);
    }

    public static function check(): bool
    {
        $request = self::request();
        if (!in_array($request->method(), ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            return true;
        }
        $tokenName = config('csrf.token_name') ?? '_token';
        $token = $request->input($tokenName) ?? $request->header('X-CSRF-TOKEN');
        return self::verify($token);
    }

    public static function validate(): void
    {
        if (!self::check()) {
            if (function_exists('logger')) {
                $request = self::request();
                logger('security', 'CSRF token validation failed', [
                    'ip'     => function_exists('get_client_ip') ? get_client_ip() : 'unknown',
                    'uri'    => $request->uri(),
                    'method' => $request->method(),
                ]);
            }
            if (function_exists('is_ajax') && is_ajax()) {
                http_response_code(403);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            } else {
                http_response_code(403);
                echo 'CSRF token validation failed';
            }
            exit;
        }
    }

    public static function regenerate(): string
    {
        self::session()->remove('_csrf_token');
        return self::generateToken();
    }
}
