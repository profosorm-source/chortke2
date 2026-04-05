<?php
// app/Services/TaskRecheckService.php

namespace App\Services;

use App\Models\TaskRecheck;
use App\Models\TaskExecution;
use App\Models\Advertisement;
use App\Services\WalletService;
use Core\Database;

class TaskRecheckService
{
    private \App\Models\Notification $notificationModel;
    private Database $db;
    private WalletService $walletService;
    private $taskExecutionModel;
    private $taskRecheckModel;
    private $advertisementModel;

    public function __construct(Database $db, 
        WalletService $walletService,
        \App\Models\TaskExecution $taskExecutionModel,
        \App\Models\TaskRecheck $taskRecheckModel,
        \App\Models\Advertisement $advertisementModel,
        \App\Models\Notification $notificationModel){
        $this->db = $db;
        $this->walletService      = $walletService;
        $this->taskExecutionModel = $taskExecutionModel;
        $this->taskRecheckModel = $taskRecheckModel;
        $this->advertisementModel = $advertisementModel;
        $this->notificationModel = $notificationModel;
    }

    /**
     * ایجاد بررسی مجدد (Cron Job)
     */
    public function createRechecks(int $limit = 50): array
    {
        $candidates = $this->taskExecutionModel->getRecheckCandidates($limit);
        $created = 0;

        foreach ($candidates as $execution) {
            $recheck = $this->taskRecheckModel->create([
                'original_execution_id' => $execution->id,
                'advertisement_id'      => $execution->advertisement_id,
                'executor_id'           => $execution->executor_id,
                'penalty_currency'      => $execution->reward_currency,
            ]);

            if ($recheck) {
                $created++;
            }
        }

        logger('task_recheck', "Created {$created} rechecks from {$limit} candidates");

        return ['success' => true, 'created' => $created];
    }

    /**
     * تایید بررسی مجدد (هنوز فالو/سابسکرایب دارد)
     */
    public function pass(int $recheckId): array
    {
        $recheck = $this->taskRecheckModel->find($recheckId);
        if (!$recheck || $recheck->status !== 'pending') {
            return ['success' => false, 'message' => 'بررسی مجدد یافت نشد.'];
        }

        $this->taskRecheckModel->update($recheckId, [
            'status'     => 'passed',
            'checked_at' => \date('Y-m-d H:i:s'),
        ]);

        // پیام تبریک (بدون پاداش مالی)
        $this->notifyUser($recheck->executor_id, 'آفرین! 👏',
            'بررسی مجدد تسک شما موفقیت‌آمیز بود. ممنون از وفاداری شما!', 'success');

        return ['success' => true, 'message' => 'بررسی مجدد تایید شد.'];
    }

    /**
     * شکست بررسی مجدد (آنفالو/آنسابسکرایب کرده)
     */
    public function fail(int $recheckId): array
    {
        $recheck = $this->taskRecheckModel->find($recheckId);
        if (!$recheck || $recheck->status !== 'pending') {
            return ['success' => false, 'message' => 'بررسی مجدد یافت نشد.'];
        }

        $execution = $this->taskExecutionModel->find($recheck->original_execution_id);
        $ad = $this->advertisementModel->find($recheck->advertisement_id);

        if (!$execution || !$ad) {
            return ['success' => false, 'message' => 'اطلاعات ناقص.'];
        }

        try {
            $db->beginTransaction();

            $penaltyAmount = $execution->reward_amount;

            // جریمه انجام‌دهنده
            $this->walletService->withdraw(
                $recheck->executor_id,
                $penaltyAmount,
                $execution->reward_currency,
                'recheck_penalty',
                'جریمه آنفالو/آنسابسکرایب تسک: ' . ($ad->title ?? ''),
                null,
                'recheck_penalty_' . $recheckId . '_' . str_random(8)
            );

            // بازگشت پول به تبلیغ‌دهنده
            $this->walletService->deposit(
                $ad->advertiser_id,
                $penaltyAmount,
                $execution->reward_currency,
                'recheck_refund',
                'بازگشت پول تسک (آنفالو توسط کاربر): ' . ($ad->title ?? ''),
                null,
                'recheck_refund_' . $recheckId . '_' . str_random(8)
            );

            $this->taskRecheckModel->update($recheckId, [
                'status'                 => 'failed',
                'penalty_amount'         => $penaltyAmount,
                'refunded_to_advertiser' => 1,
                'checked_at'             => \date('Y-m-d H:i:s'),
            ]);

            // افزایش fraud_score کاربر
            $dbInst = $this->db;
            $stmt = $dbInst->prepare("UPDATE users SET fraud_score = fraud_score + 15 WHERE id = ?");
            $stmt->execute([$recheck->executor_id]);

            $db->commit();

            logger('task_recheck', "Recheck #{$recheckId} failed for user #{$recheck->executor_id} | Penalty: {$penaltyAmount}");

            $this->notifyUser($recheck->executor_id, '⚠️ هشدار تخلف',
                "شما تسکی که قبلاً انجام داده بودید را لغو کرده‌اید (آنفالو/آنسابسکرایب). مبلغ " . \number_format($penaltyAmount) . " از حساب شما کسر شد.",
                'danger');

            $this->notifyUser($ad->advertiser_id, 'بازگشت هزینه تسک',
                "کاربری تسک شما را لغو کرده و مبلغ به حساب شما بازگشت داده شد.", 'info');

            return ['success' => true, 'message' => 'جریمه اعمال و پول بازگشت داده شد.'];

        } catch (\Throwable $e) {
            $db->rollBack();
            logger('task_recheck_error', $e->getMessage());
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
        return $this->taskRecheckModel->getAll($filters, $limit, $offset);
    }
}