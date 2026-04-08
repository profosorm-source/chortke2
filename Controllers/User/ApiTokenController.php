<?php

namespace App\Controllers\User;

use Core\Database;
use App\Controllers\User\BaseUserController;

class ApiTokenController extends BaseUserController
{
    private Database $db;

    public function __construct(Database $db){
        parent::__construct();
        $this->db = $db;
        }

    /** لیست توکن‌های کاربر */
    public function index(): void
    {
        $userId = (int)user_id();
        $tokens = $this->db->fetchAll(
            "SELECT id, name, scopes, last_used_at, use_count, expires_at, created_at
             FROM api_tokens
             WHERE user_id = ? AND revoked = 0
             ORDER BY created_at DESC",
            [$userId]
        );

        $newToken = $this->session->getFlash('new_api_token');

        view('user.api-tokens.index', [
            'title'    => 'توکن‌های API',
            'tokens'   => $tokens,
            'newToken' => $newToken,
        ]);
    }

    /** ساخت توکن جدید */
    public function create(): void
    {
        $userId    = (int)user_id();
                $name      = trim($this->request->post('name') ?? '');
        $expiresIn = (int)($this->request->post('expires_in') ?? 30);

        if (empty($name)) {
            $this->session->setFlash('error', 'نام توکن الزامی است');
            redirect(url('/api-tokens'));
            return;
        }

        // حداکثر ۱۰ توکن فعال
        $count = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM api_tokens WHERE user_id = ? AND revoked = 0",
            [$userId]
        );

        if ($count >= 10) {
            $this->session->setFlash('error', 'حداکثر ۱۰ توکن فعال مجاز است. ابتدا یکی را باطل کنید.');
            redirect(url('/api-tokens'));
            return;
        }

        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = $expiresIn > 0
            ? date('Y-m-d H:i:s', strtotime("+{$expiresIn} days"))
            : null;

        $this->db->query(
            "INSERT INTO api_tokens (user_id, token, name, scopes, expires_at, created_at)
             VALUES (?, ?, ?, 'read', ?, NOW())",
            [$userId, $tokenHash, $name, $expiresAt]
        );

        // توکن خام فقط یک‌بار نمایش داده می‌شود؛ در DB هش ذخیره شده
        $this->session->setFlash('new_api_token', $token);
        $this->session->setFlash('success', 'توکن با موفقیت ساخته شد');
        redirect(url('/api-tokens'));
    }

    /** باطل کردن توکن */
    public function revoke(): void
    {
        $userId   = (int)user_id();
                        $id       = (int)$this->request->param('id');

        $this->db->query(
            "UPDATE api_tokens SET revoked = 1, revoked_at = NOW()
             WHERE id = ? AND user_id = ? AND revoked = 0",
            [$id, $userId]
        );
        $affected = $this->db->rowCount();

        $this->response->json([
            'success' => $affected > 0,
            'message' => $affected ? 'توکن باطل شد' : 'توکن یافت نشد',
        ]);
    }
}
