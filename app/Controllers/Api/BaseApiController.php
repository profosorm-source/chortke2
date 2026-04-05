<?php

namespace App\Controllers\Api;

use Core\Request;

/**
 * BaseApiController - کنترلر پایه API
 *
 * همه API controllers باید از این کلاس extend کنند.
 * متدهای کمکی برای پاسخ‌های استاندارد JSON.
 */
abstract class BaseApiController
{
    protected Request $request;

    public function __construct(?Request $request = null)
    {
        $this->request = $request ?? app()->request;
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    /** پاسخ موفق */
    protected function success(mixed $data = null, string $message = '', int $code = 200): never
    {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** پاسخ با pagination */
    protected function paginated(array $items, int $total, int $page, int $perPage): never
    {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data'    => $items,
            'meta'    => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int)ceil($total / $perPage),
                'from'         => ($page - 1) * $perPage + 1,
                'to'           => min($page * $perPage, $total),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** پاسخ خطا */
    protected function error(string $message, int $code = 400, ?string $errorCode = null): never
    {
        http_response_code($code);
        echo json_encode([
            'success'    => false,
            'message'    => $message,
            'error_code' => $errorCode,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** خطای اعتبارسنجی */
    protected function validationError(array $errors): never
    {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'خطای اعتبارسنجی',
            'errors'  => $errors,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** کاربر جاری (از middleware inject شده) */
    protected function currentUser(): ?object
    {
        return $this->request->getUser();
    }

    /** ID کاربر جاری */
    protected function userId(): int
    {
        return (int)($this->currentUser()->id ?? 0);
    }

    /** دریافت pagination params */
    protected function paginationParams(int $defaultPerPage = 20): array
    {
        $page    = max(1, (int)($this->request->get('page') ?? 1));
        $perPage = min(100, max(1, (int)($this->request->get('per_page') ?? $defaultPerPage)));
        $offset  = ($page - 1) * $perPage;
        return [$page, $perPage, $offset];
    }
}
