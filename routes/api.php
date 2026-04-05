<?php

/**
 * مسیرهای REST API v1
 */

use App\Controllers\Api\TokenController;
use App\Controllers\Api\UserController   as ApiUserController;
use App\Controllers\Api\WalletController as ApiWalletController;
use App\Middleware\ApiAuthMiddleware;

$r = app()->router;

// ── احراز هویت بدون token ─────────────────────────────────────────────────
$r->post('/api/v1/auth/token', [TokenController::class, 'issue']);

// ── احراز هویت با token ───────────────────────────────────────────────────
$r->post('/api/v1/auth/revoke',             [TokenController::class, 'revoke'],     [ApiAuthMiddleware::class]);
$r->get('/api/v1/auth/tokens',              [TokenController::class, 'list'],       [ApiAuthMiddleware::class]);
$r->post('/api/v1/auth/tokens/{id}/revoke', [TokenController::class, 'revokeById'],[ApiAuthMiddleware::class]);

// ── کاربر ─────────────────────────────────────────────────────────────────
$r->get('/api/v1/user/profile',               [ApiUserController::class, 'profile'],       [ApiAuthMiddleware::class]);
$r->get('/api/v1/user/notifications',         [ApiUserController::class, 'notifications'], [ApiAuthMiddleware::class]);
$r->post('/api/v1/user/notifications/read',   [ApiUserController::class, 'markRead'],      [ApiAuthMiddleware::class]);

// ── کیف پول ───────────────────────────────────────────────────────────────
$r->get('/api/v1/wallet',              [ApiWalletController::class, 'balance'],      [ApiAuthMiddleware::class]);
$r->get('/api/v1/wallet/transactions', [ApiWalletController::class, 'transactions'], [ApiAuthMiddleware::class]);
