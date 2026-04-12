<?php

namespace App\Services;

use App\Models\ReferralCommission;
use App\Models\User;
use Core\Database;
use App\Services\WalletService;

class ReferralCommissionService
{
    private User $userModel;
    private ReferralCommission $commissionModel;
    private Database $db;
	private WalletService $walletService;

    public function __construct(Database $db, 
        WalletService $walletService,
        ReferralCommission $commissionModel,
        User $userModel){
        $this->db = $db;
        $this->commissionModel = $commissionModel;
        $this->walletService = $walletService;
        $this->userModel = $userModel;
    }

    /**
     * پرداخت کمیسیون برای درآمد زیرمجموعه
     *
     * @param int    $referredId    شناسه زیرمجموعه
     * @param string $sourceType   نوع منبع (task_reward, investment, vip_purchase, story_order)
     * @param int|null $sourceId   شناسه منبع
     * @param float  $sourceAmount مبلغ اصلی درآمد زیرمجموعه
     * @param string $currency     ارز (irt/usdt)
     * @return object|null         رکورد کمیسیون یا null
     */
    public function processCommission(
        int    $referredId,
        string $sourceType,
        ?int   $sourceId,
        float  $sourceAmount,
        string $currency = 'irt'
    ): ?object {
        // بررسی فعال بودن سیستم
        if (!$this->isEnabled()) {
            return null;
        }

        // پیدا کردن معرف
        $userModel = $this->userModel;
        $referred = $userModel->find($referredId);

        if (!$referred || empty($referred->referred_by)) {
            return null;
        }

        $referrerId = (int) $referred->referred_by;
        $referrer = $userModel->find($referrerId);

        if (!$referrer) {
            return null;
        }

        // بررسی وضعیت معرف
        if (isset($referrer->status) && \in_array($referrer->status, ['banned', 'suspended'])) {
            logger('info', 'Commission skipped: referrer banned/suspended', [
                'referrer_id' => $referrerId,
            ]);
            return null;
        }

        // بررسی Silent Blacklist معرف
        if (isset($referrer->is_silently_blacklisted) && $referrer->is_silently_blacklisted) {
            return null;
        }

        // محاسبه درصد کمیسیون
        $percent = $this->getCommissionPercent($sourceType);
        if ($percent <= 0) {
            return null;
        }

        // محاسبه مبلغ
        $commissionAmount = \round($sourceAmount * ($percent / 100), 2);
        if ($commissionAmount <= 0) {
            return null;
        }

        // ساخت idempotency key
        $idempotencyKey = "ref_comm_{$referrerId}_{$referredId}_{$sourceType}_{$sourceId}_{$currency}";

        // بررسی تکراری نبودن
        $existing = $this->commissionModel->findByIdempotencyKey($idempotencyKey);
        if ($existing) {
            logger('warning', 'Duplicate commission prevented', [
                'idempotency_key' => $idempotencyKey,
                'existing_id' => $existing->id,
            ]);
            return $existing;
        }

        try {
            $this->db->beginTransaction();

            // ایجاد رکورد کمیسیون
            $commission = $this->commissionModel->create([
                'referrer_id'       => $referrerId,
                'referred_id'       => $referredId,
                'source_type'       => $sourceType,
                'source_id'         => $sourceId,
                'source_amount'     => $sourceAmount,
                'commission_percent' => $percent,
                'commission_amount' => $commissionAmount,
                'currency'          => $currency,
                'status'            => 'pending',
                'idempotency_key'   => $idempotencyKey,
                'metadata'          => [
                    'source_type_label' => $this->getSourceLabel($sourceType),
                ],
            ]);

            if (!$commission) {
                $this->db->rollBack();
                return null;
            }

            // پرداخت خودکار اگر فعال باشد
            if ($this->isAutoPayEnabled()) {
                $paid = $this->payCommission($commission->id, $referrerId, $commissionAmount, $currency);
                if ($paid) {
                    $this->commissionModel->updateStatus($commission->id, 'paid', $paid);
                } else {
                    // ماندن در وضعیت pending برای بررسی ادمین
                    logger('error', 'Auto-pay commission failed', [
                        'commission_id' => $commission->id,
                    ]);
                }
            }

            // لاگ
            $this->commissionModel->logActivity($referrerId, 'commission_earned', [
                'commission_id' => $commission->id,
                'amount'        => $commissionAmount,
                'currency'      => $currency,
                'source'        => $sourceType,
            ]);

            $this->db->commit();

            logger('info', 'Commission processed', [
                'commission_id' => $commission->id,
                'referrer_id'   => $referrerId,
                'referred_id'   => $referredId,
                'amount'        => $commissionAmount,
                'currency'      => $currency,
            ]);

            // BUG FIX 7: ارسال نوتیفیکیشن کمیسیون معرفی
            try {
                $referredUser = $this->db->query(
                    "SELECT full_name, username FROM users WHERE id = ? LIMIT 1",
                    [$referredId]
                )->fetch(\PDO::FETCH_OBJ);
                $referredName = $referredUser->full_name ?? $referredUser->username ?? "کاربر #{$referredId}";

                $notifSvc = new \App\Services\NotificationService(
                    new \App\Models\Notification(),
                    new \App\Models\NotificationPreference(),
                    $this->db
                );
                $notifSvc->referralEarning($referrerId, $commissionAmount, $referredName);
            } catch (\Throwable $e) {
                logger('warning', 'Referral notification failed: ' . $e->getMessage());
            }

            return $commission;

        } catch (\Exception $e) {
            $this->db->rollBack();
            logger('error', 'Commission processing failed', [
                'error'       => $e->getMessage(),
                'referrer_id' => $referrerId,
                'referred_id' => $referredId,
            ]);
            return null;
        }
    }

