<?php
// app/Services/TaskExecutionService.php

namespace App\Services;

use App\Models\Advertisement;
use App\Models\TaskExecution;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Core\Database;

class TaskExecutionService
{
    private \App\Services\ReferralCommissionService $referralCommissionService;
    private \App\Models\Notification $notificationModel;
    private Database $db;
    private WalletService $walletService;
    private Advertisement $advertisementModel;
    private TaskExecution $taskExecutionModel;
    private SocialAccount $socialAccountModel;

    public function __construct(Database $db, 
        WalletService $walletService,
        \App\Models\Advertisement $advertisementModel,
        \App\Models\TaskExecution $taskExecutionModel,
        \App\Models\SocialAccount $socialAccountModel,
        \App\Models\Notification $notificationModel,
        \App\Services\ReferralCommissionService $referralCommissionService){
        $this->db = $db;
        $this->walletService = $walletService;
        $this->advertisementModel = $advertisementModel;
        $this->taskExecutionModel = $taskExecutionModel;
        $this->socialAccountModel = $socialAccountModel;
        $this->notificationModel = $notificationModel;
        $this->referralCommissionService = $referralCommissionService;
    }

    /**
     * شروع انجام تسک
     */
    public function start(int $adId, int $executorId): array
    {
        $ad = $this->advertisementModel->find($adId);
        if (!$ad) {
            return ['success' => false, 'message' => 'تبلیغ یافت نشد.'];
        }

        // بررسی وضعیت تبلیغ
        if ($ad->status !== 'active') {
            return ['success' => false, 'message' => 'این تبلیغ فعال نیست.'];
        }

        // تبلیغ‌دهنده نمی‌تواند تسک خودش را انجام دهد
        if ($ad->advertiser_id === $executorId) {
            return ['success' => false, 'message' => 'شما نمی‌توانید تسک خودتان را انجام دهید.'];
        }

        // بررسی تعداد باقیمانده
        if ($ad->remaining_count <= 0) {
            return ['success' => false, 'message' => 'ظرفیت این تبلیغ تکمیل شده است.'];
        }

        // بررسی انجام قبلی
        if ($this->taskExecutionModel->existsByAdAndExecutor($adId, $executorId)) {
            return ['success' => false, 'message' => 'شما قبلاً این تسک را انجام داده‌اید.'];
        }

        // بررسی Cooldown
        $cooldownCheck = $this->checkCooldown($executorId, $ad->task_type, $ad->getCooldownMinutes());
        if (!$cooldownCheck['passed']) {
            return ['success' => false, 'message' => $cooldownCheck['message']];
        }

        // بررسی حساب اجتماعی تایید‌شده
        $socialAccountId = null;
        if ($ad->requiresSocialAccount()) {
            $socialAccount = $this->socialAccountModel->findByUserAndPlatform($executorId, $ad->platform);
            if (!$socialAccount || $socialAccount->status !== 'verified') {
                $platformName = $this->socialAccountModel->platformLabel($ad->platform);
                return [
                    'success' => false,
                    'message' => "برای انجام این تسک باید حساب {$platformName} تایید‌شده داشته باشید.",
                ];
            }
            $socialAccountId = $socialAccount->id;
        }

        // بررسی محدودیت‌ها
        $restrictionCheck = $this->checkRestrictions($ad, $executorId);
        if (!$restrictionCheck['passed']) {
            return ['success' => false, 'message' => $restrictionCheck['message']];
        }

        // بررسی Silent Blacklist
        if ($this->isBlacklisted($executorId)) {
            return ['success' => false, 'message' => 'در حال حاضر امکان انجام تسک وجود ندارد. لطفاً بعداً تلاش کنید.'];
        }

        // محاسبه deadline (2 دقیقه برای تسک‌های ساده)
        $deadlineMinutes = $this->getDeadlineMinutes($ad->task_type);
        $deadlineAt = \date('Y-m-d H:i:s', \strtotime("+{$deadlineMinutes} minutes"));

        // ایجاد execution
        $execution = $this->taskExecutionModel->create([
            'advertisement_id'           => $adId,
            'executor_id'                => $executorId,
            'executor_social_account_id' => $socialAccountId,
            'reward_amount'              => $ad->price_per_task,
            'reward_currency'            => $ad->currency,
            'deadline_at'                => $deadlineAt,
            'idempotency_key'            => 'task_' . $adId . '_' . $executorId . '_' . str_random(8),
        ]);

        if (!$execution) {
            return ['success' => false, 'message' => 'خطا در شروع تسک. لطفاً دوباره تلاش کنید.'];
        }

        logger('task_execution', "User {$executorId} started task for ad #{$adId}");

        return [
            'success'   => true,
            'message'   => 'تسک شروع شد. لطفاً قبل از اتمام زمان، کار را انجام و مدرک ارسال کنید.',
            'execution' => $execution,
            'deadline'  => $deadlineAt,
            'target_url' => $ad->target_url,
        ];
    }

