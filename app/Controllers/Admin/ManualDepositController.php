<?php

namespace App\Controllers\Admin;
use App\Models\UserBankCard;
use App\Models\User;

use App\Models\ManualDeposit;
use App\Services\WalletService;
use Core\Validator;
use App\Controllers\Admin\BaseAdminController;

class ManualDepositController extends BaseAdminController
{
    private UserBankCard $userBankCardModel;
    private User $userModel;
    private ManualDeposit $depositModel;
    private WalletService $walletService;

    public function __construct(
        UserBankCard $userBankCardModel,
        User $userModel,
        \App\Models\ManualDeposit $depositModel,
        \App\Services\WalletService $walletService)
    {
        parent::__construct();
        $this->userBankCardModel = $userBankCardModel;
        $this->userModel = $userModel;
        $this->depositModel = $depositModel;
        $this->walletService = $walletService;
    }

    /**
     * لیست واریزهای دستی در انتظار
     */
    public function index(): void
    {
                
        $page = (int)$this->request->get('page', 1);
        $status = $this->request->get('status');
        $limit = 20;
        $offset = ($page - 1) * $limit;

        try {
            if ($status) {
                $deposits = $this->depositModel->getAll($status, $limit, $offset);
                $total = $this->depositModel->countAll($status);
            } else {
                $deposits = $this->depositModel->getPendingDeposits($limit, $offset);
                $total = $this->depositModel->countPendingDeposits();
            }

            $totalPages = (int)\ceil($total / $limit);

            view('admin.manual-deposits.index', [
                'deposits' => $deposits,
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'total' => $total,
                'status' => $status,
                'pageTitle' => 'واریزهای دستی'
            ]);

        } catch (\Exception $e) {
            logger()->error('Admin manual deposits index failed', [
                'error' => $e->getMessage()
            ]);

            $this->session->setFlash('error', 'خطا در دریافت لیست');
            redirect('/admin/dashboard');
        }
    }

    /**
     * صفحه بررسی واریز دستی
     */
    public function review(): void
    {
                $depositId = (int)$this->request->get('id');

        try {
            $deposit = $this->depositModel->find($depositId);

            if (!$deposit) {
                $this->session->setFlash('error', 'واریز یافت نشد');
                redirect('/admin/manual-deposits');
                return;
            }

            // دریافت اطلاعات کاربر
            $userModel = $this->userModel;
            $user = $userModel->find($deposit->user_id);

            // دریافت اطلاعات کارت
            $cardModel = $this->userBankCardModel;
            $card = $cardModel->find($deposit->card_id);

            view('admin.manual-deposits.review', [
                'deposit' => $deposit,
                'user' => $user,
                'card' => $card,
                'pageTitle' => 'بررسی واریز دستی'
            ]);

        } catch (\Exception $e) {
            logger()->error('Admin manual deposit review failed', [
                'deposit_id' => $depositId,
                'error' => $e->getMessage()
            ]);

            $this->session->setFlash('error', 'خطا در دریافت اطلاعات');
            redirect('/admin/manual-deposits');
        }
    }

   /**
 * تأیید واریز دستی
 * 
 * فایل: app/Controllers/Admin/ManualDepositsController.php
 * خط: ~100
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

        if ($deposit->status !== 'pending' && $deposit->status !== 'under_review') {
            $this->response->json([
                'success' => false,
                'message' => 'این واریز قبلاً بررسی شده است'
            ]);
            return;
        }

        // ✅ اصلاح شد: افزایش موجودی کاربر با ترتیب صحیح
        // deposit(userId, amount, currency, metadata)
        $result = $this->walletService->deposit(
            (int) $deposit->user_id,         // 1. userId
            (float) $deposit->amount,        // 2. amount
            'irt',                           // 3. currency
            [                                // 4. metadata
                'type'          => 'manual_deposit',
                'gateway'       => 'manual',
                'description'   => 'واریز دستی - شماره پیگیری: ' . ($deposit->tracking_code ?? 'N/A'),
                'tracking_code' => $deposit->tracking_code,
                'approved_by'   => $adminId
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
            $adminId,
            $result['transaction_id']  // ✅ ذخیره transaction_id
        );

        if ($updated) {
            // ثبت لاگ
            log_activity(
                $adminId,
                'manual_deposit_verified',
                "تأیید واریز دستی {$deposit->amount} تومان برای کاربر {$deposit->user_id}",
                ['deposit_id' => $depositId, 'transaction_id' => $result['transaction_id']]
            );

            $this->response->json([
                'success' => true,
                'message' => 'واریز با موفقیت تأیید شد و موجودی کاربر افزایش یافت'
            ]);
        } else {
            throw new \RuntimeException('خطا در بروزرسانی وضعیت');
        }

    } catch (\Exception $e) {
        logger()->error('Admin verify manual deposit failed', [
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
     * رد واریز دستی
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

            if ($deposit->status !== 'pending' && $deposit->status !== 'under_review') {
                $this->response->json([
                    'success' => false,
                    'message' => 'این واریز قبلاً بررسی شده است'
                ]);
                return;
            }

            $updated = $this->depositModel->updateStatus(
                $depositId,
                'rejected',
                $data['rejection_reason'],
                $adminId
            );

            if ($updated) {
                // ثبت لاگ
                log_activity(
                    $adminId,
                    'manual_deposit_rejected',
                    "رد واریز دستی کاربر {$deposit->user_id}",
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
            logger()->error('Admin reject manual deposit failed', [
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