    /**
 * پرداخت کمیسیون به کیف پول معرف
 * 
 */
private function payCommission(int $commissionId, int $referrerId, float $amount, string $currency): ?string
{
    try {
        // ✅ اصلاح شد: ترتیب صحیح
        // deposit(userId, amount, currency, metadata)
        $result = $this->walletService->deposit(
            $referrerId,                     // 1. userId
            $amount,                         // 2. amount
            $currency,                       // 3. currency ('irt' یا 'usdt')
            [                                // 4. metadata
                'type'        => 'referral_commission',
                'description' => "کمیسیون معرفی - شماره {$commissionId}",
                'ref_id'      => $commissionId,
                'ref_type'    => 'referral_commission'
            ]
        );

        // ✅ چک صحیح
        if (!$result['success']) {
            throw new \Exception($result['message'] ?? 'Wallet deposit failed');
        }

        return $result['transaction_id'];

    } catch (\Exception $e) {
        logger('error', 'Commission payment to wallet failed', [
            'commission_id' => $commissionId,
            'referrer_id'   => $referrerId,
            'amount'        => $amount,
            'error'         => $e->getMessage(),
        ]);
        return null;
    }
}

    /**
     * بررسی Anti-Farming هنگام ثبت‌نام
     */
    public function checkFarming(int $referrerId, string $ip): array
    {
        $result = [
            'allowed'    => true,
            'risk_level' => 'low',
            'reason'     => null,
        ];

        $maxDaily = (int) setting('referral_max_daily_signups', 5);
        $threshold = (int) setting('referral_farming_threshold', 10);
        $action = setting('referral_farming_action', 'warn');

        // بررسی تعداد ثبت‌نام روزانه معرف
        $todayCount = $this->commissionModel->todaySignupCount($referrerId);

        if ($todayCount >= $threshold) {
            $result['allowed'] = ($action !== 'block' && $action !== 'ban');
            $result['risk_level'] = 'critical';
            $result['reason'] = "Farming detected: {$todayCount} signups today (threshold: {$threshold})";

            $this->commissionModel->logActivity($referrerId, 'farming_detected', [
                'today_count' => $todayCount,
                'threshold'   => $threshold,
                'action'      => $action,
                'ip'          => $ip,
            ]);

            // افزایش fraud_score
            $this->increaseFraudScore($referrerId, 20);

            if ($action === 'ban') {
                $this->banUser($referrerId, 'Referral Farming: ' . $todayCount . ' signups/day');
                $result['allowed'] = false;
            }

            logger('warning', 'Referral farming detected', [
                'referrer_id' => $referrerId,
                'count'       => $todayCount,
                'action'      => $action,
            ]);

        } elseif ($todayCount >= $maxDaily) {
            $result['allowed'] = true;
            $result['risk_level'] = 'medium';
            $result['reason'] = "Daily limit reached: {$todayCount}/{$maxDaily}";

            $this->increaseFraudScore($referrerId, 5);
        }

        // بررسی تعداد ثبت‌نام از یک IP
        $ipCount = $this->commissionModel->todaySignupCountByIp($ip);
        if ($ipCount >= 3) {
            $result['risk_level'] = 'high';
            $result['reason'] = ($result['reason'] ?? '') . " | Same IP: {$ipCount} signups";
            $this->increaseFraudScore($referrerId, 15);
        }

        return $result;
    }

