<?php

namespace App\Services;

use App\Models\CustomTask;
use App\Models\CustomTaskSubmission;
use App\Models\CustomTaskDispute;
use App\Services\WalletService;
use App\Services\UserLevelService;
use App\Services\ReferralCommissionService;
use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AntiFraud\IPQualityService;
use App\Services\AntiFraud\SessionAnomalyService;
use Core\Database;

/**
 * سرویس مدیریت Custom Tasks
 * نسخه بهبودیافته با استفاده از ساختار موجود پروژه
 */
class CustomTaskService
{
    private CustomTask $taskModel;
    private CustomTaskSubmission $submissionModel;
    private CustomTaskDispute $disputeModel;
    private Database $db;
    private WalletService $walletService;
    private UserLevelService $userLevelService;
    private ReferralCommissionService $referralService;
    
    // استفاده از سیستم Anti-Fraud موجود
    private BrowserFingerprintService $fingerprintService;
    private IPQualityService $ipQualityService;
    private SessionAnomalyService $sessionAnomalyService;

    public function __construct(
        Database $db,
        WalletService $walletService,
        UserLevelService $userLevelService,
        ReferralCommissionService $referralService,
        CustomTask $taskModel,
        CustomTaskSubmission $submissionModel,
        CustomTaskDispute $disputeModel,
        BrowserFingerprintService $fingerprintService,
        IPQualityService $ipQualityService,
        SessionAnomalyService $sessionAnomalyService
    ) {
        $this->db = $db;
        $this->walletService = $walletService;
        $this->userLevelService = $userLevelService;
        $this->referralService = $referralService;
        $this->taskModel = $taskModel;
        $this->submissionModel = $submissionModel;
        $this->disputeModel = $disputeModel;
        $this->fingerprintService = $fingerprintService;
        $this->ipQualityService = $ipQualityService;
        $this->sessionAnomalyService = $sessionAnomalyService;
    }

    /**
     * ایجاد وظیفه جدید
     */
    public function createTask(int $creatorId, array $data): array
    {
        // بررسی فعال بودن از setting (نه کانفیگ!)
        if (!setting('custom_task_enabled', 1)) {
            return ['success' => false, 'message' => 'سیستم وظایف سفارشی غیرفعال است.'];
        }

        $currency = $data['currency'] ?? 'irt';
        $pricePerTask = (float) ($data['price_per_task'] ?? 0);
        $quantity = (int) ($data['total_quantity'] ?? 1);

        // بررسی حداقل قیمت از setting
        $minPrice = $currency === 'usdt'
            ? (float) setting('custom_task_min_price_usdt', 0.50)
            : (float) setting('custom_task_min_price_irt', 5000);

        if ($pricePerTask < $minPrice) {
            $label = $currency === 'usdt' 
                ? number_format($minPrice, 2) . ' USDT' 
                : number_format($minPrice) . ' تومان';
            return ['success' => false, 'message' => "حداقل قیمت هر تسک {$label} است."];
        }

        // محاسبه بودجه - از setting
        $feePercent = (float) setting('custom_task_site_fee_percent', 10);
        $totalBudget = $pricePerTask * $quantity;
        $feeAmount = round($totalBudget * ($feePercent / 100), 2);
        $totalWithFee = $totalBudget + $feeAmount;

        try {
            $this->db->beginTransaction();

            // کسر بودجه از کیف پول
            $idempotencyKey = "ctask_budget_{$creatorId}_" . time() . '_' . bin2hex(random_bytes(4));
            
            $txId = $this->walletService->withdraw(
                $creatorId,
                $totalWithFee,
                $currency,
                [
                    'type' => 'task_budget',
                    'description' => "بودجه وظیفه: {$data['title']}",
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            if (!$txId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'موجودی کافی نیست.'];
            }

            // وضعیت اولیه از setting
            $status = setting('custom_task_auto_approve', 0) ? 'active' : 'pending_review';

            // ایجاد تسک با Model موجود
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
                'task_id' => $task->id,
                'creator_id' => $creatorId,
                'budget' => $totalWithFee,
            ]);

            return [
                'success' => true,
                'message' => 'وظیفه با موفقیت ثبت شد.',
                'task' => $task,
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            logger('error', 'Task creation failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در ثبت وظیفه.'];
        }
    }

