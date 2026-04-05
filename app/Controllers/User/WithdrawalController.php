<?php

namespace App\Controllers\User;

use App\Models\Withdrawal;
use App\Models\UserBankCard;
use App\Models\User;
use App\Services\WalletService;
use Core\Validator;
use App\Services\ApiRateLimiter;
use App\Controllers\User\BaseUserController;

class WithdrawalController extends BaseUserController
{
    private \App\Services\WithdrawalLimitService $withdrawalLimitService;
    private Withdrawal $withdrawalModel;
    private UserBankCard $cardModel;
    private WalletService $walletService;

    public function __construct(
        \App\Models\Withdrawal $withdrawalModel,
        \App\Models\UserBankCard $cardModel,
        \App\Services\WithdrawalLimitService $withdrawalLimitService,
        \App\Services\WalletService $walletService)
    {
        parent::__construct();
        $this->withdrawalModel = $withdrawalModel;
        $this->cardModel = $cardModel;
        $this->walletService = $walletService;
        $this->withdrawalLimitService = $withdrawalLimitService;
    }

    /**
     * فرم برداشت وجه
     */
    public function create(): void
    {
        $userId = $this->userId();

        try {
            // بررسی KYC
            $user = $this->userModel->find($userId);

            if (!$user || $user->kyc_status !== 'verified') {
                $this->session->setFlash('error', 'برای برداشت وجه ابتدا باید احراز هویت کنید');
                redirect('/kyc');
                return;
            }

            // بررسی درخواست در انتظار
            if ($this->withdrawalModel->hasPendingWithdrawal($userId)) {
                $this->session->setFlash('error', 'شما یک درخواست برداشت در انتظار دارید');
                redirect('/wallet');
                return;
            }

            // بررسی محدودیت روزانه
            $summary = $this->walletService->getWalletSummary($userId);
            if (!$summary->can_withdraw_today) {
                $this->session->setFlash('error', 'شما امروز یکبار برداشت کرده‌اید');
                redirect('/wallet');
                return;
            }

            $siteCurrency = config('site_currency', 'irt');
            
            // دریافت کارت‌ها برای IRT
            $cards = [];
            if ($siteCurrency === 'irt') {
                $cards = $this->cardModel->getUserCards($userId, 'verified');
                if (empty($cards)) {
                    $this->session->setFlash('error', 'ابتدا باید کارت بانکی خود را ثبت و تأیید کنید');
                    redirect('/bank-cards/create');
                    return;
                }
            }

            $minWithdrawal = $siteCurrency === 'usdt'
                ? (float)config('min_withdrawal_usdt', 10)
                : (float)config('min_withdrawal_irt', 50000);

            view('user.withdrawal.create', [
                'summary' => $summary,
                'cards' => $cards,
                'siteCurrency' => $siteCurrency,
                'minWithdrawal' => $minWithdrawal,
                'pageTitle' => 'برداشت وجه'
            ]);

        } catch (\Exception $e) {
            logger()->error('Withdrawal create failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            $this->session->setFlash('error', 'خطا در بارگذاری صفحه');
            redirect('/wallet');
        }
    }

    /**
     * ثبت درخواست برداشت - با Idempotency Protection
     */
    public function store(): void
    {
        $userId = $this->userId();

        // ✅ Rate limiting: حداکثر 3 برداشت در ساعت
        ApiRateLimiter::enforce('withdrawal', $userId, is_ajax());

        // ✅ دریافت Request Metadata برای audit trail
        $requestId = get_request_id();
        $ipAddress = get_client_ip();
        $deviceFingerprint = generate_device_fingerprint();

        // بررسی KYC
        $user = $this->userModel->find($userId);

        if (!$user || $user->kyc_status !== 'verified') {
            $this->session->setFlash('error', 'احراز هویت انجام نشده است');
            redirect('/kyc');
            return;
        }

        // بررسی درخواست در انتظار
        if ($this->withdrawalModel->hasPendingWithdrawal($userId)) {
            $this->session->setFlash('error', 'شما یک درخواست برداشت در انتظار دارید');
            redirect('/wallet');
            return;
        }

        $siteCurrency = config('site_currency', 'irt');
        
        // دریافت داده‌ها
        $data = [
            'currency' => $siteCurrency,
            'amount' => $this->request->input('amount'),
        ];

        // داده‌های اضافی بر اساس نوع ارز
        if ($siteCurrency === 'irt') {
            $data['card_id'] = $this->request->input('card_id');
        } else {
            $data['wallet_address'] = $this->request->input('wallet_address');
            $data['network'] = $this->request->input('network');
        }

        // ✅ دریافت idempotency_key از فرم (اگر ارسال شده باشد)
        $idempotencyKey = $this->request->input('idempotency_key');

        // اعتبارسنجی
        $rules = [
            'amount' => 'required|numeric|min:1',
        ];

        $messages = [
            'amount.required' => 'مبلغ الزامی است',
            'amount.numeric' => 'مبلغ باید عددی باشد',
        ];

        if ($siteCurrency === 'irt') {
            $rules['card_id'] = 'required|numeric';
            $messages['card_id.required'] = 'انتخاب کارت الزامی است';
        } else {
            $rules['wallet_address'] = 'required|min:26|max:42';
            $rules['network'] = 'required|in:bnb20,trc20';
            $messages['wallet_address.required'] = 'آدرس کیف پول الزامی است';
            $messages['network.required'] = 'انتخاب شبکه الزامی است';
        }

        $validator = new Validator($data, $rules, $messages);

        if ($validator->fails()) {
            $this->session->setFlash('error', $validator->errors()[0]);
            $this->session->setFlash('old', $data);
            redirect('/withdrawal/create');
            return;
        }

        try {
            $amount = (float)$data['amount'];

            // بررسی امکان برداشت
            $canWithdraw = $this->walletService->canWithdraw($userId, $amount, $siteCurrency);
            if (!$canWithdraw['can_withdraw']) {
                throw new \RuntimeException($canWithdraw['message']);
            }

            // بررسی کارت (برای IRT)
            if ($siteCurrency === 'irt') {
                $card = $this->cardModel->find((int)$data['card_id']);
                if (!$card || $card->user_id !== $userId || $card->status !== 'verified') {
                    throw new \RuntimeException('کارت نامعتبر است');
                }
            }

            // ✅ CRITICAL: قفل موجودی و ثبت تراکنش با metadata کامل
            $transaction = $this->walletService->withdraw($userId, $amount, $siteCurrency, [
                'description' => 'درخواست برداشت',
                // ✅ اطلاعات امنیتی
                'request_id' => $requestId,
                'ip_address' => $ipAddress,
                'device_fingerprint' => $deviceFingerprint,
                'idempotency_key' => $idempotencyKey,
                // اطلاعات اضافی
                'card_id' => $data['card_id'] ?? null,
                'wallet_address' => $data['wallet_address'] ?? null,
                'network' => $data['network'] ?? null,
            ]);

            if (!$transaction || !$transaction['success']) {
                throw new \RuntimeException($transaction['message'] ?? 'خطا در قفل کردن موجودی');
            }

            // ثبت درخواست برداشت
            $data['user_id'] = $userId;
            $data['status'] = 'pending';
            $data['transaction_id'] = $transaction['transaction_id'];
            // ✅ ذخیره request_id در withdrawal
            $data['request_id'] = $requestId;

            $withdrawal = $this->withdrawalModel->create($data);

            if (!$withdrawal) {
                // بازگشت موجودی در صورت خطا
                $this->walletService->cancelWithdrawal(
                    $userId, 
                    $amount, 
                    $siteCurrency, 
                    $transaction['transaction_id']
                );
                throw new \RuntimeException('خطا در ثبت درخواست');
            }

            // ✅ ثبت لاگ با request_id
            log_activity(
                $userId,
                'withdrawal_requested',
                "درخواست برداشت {$amount} " . ($siteCurrency === 'usdt' ? 'USDT' : 'تومان'),
                [
                    'withdrawal_id' => $withdrawal->id,
                    'transaction_id' => $transaction['transaction_id'],
                    'request_id' => $requestId,
                    'ip' => $ipAddress,
                    'device' => $deviceFingerprint
                ]
            );

            // ✅ لاگ موفقیت
            error_log("✅ [WTH_{$requestId}] Withdrawal request created: User#{$userId}, Amount: {$amount} {$siteCurrency}, TXN: {$transaction['transaction_id']}");

            $this->session->setFlash('success', 'درخواست برداشت شما با موفقیت ثبت شد');
            redirect('/wallet');

        } catch (\Exception $e) {
            // ✅ لاگ خطا با جزئیات کامل
            logger()->error('Withdrawal store failed', [
                'request_id' => $requestId,
                'user_id' => $userId,
                'amount' => $amount ?? 0,
                'currency' => $siteCurrency,
                'ip' => $ipAddress,
                'device' => $deviceFingerprint,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            error_log("❌ [WTH_{$requestId}] Withdrawal failed: User#{$userId}, Error: {$e->getMessage()}");

            $this->session->setFlash('error', $e->getMessage());
            $this->session->setFlash('old', $data);
            redirect('/withdrawal/create');
        }
    }

    /**
     * لیست درخواست‌های برداشت کاربر
     */
    public function index(): void
    {
        $userId = $this->userId();

        try {
            $withdrawals = $this->withdrawalModel->getUserWithdrawals($userId);

            view('user.withdrawal.index', [
                'withdrawals' => $withdrawals,
                'pageTitle' => 'درخواست‌های برداشت'
            ]);

        } catch (\Exception $e) {
            logger()->error('Withdrawal index failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            $this->session->setFlash('error', 'خطا در دریافت لیست');
            redirect('/wallet');
        }
    }

    /**
     * نمایش محدودیت‌های برداشت برای کاربر جاری (JSON)
     * GET /user/withdrawal/limits?currency=IRT
     */
    public function limitsInfo(): void
    {
        $userId   = (int)user_id();
        $currency = strtoupper(($this->request)->get('currency') ?? 'IRT');
        if (!in_array($currency, ['IRT', 'USDT'], true)) {
            $currency = 'IRT';
        }

        $limitService = $this->withdrawalLimitService;
        $info = $limitService->getLimitsForUser($userId, $currency);

        $this->response->json([
            'success' => true,
            'limits'  => $info,
        ]);
    }
}