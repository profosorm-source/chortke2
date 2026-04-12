<?php

namespace App\Controllers\Admin;

use App\Models\Withdrawal;
use App\Services\WalletService;
use App\Services\UserService;
use App\Services\BankCardService;
use Core\Validator;
use App\Controllers\Admin\BaseAdminController;

class WithdrawalController extends BaseAdminController
{
    private \App\Services\BankCardService $bankCardService;
    private Withdrawal $withdrawalModel;
    private WalletService  $walletService;
    private UserService    $userService;
    private BankCardService $cardService;

    public function __construct(
        \App\Models\Withdrawal $withdrawalModel,
        \App\Services\BankCardService $bankCardService,
        \App\Services\WalletService $walletService,
        \App\Services\UserService $userService)
    {
        parent::__construct();
        $this->withdrawalModel = $withdrawalModel;
        $this->walletService = $walletService;
        $this->userService = $userService;
        $this->cardService = $bankCardService;
        $this->bankCardService = $bankCardService;
    }

    /**
     * لیست درخواست‌های برداشت
     */
    public function index(): void
    {
                
        $page = (int)$this->request->get('page', 1);
        $status = $this->request->get('status');
        $currency = $this->request->get('currency');
        $limit = 20;
        $offset = ($page - 1) * $limit;

        try {
            if ($status || $currency) {
                $withdrawals = $this->withdrawalModel->getAll($status, $currency, $limit, $offset);
                $total = $this->withdrawalModel->countAll($status, $currency);
            } else {
                $withdrawals = $this->withdrawalModel->getPendingWithdrawals($limit, $offset);
                $total = $this->withdrawalModel->countPendingWithdrawals();
            }

            $totalPages = (int)\ceil($total / $limit);

            // آمار خلاصه
            $summary = $this->withdrawalModel->getSummaryStats();

            view('admin.withdrawals.index', [
                'withdrawals' => $withdrawals,
                'currentPage' => $page,
                'totalPages'  => $totalPages,
                'total'       => $total,
                'status'      => $status,
                'currency'    => $currency,
                'summary'     => $summary ?? [],
                'pageTitle'   => 'درخواست‌های برداشت'
            ]);

        } catch (\Exception $e) {
            logger()->error('Admin withdrawals index failed', [
                'error' => $e->getMessage()
            ]);

            $this->session->setFlash('error', 'خطا در دریافت لیست');
            redirect('/admin/dashboard');
        }
    }

    /**
     * صفحه بررسی درخواست برداشت
     */
    public function review(): void
    {
                $withdrawalId = (int)$this->request->get('id');

        try {
            $withdrawal = $this->withdrawalModel->find($withdrawalId);

            if (!$withdrawal) {
                $this->session->setFlash('error', 'درخواست یافت نشد');
                redirect('/admin/withdrawals');
                return;
            }

            // دریافت اطلاعات کاربر
            $user = $this->userService->findById($withdrawal->user_id);

            // دریافت اطلاعات کارت (برای IRT)
            $card = null;
            if ($withdrawal->card_id) {
                $card = $this->cardService->findById($withdrawal->card_id);
            }

            view('admin.withdrawals.review', [
                'withdrawal' => $withdrawal,
                'user' => $user,
                'card' => $card,
                'pageTitle' => 'بررسی درخواست برداشت'
            ]);

        } catch (\Exception $e) {
            logger()->error('Admin withdrawal review failed', [
                'withdrawal_id' => $withdrawalId,
                'error' => $e->getMessage()
            ]);

            $this->session->setFlash('error', 'خطا در دریافت اطلاعات');
            redirect('/admin/withdrawals');
        }
    }

