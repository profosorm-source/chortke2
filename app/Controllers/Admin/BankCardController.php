<?php

namespace App\Controllers\Admin;

use App\Models\UserBankCard;
use App\Models\User;
use Core\Validator;
use App\Controllers\Admin\BaseAdminController;

class BankCardController extends BaseAdminController
{
    private User $userModel;
    private UserBankCard $cardModel;

    public function __construct(User $userModel,
        \App\Models\UserBankCard $cardModel)
    {
        parent::__construct();
        $this->userModel = $userModel;
        $this->cardModel = $cardModel;
    }

    /**
     * لیست کارت‌های در انتظار بررسی
     */
    public function index(): void
    {
                
        $page = (int)$this->request->get('page', 1);
        $limit = 20;
        $offset = ($page - 1) * $limit;

        try {
            $cards = $this->cardModel->getPendingCards($limit, $offset);
            $total = $this->cardModel->countPendingCards();
            $totalPages = (int)\ceil($total / $limit);

            view('admin.bank-cards.index', [
                'cards' => $cards,
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'total' => $total,
                'pageTitle' => 'کارت‌های در انتظار بررسی'
            ]);

        } catch (\Exception $e) {
            logger()->error('Admin bank cards index failed', [
                'error' => $e->getMessage()
            ]);

            $this->session->setFlash('error', 'خطا در دریافت لیست کارت‌ها');
            redirect('/admin/dashboard');
        }
    }

    /**
     * صفحه بررسی کارت
     */
    public function review(): void
    {
                $cardId = (int)$this->request->get('id');

        try {
            $card = $this->cardModel->find($cardId);

            if (!$card) {
                $this->session->setFlash('error', 'کارت یافت نشد');
                redirect('/admin/bank-cards');
                return;
            }

            // دریافت اطلاعات کاربر
            $userModel = $this->userModel;
            $user = $userModel->find($card->user_id);

            view('admin.bank-cards.review', [
                'card' => $card,
                'user' => $user,
                'pageTitle' => 'بررسی کارت بانکی'
            ]);

        } catch (\Exception $e) {
            logger()->error('Admin bank card review failed', [
                'card_id' => $cardId,
                'error' => $e->getMessage()
            ]);

            $this->session->setFlash('error', 'خطا در دریافت اطلاعات');
            redirect('/admin/bank-cards');
        }
    }

    /**
     * تأیید کارت بانکی
     */
    public function verify(): void
    {
                        $adminId = $this->userId();

        $cardId = (int)$this->request->input('card_id');

        try {
            $card = $this->cardModel->find($cardId);

            if (!$card) {
                $this->response->json([
                    'success' => false,
                    'message' => 'کارت یافت نشد'
                ]);
                return;
            }

            if ($card->status !== 'pending') {
                $this->response->json([
                    'success' => false,
                    'message' => 'این کارت قبلاً بررسی شده است'
                ]);
                return;
            }

            $updated = $this->cardModel->updateStatus(
                $cardId,
                'verified',
                null,
                $adminId
            );

            if ($updated) {
                // ثبت لاگ
                log_activity(
                    $adminId,
                    'bank_card_verified',
                    "تأیید کارت بانکی کاربر {$card->user_id}",
                    ['card_id' => $cardId]
                );

                $this->response->json([
                    'success' => true,
                    'message' => 'کارت بانکی با موفقیت تأیید شد'
                ]);
            } else {
                throw new \RuntimeException('خطا در تأیید کارت');
            }

        } catch (\Exception $e) {
            logger()->error('Admin verify bank card failed', [
                'admin_id' => $adminId,
                'card_id' => $cardId,
                'error' => $e->getMessage()
            ]);

            $this->response->json([
                'success' => false,
                'message' => 'خطا در تأیید کارت'
            ]);
        }
    }

    /**
     * رد کارت بانکی
     */
    public function reject(): void
    {
                        $adminId = $this->userId();

        $data = [
            'card_id' => $this->request->input('card_id'),
            'rejection_reason' => $this->request->input('rejection_reason'),
        ];

        // اعتبارسنجی
        $validator = new Validator($data, [
            'card_id' => 'required|numeric',
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
            $cardId = (int)$data['card_id'];
            $card = $this->cardModel->find($cardId);

            if (!$card) {
                $this->response->json([
                    'success' => false,
                    'message' => 'کارت یافت نشد'
                ]);
                return;
            }

            if ($card->status !== 'pending') {
                $this->response->json([
                    'success' => false,
                    'message' => 'این کارت قبلاً بررسی شده است'
                ]);
                return;
            }

            $updated = $this->cardModel->updateStatus(
                $cardId,
                'rejected',
                $data['rejection_reason'],
                $adminId
            );

            if ($updated) {
                // ثبت لاگ
                log_activity(
                    $adminId,
                    'bank_card_rejected',
                    "رد کارت بانکی کاربر {$card->user_id}",
                    ['card_id' => $cardId, 'reason' => $data['rejection_reason']]
                );

                $this->response->json([
                    'success' => true,
                    'message' => 'کارت بانکی رد شد'
                ]);
            } else {
                throw new \RuntimeException('خطا در رد کارت');
            }

        } catch (\Exception $e) {
            logger()->error('Admin reject bank card failed', [
                'admin_id' => $adminId,
                'error' => $e->getMessage()
            ]);

            $this->response->json([
                'success' => false,
                'message' => 'خطا در رد کارت'
            ]);
        }
    }
}