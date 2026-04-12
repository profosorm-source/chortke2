<?php

namespace App\Controllers\Admin;

use App\Services\Logger;
use App\Services\ActivityLogger;
use App\Services\AuditLogger;
use App\Controllers\Admin\BaseAdminController;

class BankCardController extends BaseAdminController
{
    private Logger $logger;
    private ActivityLogger $activityLogger;
    private AuditLogger $auditLogger;

    public function __construct(
        Logger $logger,
        ActivityLogger $activityLogger,
        AuditLogger $auditLogger
    ) {
        parent::__construct();
        $this->logger = $logger;
        $this->activityLogger = $activityLogger;
        $this->auditLogger = $auditLogger;
    }

    public function index()
    {
        try {
            // کد موجود...
            
            return view('admin.bank-cards.index', [/* ... */]);

        } catch (\Exception $e) {
            $this->logger->error('admin.bank_cards.index.failed', [
                'error' => $e->getMessage()
            ]);
            return view('errors.500');
        }
    }

    public function verify()
    {
        try {
            $id = (int)$this->request->param('id');
            
            // تایید کارت
            // ...
            
            $this->activityLogger->log(
                'bank_card.verified',
                "تایید کارت بانکی #{$id}",
                user_id(),
                [
                    'card_id' => $id,
                    'card_number' => $cardNumber ?? null
                ],
                'BankCard',
                $id
            );

            $this->auditLogger->record(
                'bank_card.verified',
                $card->user_id ?? null,
                [
                    'card_id' => $id,
                    'admin_id' => user_id()
                ],
                user_id()
            );

            $this->session->setFlash('success', 'کارت تایید شد');
            return redirect('/admin/bank-cards');

        } catch (\Exception $e) {
            $this->logger->error('admin.bank_card.verify.failed', [
                'card_id' => $id ?? null,
                'error' => $e->getMessage()
            ]);

            $this->session->setFlash('error', 'خطا در تایید کارت');
            return redirect('/admin/bank-cards');
        }
    }

    public function reject()
    {
        try {
            $id = (int)$this->request->param('id');
            $reason = $this->request->post('reason');

            // رد کارت
            // ...

            $this->activityLogger->log(
                'bank_card.rejected',
                "رد کارت بانکی #{$id}: {$reason}",
                user_id(),
                [
                    'card_id' => $id,
                    'reason' => $reason
                ],
                'BankCard',
                $id
            );

            $this->session->setFlash('success', 'کارت رد شد');
            return redirect('/admin/bank-cards');

        } catch (\Exception $e) {
            $this->logger->error('admin.bank_card.reject.failed', [
                'card_id' => $id ?? null,
                'error' => $e->getMessage()
            ]);

            $this->session->setFlash('error', 'خطا در رد کارت');
            return redirect('/admin/bank-cards');
        }
    }
}