    /**
     * ارسال مدرک انجام تسک
     */
    public function submit(int $executionId, int $executorId, array $data): array
    {
        $execution = $this->taskExecutionModel->find($executionId);
        if (!$execution || $execution->executor_id !== $executorId) {
            return ['success' => false, 'message' => 'تسک یافت نشد.'];
        }

        if ($execution->status !== 'started') {
            return ['success' => false, 'message' => 'این تسک قابل ارسال نیست.'];
        }

        // بررسی deadline
        if ($execution->deadline_at && \strtotime($execution->deadline_at) < \time()) {
            $this->taskExecutionModel->update($executionId, ['status' => 'expired']);
            return ['success' => false, 'message' => 'زمان انجام تسک به پایان رسیده است.'];
        }

        // بررسی سرعت انجام (ضد ربات)
        $startedAt = \strtotime($execution->started_at);
        $elapsed = \time() - $startedAt;
        if ($elapsed < 15) {
            // کمتر از 15 ثانیه → مشکوک
            $this->taskExecutionModel->update($executionId, [
                'fraud_score' => $execution->fraud_score + 30,
                'behavior_data' => \json_encode(['too_fast' => true, 'elapsed' => $elapsed]),
            ]);
            return ['success' => false, 'message' => 'سرعت انجام کار غیرطبیعی است. لطفاً تسک را با دقت انجام دهید.'];
        }

        // بررسی مدرک
        if (empty($data['proof_image'])) {
            return ['success' => false, 'message' => 'لطفاً مدرک (اسکرین‌شات) انجام کار را ارسال کنید.'];
        }

        // بررسی تصویر تکراری (Hash)
        $duplicateCheck = $this->checkDuplicateProof($data['proof_image'], $executorId);
        if ($duplicateCheck['is_duplicate']) {
            $this->taskExecutionModel->update($executionId, [
                'fraud_score' => $execution->fraud_score + 50,
            ]);
            return ['success' => false, 'message' => 'این تصویر قبلاً ارسال شده است. لطفاً اسکرین‌شات جدید بگیرید.'];
        }

        // بروزرسانی
        $result = $this->taskExecutionModel->update($executionId, [
            'proof_image'    => $data['proof_image'],
            'proof_metadata' => isset($data['proof_metadata']) ? \json_encode($data['proof_metadata']) : null,
            'status'         => 'submitted',
            'submitted_at'   => \date('Y-m-d H:i:s'),
            'behavior_data'  => isset($data['behavior_data']) ? \json_encode($data['behavior_data']) : null,
        ]);

        if (!$result) {
            return ['success' => false, 'message' => 'خطا در ارسال مدرک.'];
        }

        logger('task_execution', "User {$executorId} submitted proof for execution #{$executionId}");

        // نوتیفیکیشن به تبلیغ‌دهنده
        $ad = $this->advertisementModel->find($execution->advertisement_id);
        if ($ad) {
            $this->notifyUser($ad->advertiser_id, 'مدرک تسک جدید',
                "مدرک انجام تسک «{$ad->title}» ارسال شد. لطفاً بررسی کنید.", 'info');
        }

        return [
            'success' => true,
            'message' => 'مدرک با موفقیت ارسال شد و در انتظار بررسی قرار گرفت.',
        ];
    }

