<?php

namespace App\Services;

use App\Models\CustomTask;
use App\Models\CustomTaskSubmission;
use App\Models\TaskDispute;
use App\Services\WalletService;
use App\Services\UserLevelService;
use App\Services\ReferralCommissionService;
use Core\Database;

class CustomTaskService
{
    private TaskDispute $taskDisputeModel;
private CustomTask $taskModel;
private CustomTaskSubmission $subModel;
private Database $db;
private WalletService $walletService;
private UserLevelService $userLevelService;
private ReferralCommissionService $referralCommissionService;

public function __construct(
    Database $db, 
    WalletService $walletService,
    UserLevelService $userLevelService,
    ReferralCommissionService $referralCommissionService,
    CustomTask $taskModel,
    CustomTaskSubmission $subModel,
    TaskDispute $taskDisputeModel
) {
    $this->taskModel = $taskModel;
    $this->subModel = $subModel;
    $this->db = $db;
    $this->walletService = $walletService;
    $this->userLevelService = $userLevelService;
    $this->referralCommissionService = $referralCommissionService;
    $this->taskDisputeModel = $taskDisputeModel;
}

    /**
     * ایجاد وظیفه جدید (تبلیغ‌دهنده)
     */
    public function createTask(int $creatorId, array $data): array
    {
        if (!setting('custom_task_enabled', 1)) {
            return ['success' => false, 'message' => 'سیستم وظایف سفارشی غیرفعال است.'];
        }

        $currency = $data['currency'] ?? 'irt';
        $pricePerTask = (float) ($data['price_per_task'] ?? 0);
        $quantity = (int) ($data['total_quantity'] ?? 1);

        // بررسی حداقل قیمت
        $minPrice = $currency === 'usdt'
            ? (float) setting('custom_task_min_price_usdt', 0.50)
            : (float) setting('custom_task_min_price_irt', 5000);

        if ($pricePerTask < $minPrice) {
            $label = $currency === 'usdt' ? number_format($minPrice, 2) . ' USDT' : number_format($minPrice) . ' تومان';
            return ['success' => false, 'message' => "حداقل قیمت هر تسک {$label} است."];
        }

        // محاسبه بودجه و کارمزد
        $feePercent = (float) setting('custom_task_site_fee_percent', 10);
        $totalBudget = $pricePerTask * $quantity;
        $feeAmount = \round($totalBudget * ($feePercent / 100), 2);
        $totalWithFee = $totalBudget + $feeAmount;

        try {
            $this->db->beginTransaction();

            // کسر بودجه + کارمزد از کیف پول تبلیغ‌دهنده
            $txId = $this->walletService->withdraw(
                $creatorId, $totalWithFee, $currency,
                [
                    'type'            => 'transfer',
                    'description'     => "بودجه وظیفه: {$data['title']}",
                    'idempotency_key' => "ctask_budget_{$creatorId}_" . \time(),
                ]
            );

            if (!$txId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'موجودی کافی نیست.'];
            }

            $status = setting('custom_task_auto_approve', 0) ? 'active' : 'pending_review';

            $task = $this->taskModel->create([
                'creator_id' => $creatorId,
                'title' => $data['title'],
                'description' => $data['description'],
                'link' => $data['link'] ?? null,
                'task_type' => $data['task_type'] ?? 'custom',
                'proof_type' => $data['proof_type'] ?? 'screenshot',
                'proof_description' => $data['proof_description'] ?? null,
                'sample_image' => $data['sample_image'] ?? null,
                'price_per_task' => $pricePerTask,
                'currency' => $currency,
                'total_budget' => $totalBudget,
                'total_quantity' => $quantity,
                'deadline_hours' => $data['deadline_hours'] ?? 24,
                'country_restriction' => $data['country_restriction'] ?? null,
                'device_restriction' => $data['device_restriction'] ?? 'all',
                'os_restriction' => $data['os_restriction'] ?? null,
                'daily_limit_per_user' => $data['daily_limit_per_user'] ?? 1,
                'status' => $status,
                'site_fee_percent' => $feePercent,
                'site_fee_amount' => $feeAmount,
            ]);

            if (!$task) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ایجاد وظیفه.'];
            }

            $this->db->commit();

            logger('info', 'Custom task created', [
                'task_id' => $task->id, 'creator_id' => $creatorId,
                'budget' => $totalWithFee, 'fee' => $feeAmount,
            ]);