    /**
     * شروع انجام تسک با استفاده از Anti-Fraud موجود
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
        if ($this->submissionModel->hasWorkerDone($taskId, $workerId)) {
            return ['success' => false, 'message' => 'شما قبلاً این وظیفه را انجام داده‌اید.'];
        }

        // بررسی سقف روزانه - از setting
        $maxDaily = (int) setting('custom_task_max_daily_submissions', 20);
        if ($this->submissionModel->todayCount($workerId) >= $maxDaily) {
            return ['success' => false, 'message' => "سقف انجام تسک روزانه ({$maxDaily}) تکمیل شده."];
        }

        // ظرفیت باقی‌مانده
        $remaining = $task->total_quantity - $task->completed_count - $task->pending_count;
        if ($remaining <= 0) {
            return ['success' => false, 'message' => 'ظرفیت این وظیفه تکمیل شده.'];
        }

        // استفاده از Anti-Fraud موجود پروژه
        $riskScore = $this->calculateRiskScore($workerId, $taskId);
        
        // بررسی آستانه ریسک - از setting
        $riskThreshold = (float) setting('custom_task_risk_threshold', 70.0);
        if ($riskScore >= $riskThreshold) {
            logger('warning', 'High risk task start attempt', [
                'worker_id' => $workerId,
                'task_id' => $taskId,
                'risk_score' => $riskScore,
            ]);
            // ارسال به صف بررسی دستی یا رد مستقیم
            $autoReject = setting('custom_task_auto_reject_high_risk', 0);
            if ($autoReject) {
                return ['success' => false, 'message' => 'امتیاز ریسک شما بالا است. لطفاً بعداً تلاش کنید.'];
            }
        }

        try {
            $this->db->beginTransaction();

            $deadlineAt = date('Y-m-d H:i:s', strtotime("+{$task->deadline_hours} hours"));
            $idempotencyKey = "ctask_sub_{$taskId}_{$workerId}_" . date('Ymd_His');

            // بررسی تکراری idempotency
            $stmt = $this->db->getConnection()->prepare(
                "SELECT id FROM custom_task_submissions WHERE idempotency_key = :key"
            );
            $stmt->execute(['key' => $idempotencyKey]);
            if ($stmt->fetch()) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'درخواست تکراری است.'];
            }

            // محاسبه پاداش با بونوس
            $rewardAmount = $this->userLevelService->applyEarningBonus(
                $workerId,
                (float) $task->price_per_task
            );

            // ایجاد submission
            $submission = $this->submissionModel->create([
                'task_id' => $taskId,
                'worker_id' => $workerId,
                'deadline_at' => $deadlineAt,
                'reward_amount' => $rewardAmount,
                'reward_currency' => $task->currency,
                'idempotency_key' => $idempotencyKey,
                'worker_ip' => get_client_ip(),
                'worker_device' => get_user_agent(),
                'worker_fingerprint' => generate_device_fingerprint(),
            ]);

            if (!$submission) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در شروع وظیفه.'];
            }

            // به‌روزرسانی شمارنده pending
            $this->taskModel->update($taskId, [
                'pending_count' => $task->pending_count + 1,
            ]);

            $this->db->commit();

            logger('info', 'Task started', [
                'submission_id' => $submission->id,
                'task_id' => $taskId,
                'worker_id' => $workerId,
                'risk_score' => $riskScore,
            ]);

            return [
                'success' => true,
                'message' => 'وظیفه با موفقیت شروع شد.',
                'submission_id' => $submission->id,
                'deadline' => $deadlineAt,
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            logger('error', 'Task start failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در شروع وظیفه.'];
        }
    }

    /**
     * محاسبه ریسک با استفاده از Anti-Fraud موجود
     */
    private function calculateRiskScore(int $userId, int $taskId): float
    {
        $scores = [];

        // 1. بررسی کیفیت IP از سرویس موجود
        try {
            $ipQuality = $this->ipQualityService->checkIP(get_client_ip());
            $scores[] = $ipQuality['fraud_score'] ?? 0;
        } catch (\Exception $e) {
            logger('warning', 'IP quality check failed', ['error' => $e->getMessage()]);
        }

        // 2. بررسی Browser Fingerprint
        try {
            $fingerprint = generate_device_fingerprint();
            $fpCheck = $this->fingerprintService->analyze($fingerprint, $userId);
            if ($fpCheck['is_suspicious']) {
                $scores[] = 60; // امتیاز بالا برای fingerprint مشکوک
            }
        } catch (\Exception $e) {
            logger('warning', 'Fingerprint check failed', ['error' => $e->getMessage()]);
        }

        // 3. بررسی Session Anomaly
        try {
            $sessionCheck = $this->sessionAnomalyService->detect($userId);
            if ($sessionCheck['has_anomaly']) {
                $scores[] = 50;
            }
        } catch (\Exception $e) {
            logger('warning', 'Session anomaly check failed', ['error' => $e->getMessage()]);
        }

        // 4. بررسی تکراری بودن (سرعت submission)
        $recentCount = $this->submissionModel->todayCount($userId);
        $dailyLimit = (int) setting('custom_task_max_daily_submissions', 20);
        if ($recentCount > $dailyLimit * 0.8) {
            $scores[] = 40; // نزدیک به سقف
        }

        // محاسبه میانگین
        return empty($scores) ? 0 : round(array_sum($scores) / count($scores), 2);
    }