    /**
 * تأیید واریز کریپتو
 * 
 * فایل: app/Controllers/Admin/CryptoDepositsController.php
 * خط: ~170
 */
public function verify(): void
{
    $adminId = $this->userId();
    $depositId = (int)$this->request->input('deposit_id');

    try {
        $deposit = $this->depositModel->find($depositId);

        if (!$deposit) {
            $this->response->json([
                'success' => false,
                'message' => 'واریز یافت نشد'
            ]);
            return;
        }

        if ($deposit->verification_status === 'verified') {
            $this->response->json([
                'success' => false,
                'message' => 'این واریز قبلاً تأیید شده است'
            ]);
            return;
        }

        // ✅ اصلاح شد: افزایش موجودی کاربر
        // deposit(userId, amount, currency, metadata)
        $result = $this->walletService->deposit(
            (int) $deposit->user_id,         // 1. userId
            (float) $deposit->amount,        // 2. amount
            'usdt',                          // 3. currency
            [                                // 4. metadata
                'type'                  => 'crypto_deposit',
                'gateway'               => 'usdt_' . $deposit->network,
                'gateway_transaction_id' => $deposit->tx_hash,
                'description'           => 'واریز USDT - ' . strtoupper($deposit->network),
                'network'               => $deposit->network,
                'tx_hash'               => $deposit->tx_hash,
                'approved_by'           => $adminId
            ]
        );

        // ✅ چک صحیح
        if (!$result['success']) {
            throw new \RuntimeException($result['message'] ?? 'خطا در افزایش موجودی');
        }

        // بروزرسانی وضعیت واریز
        $updated = $this->depositModel->updateStatus(
            $depositId,
            'verified',
            null,
            null,
            $adminId,
            $result['transaction_id']  // ✅ ذخیره transaction_id
        );

        if ($updated) {
            // ثبت لاگ
            log_activity(
                $adminId,
                'crypto_deposit_verified',
                "تأیید واریز {$deposit->amount} USDT ({$deposit->network}) برای کاربر {$deposit->user_id}",
                ['deposit_id' => $depositId, 'transaction_id' => $result['transaction_id']]
            );

            $this->response->json([
                'success' => true,
                'message' => 'واریز با موفقیت تأیید شد'
            ]);
        } else {
            throw new \RuntimeException('خطا در بروزرسانی وضعیت');
        }

    } catch (\Exception $e) {
        logger()->error('Admin verify crypto deposit failed', [
            'admin_id'   => $adminId,
            'deposit_id' => $depositId,
            'error'      => $e->getMessage()
        ]);

        $this->response->json([
            'success' => false,
            'message' => 'خطا در تأیید واریز: ' . $e->getMessage()
        ]);
    }
}

