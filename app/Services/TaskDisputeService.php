<?php
// app/Services/TaskDisputeService.php

namespace App\Services;

use App\Models\TaskDispute;
use App\Models\TaskExecution;
use App\Models\Advertisement;
use App\Services\WalletService;
use App\Services\TaskExecutionService;
use Core\Database;

class TaskDisputeService
{
    private \App\Models\Notification $notificationModel;
    private Database $db;
    private WalletService $walletService;

    private TaskExecutionService $taskExecutionService;
    private $taskDisputeModel;
    private $advertisementModel;
    private $taskExecutionModel;

    public function __construct(Database $db, 
        \App\Services\WalletService $walletService,
        \App\Services\TaskExecutionService $taskExecutionService,
        \App\Models\TaskDispute $taskDisputeModel,
        \App\Models\Advertisement $advertisementModel,
        \App\Models\TaskExecution $taskExecutionModel,
        \App\Models\Notification $notificationModel){
        $this->db = $db;
        $this->walletService        = $walletService;
        $this->taskExecutionService = $taskExecutionService;
        $this->taskDisputeModel = $taskDisputeModel;
        $this->advertisementModel = $advertisementModel;
        $this->taskExecutionModel = $taskExecutionModel;
        $this->notificationModel = $notificationModel;
    }

    /**
     * ایجاد اختلاف
     */
    public function open(int $executionId, int $openerId, string $openedBy, string $reason, ?string $evidenceImage = null): array
    {
        $execution = ($this->taskExecutionModel)->find($executionId);
        if (!$execution) {
            return ['success' => false, 'message' => 'تسک یافت نشد.'];
        }

        // فقط تسک‌های رد‌شده یا ارسال‌شده قابل اختلاف هستند
        if (!\in_array($execution->status, ['submitted', 'rejected'])) {
            return ['success' => false, 'message' => 'فقط تسک‌های رد‌شده یا ارسال‌شده قابل اعتراض هستند.'];
        }

        // بررسی اختلاف قبلی باز
        $existingDispute = $this->taskDisputeModel->findByExecution($executionId);
        if ($existingDispute) {
            return ['success' => false, 'message' => 'قبلاً برای این تسک اعتراض ثبت شده است.'];
        }

        // بررسی دسترسی
        $ad = $this->advertisementModel->find($execution->advertisement_id);
        if (!$ad) {
            return ['success' => false, 'message' => 'تبلیغ یافت نشد.'];
        }

        if ($openedBy === 'executor' && $execution->executor_id !== $openerId) {
            return ['success' => false, 'message' => 'دسترسی مجاز نیست.'];
        }
        if ($openedBy === 'advertiser' && $ad->advertiser_id !== $openerId) {
            return ['success' => false, 'message' => 'دسترسی مجاز نیست.'];
        }

        $dispute = $this->taskDisputeModel->create([
            'execution_id'     => $executionId,
            'advertisement_id' => $execution->advertisement_id,
            'opened_by'        => $openedBy,
            'opener_id'        => $openerId,
            'reason'           => $reason,
            'evidence_image'   => $evidenceImage,
            'penalty_currency' => $ad->currency,
        ]);

        if (!$dispute) {
            return ['success' => false, 'message' => 'خطا در ثبت اعتراض.'];
        }

        // تغییر وضعیت تسک
        ($this->taskExecutionModel)->update($executionId, ['status' => 'disputed']);

        logger('task_dispute', "Dispute #{$dispute->id} opened by {$openedBy} #{$openerId} for execution #{$executionId}");

        // نوتیفیکیشن به طرف مقابل
        $notifyUserId = ($openedBy === 'executor') ? $ad->advertiser_id : $execution->executor_id;
        $this->notifyUser($notifyUserId, 'اعتراض جدید',
            "اعتراضی برای تسک «{$ad->title}» ثبت شده. مدیریت بررسی خواهد کرد.", 'warning');

        return [
            'success' => true,
            'message' => 'اعتراض شما ثبت شد و توسط مدیریت بررسی خواهد شد.',
            'dispute' => $dispute,
        ];
    }

    /**
     * داوری توسط ادمین — به نفع انجام‌دهنده
     */
    public function resolveForExecutor(int $disputeId, int $adminId, string $decision, float $penaltyAmount = 0): array
    {
        return $this->resolve($disputeId, $adminId, 'resolved_for_executor', $decision, 'advertiser', $penaltyAmount);
    }

    /**
     * داوری توسط ادمین — به نفع تبلیغ‌دهنده
     */
    public function resolveForAdvertiser(int $disputeId, int $adminId, string $decision, float $penaltyAmount = 0): array
    {
        return $this->resolve($disputeId, $adminId, 'resolved_for_advertiser', $decision, 'executor', $penaltyAmount);
    }