    /**
     * تایید تسک توسط تبلیغ‌دهنده
     */
    public function approveByAdvertiser(int $executionId, int $advertiserId): array
    {
        $execution = $this->taskExecutionModel->find($executionId);
        if (!$execution) {
            return ['success' => false, 'message' => 'تسک یافت نشد.'];
        }

        $ad = $this->advertisementModel->find($execution->advertisement_id);
        if (!$ad || $ad->advertiser_id !== $advertiserId) {
            return ['success' => false, 'message' => 'دسترسی مجاز نیست.'];
        }

        if ($execution->status !== 'submitted') {
            return ['success' => false, 'message' => 'فقط تسک‌های ارسال‌شده قابل تایید هستند.'];
        }

        return $this->approveExecution($execution, $ad, 'advertiser', $advertiserId);
    }

    /**
     * تایید تسک توسط ادمین
     */
    public function approveByAdmin(int $executionId, int $adminId): array
    {
        $execution = $this->taskExecutionModel->find($executionId);
        if (!$execution) {
            return ['success' => false, 'message' => 'تسک یافت نشد.'];
        }

        $ad = $this->advertisementModel->find($execution->advertisement_id);
        if (!$ad) {
            return ['success' => false, 'message' => 'تبلیغ یافت نشد.'];
        }

        return $this->approveExecution($execution, $ad, 'admin', $adminId);
    }

    /**
     * فرآیند تایید مشترک
     */
    private function approveExecution(TaskExecution $execution, Advertisement $ad, string $reviewerType, int $reviewerId): array
    {
        try {
            $this->db->beginTransaction();

            // کاهش تعداد و بودجه تبلیغ
            $decremented = $this->advertisementModel->decrementRemaining($ad->id, $ad->price_per_task);
            if (!$decremented) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در بروزرسانی تبلیغ.'];
            }

            // پرداخت پاداش به انجام‌دهنده
            $rewardResult = $this->walletService->deposit(
                $execution->executor_id,
                $execution->reward_amount,
                $execution->reward_currency,
                'task_reward',
                'پاداش انجام تسک: ' . $ad->title,
                null,
                $execution->idempotency_key
            );

            if (!$rewardResult['success']) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در پرداخت پاداش.'];
            }

            // بروزرسانی وضعیت
            $this->taskExecutionModel->update($execution->id, [
                'status'      => 'approved',
                'reviewed_by' => $reviewerType,
                'reviewer_id' => $reviewerId,
                'reviewed_at' => \date('Y-m-d H:i:s'),
                'reward_paid' => 1,
                'paid_at'     => \date('Y-m-d H:i:s'),
            ]);

            // پرداخت کمیسیون معرف
            $this->payReferralCommission($execution);

            // بررسی تکمیل تبلیغ
            $this->advertisementModel->checkAndComplete($ad->id);

            $this->db->commit();

            logger('task_execution', "Execution #{$execution->id} approved by {$reviewerType} #{$reviewerId} | Reward: {$execution->reward_amount} {$execution->reward_currency}");

            // نوتیفیکیشن
            $this->notifyUser($execution->executor_id, 'تسک تایید شد',
                "تسک «{$ad->title}» تایید شد و " . \number_format($execution->reward_amount) . " به کیف پول شما اضافه شد.",
                'success');

