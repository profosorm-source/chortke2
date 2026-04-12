<?php

namespace App\Controllers\Admin;
use App\Models\User;

use App\Models\CryptoDeposit;
use App\Services\WalletService;
use Core\Validator;
use App\Controllers\Admin\BaseAdminController;

class CryptoDepositController extends BaseAdminController
{
    private User $userModel;
    private CryptoDeposit $depositModel;
    private WalletService $walletService;

    public function __construct(
        User $userModel,
        \App\Models\CryptoDeposit $depositModel,
        \App\Services\WalletService $walletService)
    {
        parent::__construct();
        $this->userModel = $userModel;
        $this->depositModel = $depositModel;
        $this->walletService = $walletService;
    }

    /**
     * لیست واریزهای کریپتو نیازمند بررسی دستی
     */
    public function index(): void
    {
                
        $page = (int)$this->request->get('page', 1);
        $status = $this->request->get('status');
        $network = $this->request->get('network');
        $limit = 20;
        $offset = ($page - 1) * $limit;

        try {
            if ($status || $network) {
                $deposits = $this->depositModel->getAll($status, $network, $limit, $offset);
                $total = $this->depositModel->countAll($status, $network);
            } else {
                $deposits = $this->depositModel->getManualReviewDeposits($limit, $offset);
                $total = $this->depositModel->countManualReview();
            }

            $totalPages = (int)\ceil($total / $limit);

            view('admin.crypto-deposits.index', [
                'deposits' => $deposits,
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'total' => $total,
                'status' => $status,
                'network' => $network,
                'pageTitle' => 'واریزهای کریپتو'
            ]);

        } catch (\Exception $e) {
            logger()->error('Admin crypto deposits index failed', [
                'error' => $e->getMessage()
            ]);

            $this->session->setFlash('error', 'خطا در دریافت لیست');

view('admin.crypto-deposits.index', [
    'deposits' => [],
    'currentPage' => 1,
    'totalPages' => 1,
    'total' => 0,
    'status' => $status,
    'network' => $network,
    'pageTitle' => 'واریزهای کریپتو'
]);
return;
        }
    }

    /**
     * صفحه بررسی واریز کریپتو
     */
    public function review(): void
    {
                $depositId = (int)$this->request->get('id');

        try {
            $deposit = $this->depositModel->find($depositId);

            if (!$deposit) {
                $this->session->setFlash('error', 'واریز یافت نشد');
                redirect('/admin/crypto-deposits');
                return;
            }

            // دریافت اطلاعات کاربر
            $userModel = $this->userModel;
            $user = $userModel->find($deposit->user_id);

            // لینک explorer
            $explorerUrl = $deposit->network === 'trc20'
                ? "https://tronscan.org/#/transaction/{$deposit->tx_hash}"
                : "https://bscscan.com/tx/{$deposit->tx_hash}";

            view('admin.crypto-deposits.review', [
                'deposit' => $deposit,
                'user' => $user,
                'explorerUrl' => $explorerUrl,
                'pageTitle' => 'بررسی واریز کریپتو'
            ]);

        } catch (\Exception $e) {
            logger()->error('Admin crypto deposit review failed', [
                'deposit_id' => $depositId,
                'error' => $e->getMessage()
            ]);

            $this->session->setFlash('error', 'خطا در دریافت اطلاعات');
            redirect('/admin/crypto-deposits');
        }
    }

    /**
     * تأیید واریز کریپتو
     */
    public function verify(): void
{
    $adminId = $this->userId();
    $depositId = (int) $this->request->input('deposit_id');

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

        // ✅ افزایش موجودی کاربر (WalletService جدید: خروجی array)
        $depositResult = $this->walletService->deposit(
            (int) $deposit->user_id,
            (float) $deposit->amount,
            'usdt',
            [
                'type'                   => 'crypto_deposit',
                'gateway'                => 'usdt_' . $deposit->network,
                'gateway_transaction_id' => $deposit->tx_hash,
                'description'            => 'واریز USDT - ' . strtoupper((string)$deposit->network),

                // اطلاعات تکمیلی برای ردگیری
                'network'     => $deposit->network,
                'tx_hash'     => $deposit->tx_hash,
                'deposit_id'  => $depositId,
                'approved_by' => $adminId,
                'ref_id'      => $depositId,
                'ref_type'    => 'crypto_deposit',
            ]
        );

        if (empty($depositResult['success'])) {
            throw new \RuntimeException($depositResult['message'] ?? 'خطا در افزایش موجودی');
        }

        $transactionId = $depositResult['transaction_id'] ?? null;

        // ✅ بروزرسانی وضعیت واریز (transaction_id جدید)
        $updated = $this->depositModel->updateStatus(
            $depositId,
            'verified',
            null,
            null,
            $adminId,
            $transactionId
        );

        if ($updated) {
            log_activity(
                $adminId,
                'crypto_deposit_verified',
                "تأیید واریز {$deposit->amount} USDT ({$deposit->network}) برای کاربر {$deposit->user_id}",
                ['deposit_id' => $depositId, 'transaction_id' => $transactionId]
            );

            $this->response->json([
                'success' => true,
                'message' => 'واریز با موفقیت تأیید شد'
            ]);
            return;
        }

        throw new \RuntimeException('خطا در بروزرسانی وضعیت');

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
     * رد واریز کریپتو
     */
    public function reject(): void
    {
                        $adminId = $this->userId();

        $data = [
            'deposit_id' => $this->request->input('deposit_id'),
            'rejection_reason' => $this->request->input('rejection_reason'),
        ];

        // اعتبارسنجی
        $validator = new Validator($data, [
            'deposit_id' => 'required|numeric',
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
            $depositId = (int)$data['deposit_id'];
            $deposit = $this->depositModel->find($depositId);

            if (!$deposit) {
                $this->response->json([
                    'success' => false,
                    'message' => 'واریز یافت نشد'
                ]);
                return;
            }

            if ($deposit->verification_status === 'verified' || $deposit->verification_status === 'rejected') {
                $this->response->json([
                    'success' => false,
                    'message' => 'این واریز قبلاً بررسی شده است'
                ]);
                return;
            }

            $updated = $this->depositModel->updateStatus(
                $depositId,
                'rejected',
                null,
                $data['rejection_reason'],
                $adminId
            );

            if ($updated) {
                // ثبت لاگ
                log_activity(
                    $adminId,
                    'crypto_deposit_rejected',
                    "رد واریز کریپتو کاربر {$deposit->user_id}",
                    ['deposit_id' => $depositId, 'reason' => $data['rejection_reason']]
                );

                $this->response->json([
                    'success' => true,
                    'message' => 'واریز رد شد'
                ]);
            } else {
                throw new \RuntimeException('خطا در رد واریز');
            }

        } catch (\Exception $e) {
            logger()->error('Admin reject crypto deposit failed', [
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);

            $this->response->json([
                'success' => false,
                'message' => 'خطا در رد واریز'
            ]);
        }
    }
}