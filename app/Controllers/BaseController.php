<?php

namespace App\Controllers;

use Core\Session;
use Core\Request;
use Core\Response;

/**
 * BaseController — پایه تمام کنترلرهای پروژه
 *
 * ─── جریان صحیح (تعریف‌شده) ───────────────────────────────────
 *
 *   Container::make(UserController)
 *       └─→ UserController::__construct()          ← هیچ پارامتری لازم نیست
 *               └─→ BaseController::__construct()
 *                       └─→ از Container: Request, Response, Session
 *
 * ─── قرارداد ───────────────────────────────────────────────────
 *   $this->request   → Core\Request   (singleton از Container)
 *   $this->response  → Core\Response  (singleton از Container)
 *   $this->session   → Core\Session   (singleton از Container)
 *
 * ─── تذکر مهم ──────────────────────────────────────────────────
 *   هیچ کنترلری نباید مستقیم از Database یا Model استفاده کند.
 *   وابستگی‌ها باید از طریق Service به Controller تزریق شوند.
 */
abstract class BaseController
{
    protected Session  $session;
    protected Request  $request;
    protected Response $response;

    /**
     * Container، Request/Response/Session را inject می‌کند.
     * هیچ پارامتر اضافه‌ای نباید وجود داشته باشد — همه چیز از Container می‌آید.
     */
    public function __construct()
    {
        $container = \Core\Container::getInstance();

        $this->request  = $container->make(Request::class);
        $this->response = $container->make(Response::class);
        $this->session  = $container->make(Session::class);
    }

    // ─────────────────────────────────────────────────────────────
    // Auth Helpers
    // ─────────────────────────────────────────────────────────────

    /** user_id کاربر لاگین‌شده یا null */
    protected function userId(): ?int
    {
        $id = $this->session->get('user_id');
        return $id ? (int) $id : null;
    }

    /** اگر لاگین نباشد → redirect به login */
    protected function requireAuth(): void
    {
        if (!$this->userId()) {
            if (is_ajax()) {
                $this->response->error('احراز هویت لازم است', [], 401);
                exit;
            }
            $this->session->setFlash('error', 'ابتدا وارد حساب کاربری خود شوید.');
            $this->response->redirect(url('login'));
            exit;
        }
    }

    /** اگر admin نباشد → 403 */
    protected function requireAdmin(): void
    {
        $role = $this->session->get('user_role');
        if (!in_array($role, ['admin', 'super_admin', 'support'], true)) {
            if (is_ajax()) {
                $this->response->error('دسترسی غیرمجاز', [], 403);
                exit;
            }
            $this->response->redirect(url('dashboard'));
            exit;
        }
    }

    /** بررسی permission خاص */
    protected function requirePermission(string $permission): void
    {
        if ($this->session->get('user_role') === 'super_admin') {
            return;
        }

        $permissions = $this->session->get('user_permissions', []);
        if (!in_array($permission, (array) $permissions, true)) {
            if (is_ajax()) {
                $this->response->error('مجوز کافی ندارید', [], 403);
                exit;
            }
            $this->session->setFlash('error', 'مجوز کافی ندارید.');
            $this->back();
            exit;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Response Helpers
    // ─────────────────────────────────────────────────────────────

    protected function json(bool $success, string $message = '', array $data = [], int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data'    => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    protected function jsonSuccess(string $message = '', array $data = []): void
    {
        $this->json(true, $message, $data, 200);
    }

    protected function jsonError(string $message, array $data = [], int $code = 422): void
    {
        $this->json(false, $message, $data, $code);
    }

    /** redirect به صفحه قبلی (یا fallback) */
    protected function back(string $fallback = '/'): void
    {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        $this->response->redirect($ref ?: url($fallback));
        exit;
    }

    /** flash + redirect ترکیبی */
    protected function redirectWithError(string $message, string $to = ''): void
    {
        $this->session->setFlash('error', $message);
        $to ? $this->response->redirect(url($to)) : $this->back();
        exit;
    }

    protected function redirectWithSuccess(string $message, string $to = ''): void
    {
        $this->session->setFlash('success', $message);
        $to ? $this->response->redirect(url($to)) : $this->back();
        exit;
    }

    /** render view با داده */
    protected function view(string $template, array $data = []): void
    {
        view($template, $data);
    }
}
