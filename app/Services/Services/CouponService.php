<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponRedemption;

class CouponService
{
    private Coupon $couponModel;
    private CouponRedemption $redemptionModel;

    public function __construct(Coupon $coupon, CouponRedemption $redemption)
    {
        $this->couponModel = $coupon;
        $this->redemptionModel = $redemption;
    }

    /**
     * اعتبارسنجی و محاسبه تخفیف
     */
    public function validateAndCalculate(
        string $code,
        float $amount,
        string $currency,
        int $userId,
        string $applicableTo = 'all'
    ): array {
        // 1. یافتن کوپن
        $coupon = $this->couponModel->findByCode($code);
        
        if (!$coupon) {
            return [
                'valid' => false,
                'error' => 'کد تخفیف معتبر نیست'
            ];
        }

        // 2. بررسی فعال بودن
        if (!$coupon->isActive()) {
            return [
                'valid' => false,
                'error' => 'کد تخفیف منقضی شده یا غیرفعال است'
            ];
        }

        // 3. بررسی نوع کاربرد
        if ($coupon->applicable_to !== 'all' && $coupon->applicable_to !== $applicableTo) {
            return [
                'valid' => false,
                'error' => 'این کد تخفیف برای این نوع عملیات قابل استفاده نیست'
            ];
        }

        // 4. بررسی حداقل خرید
        if ($coupon->min_purchase && $amount < $coupon->min_purchase) {
            return [
                'valid' => false,
                'error' => sprintf('مبلغ خرید باید حداقل %s باشد', number_format($coupon->min_purchase))
            ];
        }

        // 5. بررسی مصرف قبلی توسط کاربر
        if ($this->redemptionModel->hasUserUsedCoupon($userId, $coupon->id)) {
            return [
                'valid' => false,
                'error' => 'شما قبلاً از این کد تخفیف استفاده کرده‌اید'
            ];
        }

        // 6. محاسبه تخفیف
        $discount = 0;
        
        if ($coupon->type === 'percent') {
            $discount = ($amount * $coupon->value) / 100;
            
            if ($coupon->max_discount && $discount > $coupon->max_discount) {
                $discount = $coupon->max_discount;
            }
        } else {
            $discount = min($coupon->value, $amount);
        }

        $finalAmount = max(0, $amount - $discount);

        return [
            'valid' => true,
            'coupon_id' => $coupon->id,
            'coupon_code' => $coupon->code,
            'original_amount' => $amount,
            'discount_amount' => round($discount, 2),
            'final_amount' => round($finalAmount, 2),
            'currency' => $currency
        ];
    }

    /**
     * ثبت مصرف کوپن
     */
    public function redeem(
        int $couponId,
        int $userId,
        float $originalAmount,
        float $discountAmount,
        float $finalAmount,
        string $currency,
        string $entityType,
        ?int $entityId = null
    ): bool {
        // ثبت مصرف
        $redemptionId = $this->redemptionModel->create([
            'coupon_id' => $couponId,
            'user_id' => $userId,
            'original_amount' => $originalAmount,
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'currency' => $currency,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => get_client_ip()
        ]);

        if (!$redemptionId) {
            return false;
        }

        // افزایش شمارنده
        return $this->couponModel->incrementUsage($couponId);
    }

    /**
     * دریافت آمار کوپن
     */
    public function getCouponStatistics(int $couponId): array
    {
        $coupon = $this->couponModel->find($couponId);
        $stats = $this->redemptionModel->getCouponStats($couponId);
        $history = $this->redemptionModel->getCouponHistory($couponId, 10);

        return [
            'coupon' => $coupon,
            'stats' => $stats,
            'recent_uses' => $history
        ];
    }

    /**
     * آمار کلی سیستم کوپن
     */
    public function getOverallStatistics(): array
    {
        $overallStats = $this->redemptionModel->getOverallStats();
        $activeCoupons = $this->couponModel->getActiveCoupons();
        $expiredCoupons = $this->couponModel->getExpiredCoupons();
        $todayRedemptions = $this->redemptionModel->getTodayRedemptions();

        return [
            'overall' => $overallStats,
            'active_coupons_count' => count($activeCoupons),
            'expired_coupons_count' => count($expiredCoupons),
            'today_redemptions_count' => count($todayRedemptions)
        ];
    }
}