            return ['success' => true, 'message' => 'تسک با موفقیت تایید شد.'];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            logger('task_execution_error', $e->getMessage());
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }
    }

    /**
     * رد تسک توسط تبلیغ‌دهنده
     */
    public function rejectByAdvertiser(int $executionId, int $advertiserId, string $reason): array
    {
        $execution = $this->taskExecutionModel->find($executionId);
        if (!$execution) {
            return ['success' => false, 'message' => 'تسک یافت نشد.'];
        }

        $ad = $this->advertisementModel->find($execution->advertisement_id);
        if (!$ad || $ad->advertiser_id !== $advertiserId) {
            return ['success' => false, 'message' => 'دسترسی مجاز نیست.'];
        }

        if ($execution->status !== 'submitted') {
            return ['success' => false, 'message' => 'فقط تسک‌های ارسال‌شده قابل رد هستند.'];
        }

        $this->taskExecutionModel->update($executionId, [
            'status'           => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by'      => 'advertiser',
            'reviewer_id'      => $advertiserId,
            'reviewed_at'      => \date('Y-m-d H:i:s'),
        ]);

        logger('task_execution', "Advertiser {$advertiserId} rejected execution #{$executionId}: {$reason}");

        $this->notifyUser($execution->executor_id, 'تسک رد شد',
            "تسک «{$ad->title}» رد شد. دلیل: {$reason}\nدر صورت اعتراض می‌توانید تیکت ثبت کنید.",
            'danger');

        return ['success' => true, 'message' => 'تسک رد شد و به انجام‌دهنده اطلاع داده شد.'];
    }

    /**
     * رد تسک توسط ادمین
     */
    public function rejectByAdmin(int $executionId, int $adminId, string $reason): array
    {
        $execution = $this->taskExecutionModel->find($executionId);
        if (!$execution) {
            return ['success' => false, 'message' => 'تسک یافت نشد.'];
        }

        $ad = $this->advertisementModel->find($execution->advertisement_id);

        $this->taskExecutionModel->update($executionId, [
            'status'           => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by'      => 'admin',
            'reviewer_id'      => $adminId,
            'reviewed_at'      => \date('Y-m-d H:i:s'),
        ]);

        // بازگشت ظرفیت تبلیغ
        if ($ad) {
            $this->advertisementModel->incrementRemaining($ad->id, $ad->price_per_task);
        }

        logger('task_execution', "Admin {$adminId} rejected execution #{$executionId}: {$reason}");

        if ($execution->executor_id) {
            $adTitle = $ad ? $ad->title : 'نامشخص';
            $this->notifyUser($execution->executor_id, 'تسک رد شد',
                "تسک «{$adTitle}» توسط مدیریت رد شد. دلیل: {$reason}", 'danger');
        }

        return ['success' => true, 'message' => 'تسک رد شد.'];
    }

    /**
     * بررسی Cooldown
     */
    private function checkCooldown(int $executorId, string $taskType, int $cooldownMinutes): array
    {
        $lastTime = $this->taskExecutionModel->getLastExecutionTime($executorId, $taskType);

        if ($lastTime) {
            $elapsed = (\time() - \strtotime($lastTime)) / 60;
            if ($elapsed < $cooldownMinutes) {
                $remaining = \ceil($cooldownMinutes - $elapsed);
                return [
                    'passed'  => false,
                    'message' => "لطفاً {$remaining} دقیقه دیگر صبر کنید. محدودیت زمانی بین تسک‌های همنوع.",
                ];
            }
        }

        return ['passed' => true];
    }

    /**
     * بررسی محدودیت‌های تبلیغ
     */
    private function checkRestrictions(Advertisement $ad, int $executorId): array
    {
        $restrictions = $ad->getRestrictions();

        // بررسی حداقل فالوور
        if (isset($restrictions->min_follower) && $restrictions->min_follower > 0) {
            $socialAccount = $this->socialAccountModel->findByUserAndPlatform($executorId, $ad->platform);
            if (!$socialAccount || $socialAccount->follower_count < $restrictions->min_follower) {
                return [
                    'passed'  => false,
                    'message' => "حداقل {$restrictions->min_follower} فالوور نیاز است.",
                ];
            }
        }

        // بررسی حداقل سن حساب
        if (isset($restrictions->min_account_age_months) && $restrictions->min_account_age_months > 0) {
            $socialAccount = $this->socialAccountModel->findByUserAndPlatform($executorId, $ad->platform);
            if (!$socialAccount || $socialAccount->account_age_months < $restrictions->min_account_age_months) {
                return [
                    'passed'  => false,
                    'message' => "حساب شما باید حداقل {$restrictions->min_account_age_months} ماه قدمت داشته باشد.",
                ];
            }
        }

        return ['passed' => true];
    }

    /**
     * بررسی تصویر تکراری
     */
    private function checkDuplicateProof(string $proofImage, int $executorId): array
    {
        $imagePath = \rtrim(env('UPLOAD_PATH', 'storage/uploads'), '/') . '/' . $proofImage;

        if (!\file_exists($imagePath)) {
            return ['is_duplicate' => false];
        }

        $imageHash = \md5_file($imagePath);

        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM task_executions 
            WHERE executor_id = ? 
              AND proof_metadata LIKE ?
              AND status != 'expired'
        ");
        $stmt->execute([$executorId, '%' . $imageHash . '%']);

        return ['is_duplicate' => (int) $stmt->fetchColumn() > 0];
    }

    /**
     * بررسی Silent Blacklist
     */
    private function isBlacklisted(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT fraud_score FROM users WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $score = (float) $stmt->fetchColumn();

            // اگر امتیاز تقلب بالای 80 باشد
            return $score >= 80;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * پرداخت کمیسیون معرف
     */
    private function payReferralCommission(TaskExecution $execution): void
    {
        try {
            if (\class_exists(\App\Services\ReferralCommissionService::class)) {
                $refService = $this->referralCommissionService;
                $refService->payCommission(
                    $execution->executor_id,
                    $execution->reward_amount,
                    $execution->reward_currency,
                    'task_reward',
                    $execution->id
                );
            }
        } catch (\Throwable $e) {
            logger('referral_commission_error', "Execution #{$execution->id}: " . $e->getMessage());
        }
    }

    /**
     * deadline بر اساس نوع تسک (دقیقه)
     */
    private function getDeadlineMinutes(string $taskType): int
    {
        $deadlines = [
            'follow'       => 2,
            'subscribe'    => 3,
            'join_channel' => 2,
            'join_group'   => 2,
            'like'         => 2,
            'comment'      => 5,
            'view'         => 10,
            'story_view'   => 2,
        ];
        return $deadlines[$taskType] ?? 5;
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
        return $this->taskExecutionModel->getAll($filters, $limit, $offset);
    }

    public function countAll(array $filters = []): int
    {
        return $this->taskExecutionModel->countAll($filters);
    }

    public function find(int $id): ?object
    {
        return $this->taskExecutionModel->find($id);
    }

    public function findAd(int $id): ?object
    {
        return $this->advertisementModel->find($id);
    }

    public function getByExecutor(int $userId, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        return $this->taskExecutionModel->getByExecutor($userId, $filters, $limit, $offset);
    }

    public function countByExecutor(int $userId, array $filters = []): int
    {
        return $this->taskExecutionModel->countByExecutor($userId, $filters);
    }

    public function getUserStats(int $userId): object
    {
        return $this->taskExecutionModel->getUserStats($userId);
    }

    public function getPendingForAdvertiser(int $userId, int $limit = 50, int $offset = 0): array
    {
        return $this->taskExecutionModel->getPendingForAdvertiser($userId, $limit, $offset);
    }

    public function markExpired(int $executionId): bool
    {
        return $this->taskExecutionModel->update($executionId, ['status' => 'expired']);
    }

    public function getVerifiedSocialAccounts(int $userId): array
    {
        return $this->socialAccountModel->getVerifiedByUser($userId);
    }
}