    /**
     * تأیید و پرداخت درخواست برداشت
     */
    public function approve(): void
    {
        $adminId = $this->userId();
        
        // ✅ دریافت metadata
        $requestId = get_request_id();
        $ipAddress = get_client_ip();

        $data = [
            'withdrawal_id' => $this->request->input('withdrawal_id'),
            'payment_reference' => $this->request->input('payment_reference'), // شماره پیگیری پرداخت
        ];

        // اعتبارسنجی
        $validator = new Validator($data, [
            'withdrawal_id' => 'required|numeric',
            'payment_reference' => 'required|min:5|max:100',
        ], [
            'withdrawal_id.required' => 'شناسه برداشت الزامی است',
            'payment_reference.required' => 'شماره پیگیری پرداخت الزامی است',
            'payment_reference.min' => 'شماره پیگیری باید حداقل 5 کاراکتر باشد',
        ]);

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'message' => $validator->errors()[0]
            ]);
            return;
        }

        try {
            $withdrawalId = (int)$data['withdrawal_id'];
            $withdrawal = $this->withdrawalModel->find($withdrawalId);

            if (!$withdrawal) {
                $this->response->json([
                    'success' => false,
                    'message' => 'درخواست یافت نشد'
                ]);
                return;
            }

            if ($withdrawal->status !== 'pending') {
                $this->response->json([
                    'success' => false,
                    'message' => 'این درخواست قبلاً پردازش شده است'
                ]);
                return;
            }

            // ✅ CRITICAL: تکمیل برداشت (به‌روزرسانی status تراکنش)
            $completed = $this->walletService->completeWithdrawal(
                $withdrawal->user_id,
                (float)$withdrawal->amount,
                $withdrawal->currency,
                $withdrawal->transaction_id
            );

            if (!$completed) {
                throw new \RuntimeException('خطا در تکمیل برداشت');
            }

            // ✅ به‌روزرسانی وضعیت withdrawal
            $updated = $this->withdrawalModel->updateStatus(
                $withdrawalId,
                'completed',
                $data['payment_reference'],
                $adminId
            );

            if ($updated) {
                // ✅ ثبت تغییر وضعیت در transaction_events
                $this->withdrawalService->recordTransactionStatusChange(
                    $withdrawal->transaction_id,
                    'completed',
                    "تایید و پرداخت توسط ادمین | مرجع: {$data['payment_reference']}",
                    $adminId,
                    [
                        'payment_reference' => $data['payment_reference'],
                        'withdrawal_id' => $withdrawalId,
                        'request_id' => $requestId
                    ]
                );

                // ✅ ثبت لاگ
                log_activity(
                    $adminId,
                    'withdrawal_approved',
                    "تأیید برداشت {$withdrawal->amount} " . ($withdrawal->currency === 'usdt' ? 'USDT' : 'تومان') . " برای کاربر {$withdrawal->user_id}",
                    [
                        'withdrawal_id' => $withdrawalId,
                        'transaction_id' => $withdrawal->transaction_id,
                        'payment_reference' => $data['payment_reference'],
                        'request_id' => $requestId,
                        'admin_ip' => $ipAddress
                    ]
                );

                error_log("✅ [WTH_APPROVE_{$requestId}] Withdrawal approved: ID#{$withdrawalId}, User#{$withdrawal->user_id}, Admin#{$adminId}");

                $this->response->json([
                    'success' => true,
                    'message' => 'برداشت با موفقیت تأیید و پرداخت شد'
                ]);
            } else {
                throw new \RuntimeException('خطا در تایید برداشت');
            }

        } catch (\Exception $e) {
            logger()->error('Admin approve withdrawal failed', [
                'request_id' => $requestId,
                'admin_id' => $adminId,
                'withdrawal_id' => $withdrawalId ?? 0,
                'ip' => $ipAddress,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            error_log("❌ [WTH_APPROVE_{$requestId}] Failed: {$e->getMessage()}");

            $this->response->json([
                'success' => false,
                'message' => 'خطا در تایید برداشت: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * رد درخواست برداشت - با Event Sourcing
     */
    public function reject(): void
    {
        $adminId = $this->userId();
        
        // ✅ دریافت metadata
        $requestId = get_request_id();
        $ipAddress = get_client_ip();

        $data = [
            'withdrawal_id' => $this->request->input('withdrawal_id'),
            'rejection_reason' => $this->request->input('rejection_reason'),
        ];

        // اعتبارسنجی
        $validator = new Validator($data, [
            'withdrawal_id' => 'required|numeric',
            'rejection_reason' => 'required|min:10|max:500',
        ], [
            'rejection_reason.required' => 'دلیل رد الزامی است',
            'rejection_reason.min' => 'دلیل رد باید حداقل 10 کاراکتر باشد',
        ]);

        if ($validator->fails()) {
            $this->response->json([
                'success' => false,
                'message' => $validator->errors()[0]
            ]);
            return;
        }

        try {
            $withdrawalId = (int)$data['withdrawal_id'];
            $withdrawal = $this->withdrawalModel->find($withdrawalId);

            if (!$withdrawal) {
                $this->response->json([
                    'success' => false,
                    'message' => 'درخواست یافت نشد'
                ]);
                return;
            }

            if ($withdrawal->status !== 'pending') {
                $this->response->json([
                    'success' => false,
                    'message' => 'این درخواست قبلاً پردازش شده است'
                ]);
                return;
            }

            // ✅ لغو برداشت (آزادسازی موجودی قفل‌شده)
            $cancelled = $this->walletService->cancelWithdrawal(
                $withdrawal->user_id,
                (float)$withdrawal->amount,
                $withdrawal->currency,
                $withdrawal->transaction_id
            );

            if (!$cancelled) {
                throw new \RuntimeException('خطا در آزادسازی موجودی');
            }

            // ✅ بروزرسانی وضعیت
            $updated = $this->withdrawalModel->updateStatus(
                $withdrawalId,
                'rejected',
                $data['rejection_reason'],
                $adminId
            );

            if ($updated) {
                // ✅ ثبت تغییر وضعیت در transaction_events
                $this->withdrawalService->recordTransactionStatusChange(
                    $withdrawal->transaction_id,
                    'cancelled',
                    "رد توسط ادمین: {$data['rejection_reason']}",
                    $adminId,
                    [
                        'rejection_reason' => $data['rejection_reason'],
                        'withdrawal_id' => $withdrawalId,
                        'request_id' => $requestId
                    ]
                );

                // ✅ ثبت لاگ
                log_activity(
                    $adminId,
                    'withdrawal_rejected',
                    "رد برداشت کاربر {$withdrawal->user_id}",
                    [
                        'withdrawal_id' => $withdrawalId,
                        'transaction_id' => $withdrawal->transaction_id,
                        'reason' => $data['rejection_reason'],
                        'request_id' => $requestId,
                        'admin_ip' => $ipAddress
                    ]
                );

                error_log("✅ [WTH_REJECT_{$requestId}] Withdrawal rejected: ID#{$withdrawalId}, User#{$withdrawal->user_id}, Reason: {$data['rejection_reason']}");

                $this->response->json([
                    'success' => true,
                    'message' => 'برداشت رد شد و موجودی به کاربر بازگردانده شد'
                ]);
            } else {
                throw new \RuntimeException('خطا در رد برداشت');
            }

        } catch (\Exception $e) {
            logger()->error('Admin reject withdrawal failed', [
                'request_id' => $requestId,
                'admin_id' => $adminId,
                'withdrawal_id' => $withdrawalId ?? 0,
                'ip' => $ipAddress,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            error_log("❌ [WTH_REJECT_{$requestId}] Failed: {$e->getMessage()}");

            $this->response->json([
                'success' => false,
                'message' => 'خطا در رد برداشت: ' . $e->getMessage()
            ]);
        }
    }
}