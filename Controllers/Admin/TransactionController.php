<?php

namespace App\Controllers\Admin;
use App\Models\User;

use App\Models\Transaction;
use App\Controllers\Admin\BaseAdminController;

class TransactionController extends BaseAdminController
{
    private User $userModel;
    private Transaction $transactionModel;

    public function __construct(User $userModel,
        \App\Models\Transaction $transactionModel)
    {
        parent::__construct();
        $this->userModel = $userModel;
        $this->transactionModel = $transactionModel;
    }

    /**
     * لیست تمام تراکنش‌ها
     */
    public function index(): void
{
    
    $page = (int) $this->request->get('page', 1);
    if ($page < 1) $page = 1;

    $status = $this->request->get('status');
    $type = $this->request->get('type');
    $currency = $this->request->get('currency');

    $limit = 50;
    $offset = ($page - 1) * $limit;

    try {
        $transactions = $this->transactionModel->getAll($status, $type, $currency, $limit, $offset);
        $total = $this->transactionModel->countAll($status, $type, $currency);
        $totalPages = (int) \ceil($total / $limit);

        echo view('admin.transactions.index', [
            'transactions' => $transactions ?? [],
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
            'status' => $status,
            'type' => $type,
            'currency' => $currency,
            'pageTitle' => 'تراکنش‌های مالی',
        ]);
        return;

    } catch (\Throwable $e) {
        // logger شما apparently object برمی‌گرداند
        try {
            logger()->error('Admin transactions index failed: ' . $e->getMessage());
        } catch (\Throwable $ignore) {}

        $this->session->setFlash('error', 'خطا در دریافت لیست تراکنش‌ها');

        // ✅ به جای redirect به داشبورد، همان صفحه را با لیست خالی نشان بده
        echo view('admin.transactions.index', [
            'transactions' => [],
            'currentPage' => 1,
            'totalPages' => 1,
            'total' => 0,
            'status' => $status,
            'type' => $type,
            'currency' => $currency,
            'pageTitle' => 'تراکنش‌های مالی',
        ]);
        return;
    }
}

    /**
     * نمایش جزئیات تراکنش
     */
    public function show(): void
    {
                $transactionId = (int)$this->request->get('id');

        try {
            $transaction = $this->transactionModel->find($transactionId);

            if (!$transaction) {
                $this->session->setFlash('error', 'تراکنش یافت نشد');
                redirect('/admin/transactions');
                return;
            }

            // دریافت اطلاعات کاربر
            $userModel = $this->userModel;
            $user = $userModel->find($transaction->user_id);

            // تبدیل metadata از JSON
            $metadata = null;
            if ($transaction->metadata) {
                $metadata = \json_decode($transaction->metadata, true);
            }

            view('admin.transactions.show', [
                'transaction' => $transaction,
                'user' => $user,
                'metadata' => $metadata,
                'pageTitle' => 'جزئیات تراکنش'
            ]);

        } catch (\Exception $e) {
            logger()->error('Admin transaction show failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage()
            ]);

            $this->session->setFlash('error', 'خطا در دریافت اطلاعات');
            redirect('/admin/transactions');
        }
    }
}