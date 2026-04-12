<?php

namespace App\Controllers\Api;

use App\Models\Wallet;
use App\Models\Transaction;
use App\Services\WalletService;
use App\Services\ApiRateLimiter;

/**
 * API\WalletController - کیف‌پول
 *
 * GET  /api/v1/wallet              → موجودی
 * GET  /api/v1/wallet/transactions → تاریخچه تراکنش‌ها
 */
class WalletController extends BaseApiController
{
    private Wallet $walletModel;
    private Transaction $txModel;

    public function __construct(
        \App\Models\Wallet $walletModel,
        \App\Models\Transaction $txModel
    )
    {
        parent::__construct();
        $this->walletModel = $walletModel;
        $this->txModel = $txModel;
    }

    /** موجودی کیف‌پول */
    public function balance(): never
    {
        $userId = $this->userId();
        $wallet = $this->walletModel->findByUserId($userId);

        if (!$wallet) {
            $this->error('کیف‌پول یافت نشد', 404);
        }

        $this->success([
            'irt_balance'        => (float)($wallet->irt_balance ?? 0),
            'irt_locked'         => (float)($wallet->irt_locked ?? 0),
            'irt_available'      => max(0, (float)($wallet->irt_balance ?? 0) - (float)($wallet->irt_locked ?? 0)),
            'usdt_balance'       => (float)($wallet->usdt_balance ?? 0),
            'usdt_locked'        => (float)($wallet->usdt_locked ?? 0),
            'usdt_available'     => max(0, (float)($wallet->usdt_balance ?? 0) - (float)($wallet->usdt_locked ?? 0)),
            'last_withdrawal_at' => $wallet->last_withdrawal_at ?? null,
        ]);
    }

    /** تاریخچه تراکنش‌ها */
    public function transactions(): never
    {
        $userId            = $this->userId();
        [$page, $perPage, $offset] = $this->paginationParams(20);

        $filters = [];
        if ($type = $this->request->get('type')) {
            $filters['type'] = $type;
        }
        if ($status = $this->request->get('status')) {
            $filters['status'] = $status;
        }
        if ($currency = $this->request->get('currency')) {
            $filters['currency'] = $currency;
        }

        $items = $this->txModel->getUserTransactions($userId, $perPage, $offset, $filters);
        $total = $this->txModel->countUserTransactions($userId, $filters);

        // پاکسازی داده‌های حساس
        $items = array_map(fn($tx) => [
            'id'          => $tx->id,
            'type'        => $tx->type,
            'amount'      => (float)$tx->amount,
            'currency'    => $tx->currency,
            'status'      => $tx->status,
            'description' => $tx->description,
            'created_at'  => $tx->created_at,
        ], $items);

        $this->paginated($items, $total, $page, $perPage);
    }
}