    /**
     * ارسال مدرک
     */
    public function submitProof(int $submissionId, int $workerId, array $proofData): array
    {
        $submission = $this->submissionModel->find($submissionId);

        if (!$submission || $submission->worker_id !== $workerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }

        if ($submission->status !== 'in_progress') {
            return ['success' => false, 'message' => 'وضعیت نامعتبر.'];
        }

        // بررسی deadline
        if (strtotime($submission->deadline_at) < time()) {
            return ['success' => false, 'message' => 'مهلت ارسال به پایان رسیده.'];
        }

        // بررسی تکراری بودن proof
        if (!empty($proofData['proof_file_hash'])) {
            if ($this->submissionModel->isDuplicateImage(
                $proofData['proof_file_hash'],
                $submission->task_id
            )) {
                return ['success' => false, 'message' => 'این مدرک قبلاً ارسال شده است.'];
            }
        }

        try {
            $this->db->beginTransaction();

            $updateData = [
                'proof_text' => $proofData['proof_text'] ?? null,
                'proof_file' => $proofData['proof_file'] ?? null,
                'proof_file_hash' => $proofData['proof_file_hash'] ?? null,
                'submitted_at' => date('Y-m-d H:i:s'),
                'status' => 'submitted',
            ];

            $this->submissionModel->update($submissionId, $updateData);

            $this->db->commit();

            logger('info', 'Proof submitted', [
                'submission_id' => $submissionId,
                'worker_id' => $workerId,
            ]);

            // بررسی تایید خودکار - از setting
            $autoApproveHours = (int) setting('custom_task_auto_approve_hours', 48);
            
            return [
                'success' => true,
                'message' => 'مدرک شما با موفقیت ارسال شد.',
                'auto_approve_info' => "در صورت عدم بررسی توسط تبلیغ‌دهنده تا {$autoApproveHours} ساعت آینده، به‌صورت خودکار تایید خواهد شد.",
            ];

        } catch (\Exception $e) {
            $this->db->rollBack();
            logger('error', 'Proof submission failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در ارسال مدرک.'];
        }
    }

