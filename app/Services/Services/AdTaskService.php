<?php
// app/Services/AdvertisementService.php

namespace App\Services;

use App\Models\Advertisement;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Core\Database;

class AdTaskService
{
    private \App\Services\CouponService $couponService;
    private \App\Models\Wallet $walletModel;
    private \App\Models\User $userModel;
    private \App\Models\Notification $notificationModel;
    private Database $db;
    private WalletService $walletService;
    private $advertisementModel;
    private $taskExecutionModel;

    public function __construct(Database $db, 
        WalletService $walletService,
        \App\Models\Advertisement $advertisementModel,
        \App\Models\TaskExecution $taskExecutionModel,
        \App\Models\Notification $notificationModel,
        \App\Models\User $userModel,
        \App\Models\Wallet $walletModel,
        \App\Services\CouponService $couponService){
        $this->db = $db;
        $this->walletService      = $walletService;
        $this->advertisementModel = $advertisementModel;
        $this->taskExecutionModel = $taskExecutionModel;
        $this->notificationModel = $notificationModel;
        $this->userModel = $userModel;
        $this->walletModel = $walletModel;
        $this->couponService = $couponService;
    }

    /**
     * ایجاد تبلیغ جدید
     */
    public function create(int $advertiserId, array $data): array
    {
        $user = ($this->userModel)->find($advertiserId);
        if (!$user) {
            return ['success' => false, 'message' => 'کاربر یافت نشد.'];
        }

        // تعیین ارز
        $currency = $this->getCurrency();

        // محاسبه بودجه کل
        $pricePerTask = (float) $data['price_per_task'];
        $totalCount = (int) $data['total_count'];

        if ($pricePerTask <= 0) {
            return ['success' => false, 'message' => 'قیمت هر تسک باید بیشتر از صفر باشد.'];
        }
        if ($totalCount < 1) {
            return ['success' => false, 'message' => 'تعداد باید حداقل ۱ باشد.'];
        }

        // محاسبه کمیسیون و مالیات
        $siteCommissionPercent = (float) (setting('ad_commission_percent') ?? 10);
        $taxPercent = (float) (setting('ad_tax_percent') ?? 0);

        $baseBudget = $pricePerTask * $totalCount;
        $commissionAmount = $baseBudget * ($siteCommissionPercent / 100);
        $taxAmount = $baseBudget * ($taxPercent / 100);
        $totalBudget = $baseBudget + $commissionAmount + $taxAmount;

        // بررسی موجودی کیف پول
        $wallet = ($this->walletModel)->findByUserId($advertiserId);
        if (!$wallet) {
            return ['success' => false, 'message' => 'کیف پول یافت نشد.'];
        }

        $balanceField = ($currency === 'usdt') ? 'balance_usdt' : 'balance_irt';
        if ($wallet->$balanceField < $totalBudget) {
            $currencyLabel = ($currency === 'usdt') ? 'تتر' : 'تومان';
            return [
                'success' => false,
                'message' => "موجودی کیف پول شما کافی نیست. مبلغ مورد نیاز: " . \number_format($totalBudget) . " {$currencyLabel}",
            ];
        }

        // اعمال کوپن (اگر وجود دارد)
        $couponDiscount = 0;
        if (!empty($data['coupon_code'])) {
            $couponResult = $this->applyCoupon($data['coupon_code'], $advertiserId, $totalBudget, $currency);
            if ($couponResult['success']) {
                $couponDiscount = $couponResult['discount'];
                $totalBudget -= $couponDiscount;
            }
        }

        try {
            $this->db->beginTransaction();

            // کسر از کیف پول تبلیغ‌دهنده
            $withdrawResult = $this->walletService->withdraw(
                $advertiserId,
                $totalBudget,
                $currency,
                'ad_budget',
                'بودجه تبلیغ: ' . ($data['title'] ?? 'بدون عنوان'),
                null,
                'ad_create_' . str_random(16)
            );

            if (!$withdrawResult['success']) {
                $this->db->rollBack();
                return ['success' => false, 'message' => $withdrawResult['message']];
            }

            // ایجاد تبلیغ
            $ad = $this->advertisementModel->create([
                'advertiser_id'          => $advertiserId,
                'platform'               => $data['platform'],
                'task_type'              => $data['task_type'],
                'target_url'             => $data['target_url'],
                'target_username'        => $data['target_username'] ?? null,
                'title'                  => $data['title'],
                'description'            => $data['description'] ?? null,
                'sample_image'           => $data['sample_image'] ?? null,
                'currency'               => $currency,
                'price_per_task'         => $pricePerTask,
                'total_budget'           => $totalBudget,
                'site_commission_percent' => $siteCommissionPercent,
                'tax_percent'            => $taxPercent,
                'total_count'            => $totalCount,
                'restrictions'           => $data['restrictions'] ?? null,
                'start_date'             => $data['start_date'] ?? null,
                'end_date'               => $data['end_date'] ?? null,
                'created_by'             => $advertiserId,
            ]);

            if (!$ad) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ایجاد تبلیغ.'];
            }

            $this->db->commit();

            logger('advertisement', "User {$advertiserId} created ad #{$ad->id}: {$data['title']} | Budget: {$totalBudget} {$currency}");

            return [
                'success'       => true,
                'message'       => 'تبلیغ شما با موفقیت ثبت شد و در انتظار بررسی قرار گرفت.',
                'advertisement' => $ad,
                'total_budget'  => $totalBudget,
                'discount'      => $couponDiscount,
            ];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            logger('advertisement_error', $e->getMessage());
            return ['success' => false, 'message' => 'خطای سیستمی رخ داد. لطفاً دوباره تلاش کنید.'];
        }
    }

    /**
     * تایید تبلیغ توسط ادمین
     */
    public function approve(int $adId, int $adminId): array
    {
        $ad = $this->advertisementModel->find($adId);
        if (!$ad) {
            return ['success' => false, 'message' => 'تبلیغ یافت نشد.'];
        }

        if ($ad->status !== 'pending') {
            return ['success' => false, 'message' => 'فقط تبلیغات در انتظار قابل تایید هستند.'];
        }

        $result = $this->advertisementModel->update($adId, [
            'status'      => 'active',
            'approved_by' => $adminId,
            'approved_at' => \date('Y-m-d H:i:s'),
        ]);

        if (!$result) {
            return ['success' => false, 'message' => 'خطا در تایید تبلیغ.'];
        }

        logger('advertisement', "Admin {$adminId} approved ad #{$adId}");

        $this->notifyUser($ad->advertiser_id, 'تبلیغ شما تایید شد',
            "تبلیغ «{$ad->title}» تایید و فعال شد.", 'success');

        return ['success' => true, 'message' => 'تبلیغ تایید و فعال شد.'];
    }

    /**
     * رد تبلیغ توسط ادمین
     */
    public function reject(int $adId, int $adminId, string $reason): array
    {
        $ad = $this->advertisementModel->find($adId);
        if (!$ad) {
            return ['success' => false, 'message' => 'تبلیغ یافت نشد.'];
        }

        if ($ad->status !== 'pending') {
            return ['success' => false, 'message' => 'فقط تبلیغات در انتظار قابل رد هستند.'];
        }

        try {
            $this->db->beginTransaction();

            // بازگشت بودجه به کیف پول
            $refundResult = $this->walletService->deposit(
                $ad->advertiser_id,
                $ad->total_budget,
                $ad->currency,
                'ad_refund',
                'بازگشت بودجه تبلیغ رد‌شده: ' . $ad->title,
                null,
                'ad_refund_' . $adId . '_' . str_random(8)
            );

            if (!$refundResult['success']) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در بازگشت بودجه.'];
            }

            $this->advertisementModel->update($adId, [
                'status'           => 'rejected',
                'rejection_reason' => $reason,
            ]);

            $this->db->commit();

            logger('advertisement', "Admin {$adminId} rejected ad #{$adId}: {$reason}");

            $this->notifyUser($ad->advertiser_id, 'تبلیغ شما رد شد',
                "تبلیغ «{$ad->title}» رد شد. دلیل: {$reason}\nبودجه به کیف پول شما بازگشت داده شد.",
                'danger');

            return ['success' => true, 'message' => 'تبلیغ رد شد و بودجه بازگشت داده شد.'];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            logger('advertisement_error', $e->getMessage());
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }
    }

    /**
     * توقف تبلیغ توسط تبلیغ‌دهنده
     */
    public function pause(int $adId, int $userId): array
    {
        $ad = $this->advertisementModel->find($adId);
        if (!$ad || $ad->advertiser_id !== $userId) {
            return ['success' => false, 'message' => 'تبلیغ یافت نشد.'];
        }

        if ($ad->status !== 'active') {
            return ['success' => false, 'message' => 'فقط تبلیغات فعال قابل توقف هستند.'];
        }

        $this->advertisementModel->update($adId, ['status' => 'paused']);

        logger('advertisement', "User {$userId} paused ad #{$adId}");

        return ['success' => true, 'message' => 'تبلیغ متوقف شد.'];
    }

    /**
     * از سرگیری تبلیغ
     */
    public function resume(int $adId, int $userId): array
    {
        $ad = $this->advertisementModel->find($adId);
        if (!$ad || $ad->advertiser_id !== $userId) {
            return ['success' => false, 'message' => 'تبلیغ یافت نشد.'];
        }

        if ($ad->status !== 'paused') {
            return ['success' => false, 'message' => 'فقط تبلیغات متوقف قابل ازسرگیری هستند.'];
        }

        $this->advertisementModel->update($adId, ['status' => 'active']);

        logger('advertisement', "User {$userId} resumed ad #{$adId}");

        return ['success' => true, 'message' => 'تبلیغ دوباره فعال شد.'];
    }

    /**
     * لغو تبلیغ + بازگشت بودجه باقیمانده
     */
    public function cancel(int $adId, int $userId): array
    {
        $ad = $this->advertisementModel->find($adId);
        if (!$ad || $ad->advertiser_id !== $userId) {
            return ['success' => false, 'message' => 'تبلیغ یافت نشد.'];
        }

        if (!\in_array($ad->status, ['active', 'paused', 'pending'])) {
            return ['success' => false, 'message' => 'این تبلیغ قابل لغو نیست.'];
        }

        try {
            $this->db->beginTransaction();

            // بازگشت بودجه باقیمانده
            if ($ad->remaining_budget > 0) {
                $refundResult = $this->walletService->deposit(
                    $ad->advertiser_id,
                    $ad->remaining_budget,
                    $ad->currency,
                    'ad_refund',
                    'بازگشت بودجه باقیمانده تبلیغ لغو‌شده: ' . $ad->title,
                    null,
                    'ad_cancel_' . $adId . '_' . str_random(8)
                );

                if (!$refundResult['success']) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'خطا در بازگشت بودجه.'];
                }
            }

            $this->advertisementModel->update($adId, [
                'status'           => 'cancelled',
                'remaining_budget' => 0,
            ]);

            $this->db->commit();

            logger('advertisement', "User {$userId} cancelled ad #{$adId} | Refund: {$ad->remaining_budget} {$ad->currency}");

            return [
                'success' => true,
                'message' => 'تبلیغ لغو شد و بودجه باقیمانده به کیف پول شما بازگشت داده شد.',
                'refunded' => $ad->remaining_budget,
            ];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            logger('advertisement_error', $e->getMessage());
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }
    }

    /**
     * دریافت ارز فعال سیستم
     */
    private function getCurrency(): string
    {
        $mode = setting('currency_mode') ?? 'irt';
        return ($mode === 'usdt') ? 'usdt' : 'irt';
    }

    /**
     * اعمال کوپن
     */
    private function applyCoupon(string $code, int $userId, float $amount, string $currency): array
    {
        try {
            if (\class_exists(\App\Services\CouponService::class)) {
                $couponService = $this->couponService;
                return $couponService->validateAndCalculate($code, $userId, $amount, 'tasks', $currency);
            }
        } catch (\Throwable $e) {
            logger('coupon_error', $e->getMessage());
        }

        return ['success' => false, 'discount' => 0];
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
        return $this->advertisementModel->getAll($filters, $limit, $offset);
    }

    public function countAll(array $filters = []): int
    {
        return $this->advertisementModel->countAll($filters);
    }

    public function getStats(): object
    {
        return $this->advertisementModel->getStats();
    }

    public function find(int $id): ?object
    {
        return $this->advertisementModel->find($id);
    }

    public function getByAdvertiser(int $userId, int $limit = 50, int $offset = 0): array
    {
        return $this->advertisementModel->getByAdvertiser($userId, $limit, $offset);
    }

    public function countByAdvertiser(int $userId): int
    {
        return $this->advertisementModel->countByAdvertiser($userId);
    }

    public function getActiveForExecutor(int $userId, int $limit = 30): array
    {
        return $this->advertisementModel->getActiveForExecutor($userId, $limit);
    }
}