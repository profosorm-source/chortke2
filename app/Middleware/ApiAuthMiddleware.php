<?php

namespace App\Middleware;

use Core\Request;
use Core\Response;
use Core\Database;
use Core\Container;

/**
 * ApiAuthMiddleware — احراز هویت API
 *
 * Database از Container inject می‌شود (نه مستقیم)
 */
class ApiAuthMiddleware
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function handle(Request $request, Response $response): bool
    {
        $token = $this->extractToken($request);

        if (!$token) {
            $this->unauthorized('توکن API ارائه نشده');
            return false;
        }

        $user = $this->validateToken($token);

        if (!$user) {
            $this->unauthorized('توکن نامعتبر یا منقضی شده');
            return false;
        }

        if ((int)($user->status ?? 1) !== 1) {
            $this->unauthorized('حساب کاربری غیرفعال است');
            return false;
        }

        $request->setUser($user);

        $this->db->query(
            "UPDATE api_tokens SET last_used_at = NOW(), use_count = use_count + 1 WHERE token = ?",
            [$token]
        );

        return true;
    }

    private function extractToken(Request $request): ?string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($authHeader, 'Bearer ')) {
            return trim(substr($authHeader, 7));
        }
        return null;
    }

    private function validateToken(string $token): ?object
    {
        return $this->db->fetch(
            "SELECT u.*, at.id as token_id, at.scopes
             FROM api_tokens at
             JOIN users u ON u.id = at.user_id
             WHERE at.token = ?
               AND (at.expires_at IS NULL OR at.expires_at > NOW())
               AND at.revoked = 0
             LIMIT 1",
            [$token]
        ) ?: null;
    }

    private function unauthorized(string $message): void
    {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message, 'error' => 'UNAUTHORIZED']);
        exit;
    }
}
