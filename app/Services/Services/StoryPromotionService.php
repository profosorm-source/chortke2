<?php

namespace App\Services;

use App\Models\InfluencerProfile;
use App\Models\StoryOrder;
use Core\Database;

class StoryPromotionService
{
    private InfluencerProfile $profileModel;
    private StoryOrder $orderModel;
    private Database $db;
    private WalletService $walletService;
    private ReferralCommissionService $referralCommissionService;

    public function __construct(Database $db, 
        WalletService $walletService,
        ReferralCommissionService $referralCommissionService,
        \App\Models\InfluencerProfile $profileModel,
        \App\Models\StoryOrder $orderModel){
        $this->profileModel = $profileModel;
        $this->orderModel = $orderModel;
        $this->db = $db;$this->walletService             = $walletService;
        $this->referralCommissionService = $referralCommissionService;
    }

    /**
     * ثبت پیج اینفلوئنسر
     */
    public function registerInfluencer(int $userId, array $data): array
    {
        if (!setting('story_promotion_enabled', 1)) {
            return ['success' => false, 'message' => 'سیستم تبلیغات استوری غیرفعال است.'];
        }

        $existing = $this->profileModel->findByUserId($userId);
        if ($existing) {
            return ['success' => false, 'message' => 'شما قبلاً یک پیج ثبت کرده‌اید.'];
        }

        $minFollowers = (int) setting('story_min_followers', 1000);
        if (($data['follower_count'] ?? 0) < $minFollowers) {
            return ['success' => false, 'message' => "حداقل فالوور مورد نیاز: {$minFollowers}"];
        }

        $profile = $this->profileModel->create([
            'user_id' => $userId,
            'username' => $data['username'],
            'page_url' => $data['page_url'],
            'profile_image' => $data['profile_image'] ?? null,
            'follower_count' => (int) ($data['follower_count'] ?? 0),
            'engagement_rate' => (float) ($data['engagement_rate'] ?? 0),
            'category' => $data['category'] ?? null,
            'bio' => $data['bio'] ?? null,
            'story_price_24h' => (float) ($data['story_price_24h'] ?? 0),
            'post_price_24h' => (float) ($data['post_price_24h'] ?? 0),
            'post_price_48h' => (float) ($data['post_price_48h'] ?? 0),
            'post_price_72h' => (float) ($data['post_price_72h'] ?? 0),
            'currency' => setting('currency_mode', 'irt'),
            'status' => 'pending',
        ]);

        if (!$profile) return ['success' => false, 'message' => 'خطا در ثبت پیج.'];

        logger('info', 'Influencer profile registered', ['profile_id' => $profile->id, 'user_id' => $userId]);
        return ['success' => true, 'message' => 'پیج با موفقیت ثبت شد و در انتظار تأیید ادمین است.', 'profile' => $profile];
    }

    /**
     * ثبت سفارش
     */
    public function createOrder(int $customerId, int $influencerId, array $data): array
    {
        if (!setting('story_promotion_enabled', 1)) {
            return ['success' => false, 'message' => 'سیستم غیرفعال است.'];
        }

        $profile = $this->profileModel->find($influencerId);
        if (!$profile || $profile->status !== 'verified' || !$profile->is_active) {
            return ['success' => false, 'message' => 'اینفلوئنسر فعال نیست.'];
        }

        if ((int) $profile->user_id === $customerId) {
            return ['success' => false, 'message' => 'نمی‌توانید برای پیج خودتان سفارش دهید.'];
        }

        $orderType = $data['order_type'] ?? 'story';
        $duration = (int) ($data['duration_hours'] ?? 24);

        // محاسبه قیمت
        $price = $this->calculatePrice($profile, $orderType, $duration);
        if ($price <= 0) {
            return ['success' => false, 'message' => 'قیمت نامعتبر.'];
        }

        $feePercent = (float) setting('story_site_fee_percent', 15);
        $feeAmount = \round($price * ($feePercent / 100), 2);
        $influencerEarning = $price - $feeAmount;
        $totalCharge = $price;

        $verificationCode = ($this->orderModel)->generateVerificationCode();
        $idempotencyKey = "story_order_{$customerId}_{$influencerId}_" . \time();

        try {
            $this->db->beginTransaction();

            // کسر از کیف پول
            $txId = $this->walletService->withdraw(
                $customerId, $totalCharge, $profile->currency, 'transfer',
                "سفارش {$orderType} - @{$profile->username}",
                "story_pay_{$customerId}_{$influencerId}_" . \time()
            );

            if (!$txId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'موجودی کافی نیست.'];
            }

            $order = $this->orderModel->create([
                'customer_id' => $customerId,
                'influencer_id' => $influencerId,
                'influencer_user_id' => (int) $profile->user_id,
                'order_type' => $orderType,
                'duration_hours' => $duration,
                'media_path' => $data['media_path'] ?? null,
                'caption' => $data['caption'] ?? null,
                'link' => $data['link'] ?? null,
                'preferred_publish_time' => $data['preferred_publish_time'] ?? null,
                'verification_code' => $verificationCode,
                'price' => $price,
                'currency' => $profile->currency,
                'site_fee_percent' => $feePercent,
                'site_fee_amount' => $feeAmount,
                'influencer_earning' => $influencerEarning,
                'status' => 'paid',
                'payment_transaction_id' => $txId,
                'idempotency_key' => $idempotencyKey,
            ]);

            if (!$order) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ثبت سفارش.'];
            }