            return ['success' => true, 'message' => 'وظیفه با موفقیت ثبت شد.', 'task' => $task];

        } catch (\Exception $e) {
            $this->db->rollBack();
            logger('error', 'Custom task creation failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در ثبت وظیفه.'];
        }
    }

    /**
     * شروع انجام تسک (کارمند)
     */
    public function startTask(int $taskId, int $workerId): array
    {
        $task = $this->taskModel->find($taskId);
        if (!$task || $task->status !== 'active') {
            return ['success' => false, 'message' => 'وظیفه فعال نیست.'];
        }

        if ($task->creator_id === $workerId) {
            return ['success' => false, 'message' => 'نمی‌توانید وظیفه خودتان را انجام دهید.'];
        }

        // بررسی تکراری
        if ($this->subModel->hasWorkerDone($taskId, $workerId)) {
            return ['success' => false, 'message' => 'شما قبلاً این وظیفه را انجام داده‌اید.'];
        }

        // بررسی سقف روزانه
        $maxDaily = (int) setting('custom_task_max_daily_submissions', 20);
        if ($this->subModel->todayCount($workerId) >= $maxDaily) {
            return ['success' => false, 'message' => "سقف انجام تسک روزانه ({$maxDaily}) تکمیل شده."];
        }

        // بررسی ظرفیت باقی‌مانده
        if ($task->remaining_count <= 0) {
            return ['success' => false, 'message' => 'ظرفیت این وظیفه تکمیل شده.'];
        }

        $deadlineAt = \date('Y-m-d H:i:s', \strtotime("+{$task->deadline_hours} hours"));
        $idempotencyKey = "ctask_sub_{$taskId}_{$workerId}_" . \date('Y-m-d');

        // بررسی تکراری idempotency
        $existing = $this->db->prepare("SELECT id FROM custom_task_submissions WHERE idempotency_key = ?");
        $existing->execute([$idempotencyKey]);
        if ($existing->fetch()) {
            return ['success' => false, 'message' => 'شما قبلاً امروز این وظیفه را شروع کرده‌اید.'];
        }

        // محاسبه پاداش با بونوس سطح
        $rewardAmount = $this->userLevelService->applyEarningBonus($workerId, (float) $task->price_per_task);

        $sub = $this->subModel->create([
            'task_id' => $taskId,
            'worker_id' => $workerId,
            'deadline_at' => $deadlineAt,
            'reward_amount' => $rewardAmount,
            'reward_currency' => $task->currency,
            'idempotency_key' => $idempotencyKey,
        ]);

        if (!$sub) {
            return ['success' => false, 'message' => 'خطا در شروع وظیفه.'];
        }

        // افزایش pending_count
        $this->taskModel->update($taskId, ['pending_count' => $task->pending_count + 1]);

        return ['success' => true, 'message' => 'وظیفه شروع شد.', 'submission' => $sub, 'deadline' => $deadlineAt];
    }

    /**
     * ارسال مدرک (کارمند)
     */
    public function submitProof(int $submissionId, int $workerId, array $proofData): array
    {
        $sub = $this->subModel->find($submissionId);
        if (!$sub || (int) $sub->worker_id !== $workerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if ($sub->status !== 'in_progress') {
            return ['success' => false, 'message' => 'وضعیت نامعتبر.'];
        }
        if (\strtotime($sub->deadline_at) < \time()) {
            $this->expireSubmission($submissionId, $sub->task_id);
            return ['success' => false, 'message' => 'مهلت شما تمام شده.'];
        }

        $updateData = [
            'submitted_at' => \date('Y-m-d H:i:s'),
            'status' => 'submitted',
        ];

        if (!empty($proofData['proof_text'])) {
            $updateData['proof_text'] = $proofData['proof_text'];
        }
        if (!empty($proofData['proof_file'])) {
            $updateData['proof_file'] = $proofData['proof_file'];

            // بررسی تکراری بودن تصویر
            if (setting('custom_task_duplicate_image_check', 1)) {
                $hash = $proofData['proof_file_hash'] ?? null;
                if ($hash && $this->subModel->isDuplicateImage($hash, (int) $sub->task_id)) {
                    return ['success' => false, 'message' => 'این تصویر قبلاً برای این وظیفه ارسال شده.'];
                }
                $updateData['proof_file_hash'] = $hash;
            }
        }

        $this->subModel->update($submissionId, $updateData);

        logger('info', 'Task proof submitted', ['submission_id' => $submissionId, 'worker_id' => $workerId]);

        return ['success' => true, 'message' => 'مدرک با موفقیت ارسال شد. منتظر بررسی باشید.'];
    }

    /**
     * تأیید/رد توسط تبلیغ‌دهنده
     */
    public function reviewSubmission(int $submissionId, int $advertiserId, string $decision, ?string $reason = null): array
    {
        $sub = $this->subModel->find($submissionId);
        if (!$sub) return ['success' => false, 'message' => 'یافت نشد.'];
        if ((int) $sub->creator_id !== $advertiserId) return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        if ($sub->status !== 'submitted') return ['success' => false, 'message' => 'وضعیت نامعتبر.'];

        try {
            $this->db->beginTransaction();

            if ($decision === 'approve') {
                $this->subModel->update($submissionId, [
                    'status' => 'approved',
                    'reviewed_at' => \date('Y-m-d H:i:s'),
                ]);

                // پرداخت به کارمند
                $this->payWorker($sub);

                // بروزرسانی تسک
                $task = $this->taskModel->find((int) $sub->task_id);
                $this->taskModel->update($task->id, [
                    'completed_count' => $task->completed_count + 1,
                    'pending_count' => \max(0, $task->pending_count - 1),
                    'spent_budget' => $task->spent_budget + $sub->reward_amount,
                ]);

                // بررسی تکمیل شدن تسک
                if (($task->completed_count + 1) >= $task->total_quantity) {
                    $this->taskModel->update($task->id, ['status' => 'completed']);
                }

                // ثبت فعالیت سطح
                $this->userLevelService->recordTaskCompletion((int) $sub->worker_id, (float) $sub->reward_amount, $sub->reward_currency);

                $this->db->commit();
                return ['success' => true, 'message' => 'انجام کار تأیید و پاداش پرداخت شد.'];

            } else {
                $this->subModel->update($submissionId, [
                    'status' => 'rejected',
                    'rejection_reason' => $reason ?? 'مدرک ناکافی',
                    'reviewed_at' => \date('Y-m-d H:i:s'),
                ]);

                $task = $this->taskModel->find((int) $sub->task_id);
                $this->taskModel->update($task->id, [
                    'pending_count' => \max(0, $task->pending_count - 1),
                ]);

                $this->db->commit();
                return ['success' => true, 'message' => 'مدرک رد شد.'];
            }

        } catch (\Exception $e) {
            $this->db->rollBack();
            logger('error', 'Review submission failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در بررسی.'];
        }
    }

    /**
     * ثبت اختلاف
     */
    public function raiseDispute(int $submissionId, int $raisedBy, string $role, string $reason, ?string $evidenceFile = null): array
    {
        $disputeModel = $this->taskDisputeModel;

        if ($disputeModel->hasOpenDispute($submissionId)) {
            return ['success' => false, 'message' => 'یک اختلاف باز برای این مورد وجود دارد.'];
        }

        $sub = $this->subModel->find($submissionId);
        if (!$sub) return ['success' => false, 'message' => 'یافت نشد.'];

        // بروزرسانی وضعیت submission
        $this->subModel->update($submissionId, ['status' => 'disputed']);

        $dispute = $disputeModel->create([
            'submission_id' => $submissionId,
            'task_id' => (int) $sub->task_id,
            'raised_by' => $raisedBy,
            'raised_by_role' => $role,
            'reason' => $reason,
            'evidence_file' => $evidenceFile,
        ]);

        logger('info', 'Task dispute raised', [
            'dispute_id' => $dispute->id ?? null,
            'submission_id' => $submissionId,
            'raised_by' => $raisedBy,
        ]);

        return ['success' => true, 'message' => 'اختلاف ثبت شد و برای بررسی ادمین ارسال خواهد شد.'];
    }

    /**
     * داوری اختلاف (ادمین)
     */
    public function resolveDispute(int $disputeId, int $adminId, string $decision, string $note, ?float $penaltyAmount = null): array
    {
        $disputeModel = $this->taskDisputeModel;
        $dispute = $disputeModel->find($disputeId);
        if (!$dispute || $dispute->status === 'resolved') {
            return ['success' => false, 'message' => 'اختلاف قابل بررسی نیست.'];
        }

        try {
            $this->db->beginTransaction();

            $penaltyUserId = null;
            $sitePenaltyShare = 0;
            $penaltyCurrency = 'irt';

            $sub = $this->subModel->find((int) $dispute->submission_id);

            if ($decision === 'worker_wins') {
                // پاداش به کارمند
                if ($sub && !$sub->reward_paid) {
                    $this->payWorker($sub);
                    $this->subModel->update($sub->id, ['status' => 'approved']);
                }
                // جریمه تبلیغ‌دهنده
                $penaltyUserId = (int) $dispute->advertiser_id;

            } elseif ($decision === 'advertiser_wins') {
                // جریمه کارمند
                $penaltyUserId = $sub ? (int) $sub->worker_id : null;
                if ($sub) $this->subModel->update($sub->id, ['status' => 'rejected']);

            } elseif ($decision === 'split') {
                // هر دو طرف → بدون جریمه، بدون پرداخت
                if ($sub) $this->subModel->update($sub->id, ['status' => 'rejected']);
            }

            // محاسبه جریمه
            if ($penaltyUserId && $penaltyAmount && $penaltyAmount > 0) {
                $siteSharePercent = (float) setting('custom_task_dispute_penalty_percent', 20);
                $sitePenaltyShare = \round($penaltyAmount * ($siteSharePercent / 100), 2);
                $penaltyCurrency = $sub ? $sub->reward_currency : 'irt';
            }

            $disputeModel->update($disputeId, [
                'admin_id' => $adminId,
                'admin_decision' => $decision,
                'admin_note' => $note,
                'penalty_user_id' => $penaltyUserId,
                'penalty_amount' => $penaltyAmount ?? 0,
                'penalty_currency' => $penaltyCurrency,
                'site_penalty_share' => $sitePenaltyShare,
                'status' => 'resolved',
                'resolved_at' => \date('Y-m-d H:i:s'),
            ]);

            $this->db->commit();

            logger('info', 'Dispute resolved', [
                'dispute_id' => $disputeId, 'decision' => $decision,
                'admin_id' => $adminId, 'penalty' => $penaltyAmount,
            ]);

            return ['success' => true, 'message' => 'اختلاف حل شد.'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            logger('error', 'Dispute resolution failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در حل اختلاف.'];
        }
    }

    /**
     * پرداخت پاداش به کارمند
     */
    private function payWorker(object $sub): void
    {
        $txId = $this->walletService->deposit(
            (int) $sub->worker_id,
            (float) $sub->reward_amount,
            $sub->reward_currency,
            [
                'type'            => 'task_reward',
                'description'     => "پاداش وظیفه #{$sub->task_id}",
                'idempotency_key' => "ctask_reward_{$sub->id}",
            ]
        );

        if ($txId) {
            $this->subModel->update($sub->id, [
                'reward_paid' => 1,
                'reward_transaction_id' => $txId,
            ]);

            // کمیسیون معرفی
            $this->referralCommissionService->processCommission(
                (int) $sub->worker_id, 'task_reward',
                (int) $sub->id, (float) $sub->reward_amount, $sub->reward_currency
            );
        }
    }

    /**
     * انقضای submission
     */
    public function expireSubmission(int $subId, int $taskId): void
    {
        $this->subModel->update($subId, ['status' => 'expired']);
        $task = $this->taskModel->find($taskId);
        if ($task) {
            $this->taskModel->update($taskId, ['pending_count' => \max(0, $task->pending_count - 1)]);
        }
    }

    /**
     * CronJob: انقضای submission‌های تمام‌شده
     */
    public function processExpiredSubmissions(): int
    {
        $expired = $this->subModel->getExpiredSubmissions();
        $count = 0;
        foreach ($expired as $sub) {
            $this->expireSubmission($sub->id, $sub->task_id);
            $count++;
        }
        if ($count > 0) logger('info', 'Expired submissions processed', ['count' => $count]);
        return $count;
    }

    /**
     * CronJob: تأیید خودکار submission‌های بررسی‌نشده
     */
    public function autoApproveUnreviewed(): int
    {
        $hours = (int) setting('custom_task_auto_expire_hours', 48);
        $unreviewed = $this->subModel->getUnreviewedSubmissions($hours);
        $count = 0;
        foreach ($unreviewed as $sub) {
            $fullSub = $this->subModel->find($sub->id);
            if ($fullSub && $fullSub->status === 'submitted') {
                try {
                    $this->db->beginTransaction();
                    $this->subModel->update($sub->id, ['status' => 'approved', 'reviewed_at' => \date('Y-m-d H:i:s')]);
                    $this->payWorker($fullSub);
                    $task = $this->taskModel->find($sub->task_id);
                    if ($task) {
                        $this->taskModel->update($task->id, [
                            'completed_count' => $task->completed_count + 1,
                            'pending_count' => \max(0, $task->pending_count - 1),
                            'spent_budget' => $task->spent_budget + $fullSub->reward_amount,
                        ]);
                    }
                    $this->db->commit();
                    $count++;
                } catch (\Throwable $e) {
                    $this->db->rollBack();
                    logger('error', 'autoApproveUnreviewed failed for sub #' . $sub->id, ['error' => $e->getMessage()]);
                }
            }
        }
        if ($count > 0) logger('info', 'Auto-approved submissions', ['count' => $count]);
        return $count;
    }

    // ─── Query Methods (برای Controllers) ───────────────────────

    public function find(int $id): ?object
    {
        return $this->taskModel->find($id);
    }

    public function taskTypes(): array
    {
        return $this->taskModel->taskTypes();
    }

    public function proofTypes(): array
    {
        return $this->taskModel->proofTypes();
    }

    public function statusLabels(): array
    {
        return $this->taskModel->statusLabels();
    }

    public function statusClasses(): array
    {
        return $this->taskModel->statusClasses();
    }

    public function getByCreator(int $creatorId, ?string $status = null, int $limit = 20, int $offset = 0): array
    {
        return $this->taskModel->getByCreator($creatorId, $status, $limit, $offset);
    }
}