    /**
     * فرآیند داوری مشترک
     */
    private function resolve(int $disputeId, int $adminId, string $resolution, string $decision, string $penaltyTarget, float $penaltyAmount): array
    {
        $dispute = $this->taskDisputeModel->find($disputeId);
        if (!$dispute) {
            return ['success' => false, 'message' => 'اختلاف یافت نشد.'];
        }

        if (!\in_array($dispute->status, ['open', 'under_review'])) {
            return ['success' => false, 'message' => 'این اختلاف قبلاً حل شده است.'];
        }

        $execution = ($this->taskExecutionModel)->find($dispute->execution_id);
        $ad = $this->advertisementModel->find($dispute->advertisement_id);

        if (!$execution || !$ad) {
            return ['success' => false, 'message' => 'اطلاعات ناقص.'];
        }

        try {
            $this->db->beginTransaction();

            // محاسبه مالیات سایت از جریمه
            $siteTaxPercent = (float) (setting('dispute_tax_percent') ?? 20);
            $siteTaxAmount = $penaltyAmount * ($siteTaxPercent / 100);
            $netPenalty = $penaltyAmount - $siteTaxAmount;

            if ($resolution === 'resolved_for_executor') {
                // تایید تسک و پرداخت به انجام‌دهنده
                $approveResult = $this->taskExecutionService->approveByAdmin($execution->id, $adminId);

                // جریمه تبلیغ‌دهنده (اگر تعیین شده)
                if ($penaltyAmount > 0) {
                    $this->walletService->withdraw(
                        $ad->advertiser_id,
                        $penaltyAmount,
                        $ad->currency,
                        'dispute_penalty',
                        'جریمه اختلاف تسک: ' . $ad->title,
                        null,
                        'penalty_' . $disputeId . '_' . str_random(8)
                    );
                }
            } else {
                // رد تسک — بازگشت ظرفیت
                ($this->taskExecutionModel)->update($execution->id, [
                    'status'      => 'rejected',
                    'reviewed_by' => 'admin',
                    'reviewer_id' => $adminId,
                    'reviewed_at' => \date('Y-m-d H:i:s'),
                ]);
                $this->advertisementModel->incrementRemaining($ad->id, $ad->price_per_task);

                // جریمه انجام‌دهنده (اگر تعیین شده)
                if ($penaltyAmount > 0) {
                    $this->walletService->withdraw(
                        $execution->executor_id,
                        $penaltyAmount,
                        $ad->currency,
                        'dispute_penalty',
                        'جریمه اختلاف تسک: ' . $ad->title,
                        null,
                        'penalty_' . $disputeId . '_' . str_random(8)
                    );
                }
            }

            // بروزرسانی اختلاف
            $this->taskDisputeModel->update($disputeId, [
                'status'          => $resolution,
                'admin_decision'  => $decision,
                'admin_id'        => $adminId,
                'penalty_amount'  => $penaltyAmount,
                'penalty_target'  => $penaltyTarget,
                'site_tax_amount' => $siteTaxAmount,
                'resolved_at'     => \date('Y-m-d H:i:s'),
            ]);

            $this->db->commit();

            logger('task_dispute', "Dispute #{$disputeId} resolved: {$resolution} by admin #{$adminId} | Penalty: {$penaltyAmount}");

            // نوتیفیکیشن
            $executorMsg = ($resolution === 'resolved_for_executor')
                ? 'اعتراض به نفع شما حل شد و پاداش پرداخت شد.'
                : 'اعتراض به نفع تبلیغ‌دهنده حل شد.';
            $this->notifyUser($execution->executor_id, 'نتیجه اعتراض', $executorMsg,
                $resolution === 'resolved_for_executor' ? 'success' : 'warning');

            $advertiserMsg = ($resolution === 'resolved_for_advertiser')
                ? 'اعتراض به نفع شما حل شد.'
                : 'اعتراض به نفع انجام‌دهنده حل شد و پاداش پرداخت شد.';
            $this->notifyUser($ad->advertiser_id, 'نتیجه اعتراض', $advertiserMsg,
                $resolution === 'resolved_for_advertiser' ? 'success' : 'warning');

            return ['success' => true, 'message' => 'اختلاف حل شد.'];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            logger('task_dispute_error', $e->getMessage());
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }
    }

    /**
     * نوتیفیکیشن
     */
    private function notifyUser(int $userId, string $title, string $message, string $type = 'info'): void
    {
        try {
            if (\class_exists(\App\Models\Notification::class)) {
                ($this->notificationModel)->create([
                    'user_id' => $userId,
                    'title'   => $title,
                    'message' => $message,
                    'type'    => $type,
                ]);
            }
        } catch (\Throwable $e) {
            logger('notification_error', $e->getMessage());
        }
    }

    // ─── Query Methods (برای Controllers) ───────────────────────

    public function getAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->taskDisputeModel->getAll($filters, $limit, $offset);
    }

    public function countAll(array $filters = []): int
    {
        return $this->taskDisputeModel->countAll($filters);
    }

    public function find(int $id): ?object
    {
        return $this->taskDisputeModel->find($id);
    }
}