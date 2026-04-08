<?php

namespace App\Controllers\Api;

use Core\Database;
use Core\Session;

/**
 * API\TokenController - مدیریت API Token
 *
 * POST /api/v1/auth/token    → دریافت token با credentials
 * POST /api/v1/auth/revoke   → باطل کردن token
 * GET  /api/v1/auth/tokens   → لیست tokenهای فعال (نیاز به auth)
 */
class TokenController extends BaseApiController
{
    private Database $db;

    public function __construct(Database $db){
        parent::__construct();
        $this->db = $db;
        }

    /**
     * دریافت API Token با email/password
     * این endpoint نیاز به middleware auth ندارد
     */
    public function issue(): never
    {
        $data = $this->request->body();

        if (empty($data['email']) || empty($data['password'])) {
            $this->validationError([
                'email'    => empty($data['email'])    ? 'ایمیل الزامی است' : null,
                'password' => empty($data['password']) ? 'رمز الزامی است'  : null,
            ]);
        }

        // پیدا کردن کاربر
        $user = $this->db->fetch(
            "SELECT * FROM users WHERE email = ? AND deleted_at IS NULL LIMIT 1",
            [$data['email']]
        );

        if (!$user || !password_verify($data['password'], $user->password)) {
            $this->error('ایمیل یا رمز عبور اشتباه است', 401, 'INVALID_CREDENTIALS');
        }

        if ((int)$user->status !== 1) {
            $this->error('حساب کاربری غیرفعال است', 403, 'ACCOUNT_INACTIVE');
        }

        // ساخت token
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        $name      = $data['token_name'] ?? 'api-token-' . date('Ymd');

        $this->db->query(
            "INSERT INTO api_tokens (user_id, token, name, scopes, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [$user->id, $token, $name, $data['scopes'] ?? 'read', $expiresAt]
        );

        $this->success([
            'token'      => $token,
            'type'       => 'Bearer',
            'expires_at' => $expiresAt,
            'name'       => $name,
        ], 'توکن با موفقیت صادر شد', 201);
    }

    /**
     * باطل کردن token جاری
     */
    public function revoke(): never
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token      = str_starts_with($authHeader, 'Bearer ') ? trim(substr($authHeader, 7)) : null;

        if (!$token) {
            $this->error('توکن یافت نشد', 400);
        }

        $this->db->query(
            "UPDATE api_tokens SET revoked = 1, revoked_at = NOW() WHERE token = ?",
            [$token]
        );

        $this->success(null, 'توکن با موفقیت باطل شد');
    }

    /**
     * لیست tokenهای فعال کاربر
     */
    public function list(): never
    {
        $userId = $this->userId();

        $tokens = $this->db->fetchAll(
            "SELECT id, name, scopes, last_used_at, use_count, expires_at, created_at
             FROM api_tokens
             WHERE user_id = ? AND revoked = 0
             ORDER BY created_at DESC",
            [$userId]
        );

        $this->success($tokens);
    }

    /**
     * باطل کردن یک token خاص
     */
    public function revokeById(): never
    {
        $userId  = $this->userId();
        $tokenId = (int)($this->request->get('id') ?? 0);

        if (!$tokenId) {
            $this->error('ID توکن الزامی است', 400);
        }

        $affected = $this->db->query(
            "UPDATE api_tokens SET revoked = 1, revoked_at = NOW()
             WHERE id = ? AND user_id = ? AND revoked = 0",
            [$tokenId, $userId]
        );

        if (!$affected) {
            $this->error('توکن یافت نشد', 404);
        }

        $this->success(null, 'توکن باطل شد');
    }
}