    /**
     * بررسی و تایید/رد
     */
    public function reviewSubmission(
        int $submissionId,
        int $reviewerId,
        string $decision,
        ?string $reason = null
    ): array {
        $submission = $this->submissionModel->find($submissionId);

        if (!$submission) {
            return ['success' => false, 'message' => 'یافت نشد.'];
        }

        if ($submission->creator_id !== $reviewerId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }

        if ($submission->status !== 'submitted') {
            return ['success' => false, 'message' => 'وضعیت نامعتبر.'];
        }

        if (!in_array($decision, ['approve', 'reject'])) {
            return ['success' => false, 'message' => 'تصمیم نامعتبر.'];
        }

        if ($decision === 'approve') {
            return $this->approveSubmission($submission);
        } else {
            return $this->rejectSubmission($submission, $reason);
        }
    }

    /**
     * تایید submission
     */
    private function approveSubmission(object $submission): array
    {
        try {
            $this->db->beginTransaction();

            // به‌روزرسانی وضعیت
            $this->submissionModel->update($submission->id, [
                'status' => 'approved',
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);

            // پرداخت پاداش
            $this->payWorkerReward($submission);

            // به‌روزرسانی آمار تسک
            $task = $this->taskModel->find($submission->task_id);
            $this->taskModel->update($task->id, [
                'completed_count' => $task->completed_count + 1,
                'pending_count' => max(0, $task->pending_count - 1),
                'spent_budget' => $task->spent_budget + $submission->reward_amount,
            ]);

            $this->db->commit();

            logger('info', 'Submission approved', [
                'submission_id' => $submission->id,
                'worker_id' => $submission->worker_id,
            ]);

            return ['success' => true, 'message' => 'درخواست تایید شد.'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            logger('error', 'Approval failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در تایید.'];
        }
    }

    /**
     * رد submission
     */
    private function rejectSubmission(object $submission, ?string $reason): array
    {
        try {
            $this->db->beginTransaction();

            $this->submissionModel->update($submission->id, [
                'status' => 'rejected',
                'reviewed_at' => date('Y-m-d H:i:s'),
                'rejection_reason' => $reason,
            ]);

            // کاهش شمارنده pending
            $task = $this->taskModel->find($submission->task_id);
            $this->taskModel->update($task->id, [
                'pending_count' => max(0, $task->pending_count - 1),
            ]);

            $this->db->commit();

            logger('info', 'Submission rejected', [
                'submission_id' => $submission->id,
                'reason' => $reason,
            ]);

            return ['success' => true, 'message' => 'درخواست رد شد.'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            logger('error', 'Rejection failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در رد درخواست.'];
        }
    }

    /**
     * پرداخت پاداش
     */
    private function payWorkerReward(object $submission): void
    {
        $idempotencyKey = "ctask_reward_{$submission->id}_" . time();

        $txId = $this->walletService->deposit(
            $submission->worker_id,
            $submission->reward_amount,
            $submission->reward_currency,
            [
                'type' => 'task_reward',
                'description' => "پاداش وظیفه #{$submission->task_id}",
                'idempotency_key' => $idempotencyKey,
            ]
        );

        if ($txId) {
            $this->submissionModel->update($submission->id, [
                'reward_paid' => 1,
                'reward_transaction_id' => $txId,
            ]);

            // پرداخت کمیسیون
            $this->referralService->processCommission(
                $submission->worker_id,
                'task_reward',
                $submission->id,
                $submission->reward_amount,
                $submission->reward_currency
            );
        }
    }

    // متدهای Query ساده برای Controller ها

    public function find(int $id): ?object
    {
        return $this->taskModel->find($id);
    }

    public function getAvailableTasks(int $workerId, array $filters, int $limit, int $offset): array
    {
        return $this->taskModel->getAvailable($workerId, $filters, $limit, $offset);
    }

    public function getMyTasks(int $creatorId, ?string $status, int $limit, int $offset): array
    {
        return $this->taskModel->getByCreator($creatorId, $status, $limit, $offset);
    }

    public function getMySubmissions(int $workerId, ?string $status, int $limit, int $offset): array
    {
        return $this->submissionModel->getByWorker($workerId, $status, $limit, $offset);
    }
}