    /**
     * ثبت لاگ ثبت‌نام زیرمجموعه
     */
    public function logSignup(int $referrerId, int $referredId): void
    {
        $this->commissionModel->logActivity($referrerId, 'signup', [
            'referred_id' => $referredId,
            'ip'          => get_client_ip(),
        ]);
    }

    /**
     * پرداخت دسته‌ای کمیسیون‌های pending
     */
    public function batchPay(string $currency = 'irt'): array
    {
        $results = ['success' => 0, 'failed' => 0, 'skipped' => 0];

        $minPayout = $currency === 'usdt'
            ? (float) setting('referral_commission_min_payout_usdt', 1)
            : (float) setting('referral_commission_min_payout', 10000);

        $pendingList = $this->commissionModel->getPendingPayable($currency);

        foreach ($pendingList as $item) {
            if ($item->total_pending < $minPayout) {
                $results['skipped']++;
                continue;
            }

            // پرداخت تمام pending‌های این معرف
            $stmt = $this->db->prepare("
                SELECT id, commission_amount FROM referral_commissions
                WHERE referrer_id = ? AND status = 'pending' AND currency = ?
                ORDER BY created_at ASC
                FOR UPDATE
            ");
            $stmt->execute([$item->referrer_id, $currency]);
            $commissions = $stmt->fetchAll(\PDO::FETCH_OBJ);

            foreach ($commissions as $comm) {
                $txId = $this->payCommission($comm->id, $item->referrer_id, $comm->commission_amount, $currency);

                if ($txId) {
                    $this->commissionModel->updateStatus($comm->id, 'paid', $txId);
                    $results['success']++;
                } else {
                    $results['failed']++;
                }
            }
        }

        logger('info', 'Batch commission payment completed', $results);

        return $results;
    }

    /**
     * لغو کمیسیون (در صورت تقلب)
     */
    public function cancelCommission(int $commissionId, string $reason = ''): bool
    {
        $commission = $this->commissionModel->find($commissionId);
        if (!$commission || $commission->status !== 'pending') {
            return false;
        }

        $updated = $this->commissionModel->updateStatus($commissionId, 'cancelled');

        if ($updated) {
            $this->commissionModel->logActivity($commission->referrer_id, 'commission_cancelled', [
                'commission_id' => $commissionId,
                'reason'        => $reason,
            ]);

            logger('info', 'Commission cancelled', [
                'commission_id' => $commissionId,
                'reason'        => $reason,
            ]);
        }

        return $updated;
    }

    /**
     * دریافت درصد کمیسیون بر اساس نوع
     */
    private function getCommissionPercent(string $sourceType): float
    {
        $key = "referral_commission_{$sourceType}_percent";
        return (float) setting($key, 0);
    }

    /**
     * برچسب فارسی منبع
     */
    public function getSourceLabel(string $sourceType): string
    {
        $labels = [
            'task_reward'  => 'تسک و تبلیغات',
            'investment'   => 'سرمایه‌گذاری',
            'vip_purchase' => 'خرید VIP',
            'story_order'  => 'سفارش استوری',
        ];

        return $labels[$sourceType] ?? $sourceType;
    }

    /**
     * لیست منابع کمیسیون
     */
    public static function sourceTypes(): array
    {
        return [
            'task_reward'  => 'تسک و تبلیغات',
            'investment'   => 'سرمایه‌گذاری',
            'vip_purchase' => 'خرید VIP',
            'story_order'  => 'سفارش استوری',
        ];
    }

    /**
     * بررسی فعال بودن سیستم
     */
    private function isEnabled(): bool
    {
        return (bool) setting('referral_commission_enabled', 1);
    }

    /**
     * بررسی پرداخت خودکار
     */
    private function isAutoPayEnabled(): bool
    {
        return (bool) setting('referral_commission_auto_pay', 1);
    }

    /**
     * افزایش امتیاز تقلب
     */
    private function increaseFraudScore(int $userId, int $points): void
    {
        $stmt = $this->db->prepare("
            UPDATE users SET fraud_score = COALESCE(fraud_score, 0) + ? WHERE id = ?
        ");
        $stmt->execute([$points, $userId]);
    }

    /**
     * بن کاربر
     */
    private function banUser(int $userId, string $reason): void
    {
        $stmt = $this->db->prepare("
            UPDATE users SET status = 'banned', ban_reason = ? WHERE id = ?
        ");
        $stmt->execute([$reason, $userId]);

        logger('warning', 'User banned for referral farming', [
            'user_id' => $userId,
            'reason'  => $reason,
        ]);
    }
}