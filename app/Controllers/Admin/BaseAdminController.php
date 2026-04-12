<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/**
 * BaseAdminController — پایه تمام کنترلرهای پنل مدیریت
 *
 * ─── جریان صحیح ────────────────────────────────────────────────
 *
 *   Container::make(AdminUserController)
 *       └─→ AdminUserController::__construct()     ← بدون پارامتر
 *               └─→ BaseAdminController::__construct()
 *                       └─→ BaseController::__construct()
 *                               └─→ Container: Request, Response, Session
 *                       └─→ requireAuth() + requireAdmin()
 *
 * ─── تذکر ──────────────────────────────────────────────────────
 *   Auth در دو سطح بررسی می‌شود:
 *     ۱. AdminMiddleware  (در Route) — قبل از رسیدن به Controller
 *     ۲. requireAuth/requireAdmin  (اینجا) — لایه دوم اطمینان
 */
abstract class BaseAdminController extends BaseController
{
    /**
     * هیچ پارامتری نمی‌گیرد — Container همه چیز را inject می‌کند.
     */
    public function __construct()
    {
        parent::__construct();
        $this->requireAuth();
        $this->requireAdmin();
    }
}
