<?php

namespace App\Controllers\User;
use App\Models\Transaction;

use App\Services\WalletService;
use App\Controllers\User\BaseUserController;

class WalletController extends BaseUserController
{
    private Transaction $transactionModel;
    private WalletService $walletService;

    public function __construct(
        Transaction $transactionModel,
        \App\Services\WalletService $walletService)
    {
        parent::__construct();
        $this->transactionModel = $transactionModel;
        $this->walletService = $walletService;
    }

    /**
     * صفحه اصلی کیف پول (نمایش موجودی)
     */
    public function index(): void
    {
        $userId = $this->userId();
        
        try {
            $summary = $this->walletService->getWalletSummary($userId);
            $siteCurrency = config('site_currency', 'irt');
            
            view('user.wallet.index', [
                'summary' => $summary,
                'siteCurrency' => $siteCurrency,
                'pageTitle' => 'کیف پول من'
            ]);

        } catch (\Exception $e) {
            logger()->error('Wallet index failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            $this->session->setFlash('error', 'خطا در دریافت اطلاعات کیف پول');
            redirect('/dashboard');
        }
    }

    /**
     * صفحه انتخاب روش افزایش موجودی
     */
    public function depositIndex(): void
    {
        $siteCurrency = config('site_currency', 'irt');
        
        view('user.wallet.deposit-select', [
            'siteCurrency' => $siteCurrency,
            'pageTitle' => 'افزایش موجودی'
        ]);
    }

    /**
     * تاریخچه تراکنش‌ها
     */
    public function history(): void
    {
                $userId = $this->userId();
        
        $page = (int)$this->request->get('page', 1);
        $type = $this->request->get('type');
        $currency = $this->request->get('currency');
        $limit = 20;
        $offset = ($page - 1) * $limit;

        try {
            $transactionModel = $this->transactionModel;
            
            $transactions = $transactionModel->getUserTransactions(
                $userId,
                $type,
                $currency,
                $limit,
                $offset
            );
            
            $total = $transactionModel->countUserTransactions($userId, $type, $currency);
            $totalPages = (int)\ceil($total / $limit);

            view('user.wallet.history', [
                'transactions' => $transactions,
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'type' => $type,
                'currency' => $currency,
                'pageTitle' => 'تاریخچه تراکنش‌ها'
            ]);

        } catch (\Exception $e) {
            logger()->error('Transaction history failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            $this->session->setFlash('error', 'خطا در دریافت تاریخچه');
            redirect('/wallet');
        }
    }
}