            // کمیسیون معرفی
            $this->referralCommissionService->processCommission($customerId, 'story_order', (int) $order->id, $price, $profile->currency);

            // بروزرسانی آمار اینفلوئنسر
            $this->profileModel->update($influencerId, [
                'total_orders' => $profile->total_orders + 1,
            ]);

            $this->db->commit();

            logger('info', 'Story order created', [
                'order_id' => $order->id, 'customer_id' => $customerId,
                'influencer_id' => $influencerId, 'price' => $price,
            ]);

            return ['success' => true, 'message' => 'سفارش با موفقیت ثبت شد.', 'order' => $order, 'code' => $verificationCode];

        } catch (\Exception $e) {
            $this->db->rollBack();
            logger('error', 'Story order failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در ثبت سفارش.'];
        }
    }

    /**
     * پذیرش/رد سفارش توسط اینفلوئنسر
     */
    public function respondToOrder(int $orderId, int $influencerUserId, string $decision, ?string $reason = null): array
    {
        $order = $this->orderModel->find($orderId);
        if (!$order || (int) $order->influencer_user_id !== $influencerUserId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if ($order->status !== 'paid') {
            return ['success' => false, 'message' => 'وضعیت سفارش اجازه این عملیات را نمی‌دهد.'];
        }

        if ($decision === 'accept') {
            $this->orderModel->update($orderId, ['status' => 'accepted']);
            return ['success' => true, 'message' => 'سفارش پذیرفته شد. لطفاً در زمان مقرر منتشر کنید.'];
        } else {
            $this->orderModel->update($orderId, [
                'status' => 'rejected_by_influencer',
                'rejection_reason' => $reason ?? 'رد توسط اینفلوئنسر',
            ]);
            // بازگشت وجه
            $this->refundCustomer($order);
            return ['success' => true, 'message' => 'سفارش رد شد و مبلغ به تبلیغ‌دهنده بازگشت.'];
        }
    }

    /**
     * ارسال مدرک انتشار
     */
    public function submitProof(int $orderId, int $influencerUserId, array $proofData): array
    {
        $order = $this->orderModel->find($orderId);
        if (!$order || (int) $order->influencer_user_id !== $influencerUserId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        if (!\in_array($order->status, ['accepted', 'published'])) {
            return ['success' => false, 'message' => 'وضعیت سفارش مناسب نیست.'];
        }

        $updateData = [
            'status' => 'proof_submitted',
            'proof_submitted_at' => \date('Y-m-d H:i:s'),
        ];
        if (!empty($proofData['proof_screenshot'])) $updateData['proof_screenshot'] = $proofData['proof_screenshot'];
        if (!empty($proofData['proof_video'])) $updateData['proof_video'] = $proofData['proof_video'];
        if (!empty($proofData['actual_publish_time'])) $updateData['actual_publish_time'] = $proofData['actual_publish_time'];

        $this->orderModel->update($orderId, $updateData);

        logger('info', 'Story proof submitted', ['order_id' => $orderId]);
        return ['success' => true, 'message' => 'مدرک ارسال شد. منتظر بررسی باشید.'];
    }

    /**
     * تأیید/رد مدرک (ادمین)
     */
    public function verifyProof(int $orderId, int $adminId, string $decision, ?string $reason = null): array
    {
        $order = $this->orderModel->find($orderId);
        if (!$order || $order->status !== 'proof_submitted') {
            return ['success' => false, 'message' => 'وضعیت نامعتبر.'];
        }

        try {
            $this->db->beginTransaction();

            if ($decision === 'approve') {
                // پرداخت به اینفلوئنسر
                $payoutTxId = $this->walletService->deposit(
                    (int) $order->influencer_user_id,
                    (float) $order->influencer_earning,
                    $order->currency,
                    'transfer',
                    "درآمد سفارش #{$orderId}",
                    "story_payout_{$orderId}"
                );

                $this->orderModel->update($orderId, [
                    'status' => 'completed',
                    'payout_transaction_id' => $payoutTxId,
                    'reviewed_by' => $adminId,
                    'reviewed_at' => \date('Y-m-d H:i:s'),
                ]);

                // بروزرسانی آمار
                $profile = $this->profileModel->find((int) $order->influencer_id);
                if ($profile) {
                    $this->profileModel->update($profile->id, [
                        'completed_orders' => $profile->completed_orders + 1,
                    ]);
                }

                // حذف فایل مدرک
                $this->cleanupProofFiles($order);

                $this->db->commit();
                return ['success' => true, 'message' => 'مدرک تأیید و مبلغ به اینفلوئنسر پرداخت شد.'];

            } else {
                $this->orderModel->update($orderId, [
                    'status' => 'rejected',
                    'rejection_reason' => $reason ?? 'مدرک ناکافی',
                    'reviewed_by' => $adminId,
                    'reviewed_at' => \date('Y-m-d H:i:s'),
                ]);

                $this->db->commit();
                return ['success' => true, 'message' => 'مدرک رد شد.'];
            }

        } catch (\Exception $e) {
            $this->db->rollBack();
            logger('error', 'Proof verification failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در بررسی مدرک.'];
        }
    }

    /**
     * بازگشت وجه
     */
    private function refundCustomer(object $order): void
    {
        try {
            $this->walletService->deposit(
                (int) $order->customer_id,
                (float) $order->price,
                $order->currency,
                'refund',
                "بازگشت سفارش #{$order->id}",
                "story_refund_{$order->id}"
            );
            $this->orderModel->update($order->id, ['status' => 'refunded']);
        } catch (\Exception $e) {
            logger('error', 'Story refund failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * محاسبه قیمت
     */
    private function calculatePrice(object $profile, string $orderType, int $duration): float
    {
        if ($orderType === 'story') {
            return (float) $profile->story_price_24h;
        }
        return match ($duration) {
            48 => (float) $profile->post_price_48h,
            72 => (float) $profile->post_price_72h,
            default => (float) $profile->post_price_24h,
        };
    }

    /**
     * حذف فایل‌های مدرک
     */
    private function cleanupProofFiles(object $order): void
    {
        $basePath = __DIR__ . '/../../';
        if ($order->proof_screenshot) {
            $path = $basePath . $order->proof_screenshot;
            if (\file_exists($path)) \unlink($path);
        }
        if ($order->proof_video) {
            $path = $basePath . $order->proof_video;
            if (\file_exists($path)) \unlink($path);
        }
        if ($order->media_path) {
            $path = $basePath . $order->media_path;
            if (\file_exists($path)) \unlink($path);
        }
    }

    /**
     * CronJob: تأیید خودکار مدرک‌های بررسی‌نشده
     */
    public function autoCompleteOrders(): int
    {
        $hours = (int) setting('story_auto_complete_hours', 72);
        $stmt = $this->db->prepare("
            SELECT id FROM story_orders
            WHERE status = 'proof_submitted'
            AND proof_submitted_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$hours]);
        $orders = $stmt->fetchAll(\PDO::FETCH_OBJ);
        $count = 0;
        foreach ($orders as $o) {
            $result = $this->verifyProof($o->id, 0, 'approve');
            if ($result['success']) $count++;
        }
        if ($count > 0) logger('info', 'Auto-completed story orders', ['count' => $count]);
        return $count;
    }

    /**
     * CronJob: حذف فایل‌های قدیمی
     */
    public function cleanupOldFiles(int $days = 3): int
    {
        $stmt = $this->db->prepare("
            SELECT id, proof_screenshot, proof_video, media_path FROM story_orders
            WHERE status IN ('completed','refunded','cancelled')
            AND updated_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
            AND (proof_screenshot IS NOT NULL OR proof_video IS NOT NULL OR media_path IS NOT NULL)
        ");
        $stmt->execute([$days]);
        $orders = $stmt->fetchAll(\PDO::FETCH_OBJ);
        $count = 0;
        foreach ($orders as $o) {
            $this->cleanupProofFiles($o);
            $this->orderModel->update($o->id, [
                'proof_screenshot' => null, 'proof_video' => null, 'media_path' => null,
            ]);
            $count++;
        }
        return $count;
    }
}