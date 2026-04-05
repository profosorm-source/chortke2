<?php

namespace App\Services;

use Core\Database;
use App\Models\Setting;

/**
 * WithdrawalLimitService - محدودیت هوشمند برداشت
 *
 * سطوح محدودیت بر اساس وضعیت کاربر:
 *
 * | وضعیت         | روزانه | هفتگی  | ماهانه   | حداکثر یکجا  |
 * |---------------|--------|--------|----------|---------------|
 * | بدون KYC      | ۰      | ۰      | ۰        | ۰ (ممنوع)    |
 * | KYC + Silver  | ۱      | ۳      | ۱۰       | min_setting   |
 * | KYC + Gold    | ۳      | ۱۰     | ۳۰       | ×۲            |
 * | KYC + VIP     | ۵      | ۲۰     | ۶۰       | ×۵            |
 * | KYC + Admin   | ∞      | ∞      | ∞        | ∞             |
 */
class WithdrawalLimitService
{
    private Database $db;
    private Setting $settings;

    /** تعریف پروفایل‌های محدودیت */
    private const PROFILES = [
        'no_kyc'        => ['daily'=>0,  'weekly'=>0,   'monthly'=>0,   'multiplier'=>0],
        'silver_kyc'    => ['daily'=>1,  'weekly'=>3,   'monthly'=>10,  'multiplier'=>1.0],
        'gold_kyc'      => ['daily'=>3,  'weekly'=>10,  'monthly'=>30,  'multiplier'=>2.0],
        'vip_kyc'       => ['daily'=>5,  'weekly'=>20,  'monthly'=>60,  'multiplier'=>5.0],
        'admin'         => ['daily'=>999,'weekly'=>9999,'monthly'=>99999,'multiplier'=>100.0],
    ];

    public function __construct(Database $db,
        \App\Models\Setting $settings){
        $this->db = $db;
        $this->settings = $settings;
    }

    /**
     * بررسی کامل مجاز بودن برداشت
     *
     * @return array ['allowed'=>bool, 'reason'=>string, 'limits'=>array]
     */
    public function check(int $userId, float $amount, string $currency): array
    {
        $user    = $this->db->fetch("SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (!$user) {
            return ['allowed' => false, 'reason' => 'کاربر یافت نشد', 'limits' => []];
        }

        $profile = $this->resolveProfile($user);
        $limits  = $this->getLimits($currency, $profile);

        // ── محدودیت تعداد ─────────────────────────────────────
        if ($limits['daily_count'] === 0) {
            return [
                'allowed' => false,
                'reason'  => 'برای برداشت باید ابتدا احراز هویت (KYC) را تکمیل کنید',
                'limits'  => $limits,
            ];
        }

        // بررسی تعداد روزانه
        $todayCount = $this->getWithdrawalCount($userId, 'day');
        if ($todayCount >= $limits['daily_count']) {
            return [
                'allowed' => false,
                'reason'  => "امروز به سقف برداشت روزانه ({$limits['daily_count']} بار) رسیده‌اید",
                'limits'  => $limits,
            ];
        }

        // بررسی تعداد هفتگی
        $weekCount = $this->getWithdrawalCount($userId, 'week');
        if ($weekCount >= $limits['weekly_count']) {
            return [
                'allowed' => false,
                'reason'  => "این هفته به سقف برداشت هفتگی ({$limits['weekly_count']} بار) رسیده‌اید",
                'limits'  => $limits,
            ];
        }

        // بررسی تعداد ماهانه
        $monthCount = $this->getWithdrawalCount($userId, 'month');
        if ($monthCount >= $limits['monthly_count']) {
            return [
                'allowed' => false,
                'reason'  => "این ماه به سقف برداشت ماهانه ({$limits['monthly_count']} بار) رسیده‌اید",
                'limits'  => $limits,
            ];
        }

        // ── محدودیت مبلغ ──────────────────────────────────────
        if ($amount > $limits['max_amount']) {
            $formatted = number_format($limits['max_amount']);
            return [
                'allowed' => false,
                'reason'  => "مبلغ بیشتر از سقف مجاز ({$formatted} {$currency}) است",
                'limits'  => $limits,
            ];
        }

        if ($amount < $limits['min_amount']) {
            $formatted = number_format($limits['min_amount']);
            return [
                'allowed' => false,
                'reason'  => "مبلغ کمتر از حداقل برداشت ({$formatted} {$currency}) است",
                'limits'  => $limits,
            ];
        }

        return [
            'allowed'    => true,
            'reason'     => '',
            'limits'     => $limits,
            'remaining'  => [
                'daily'   => $limits['daily_count'] - $todayCount,
                'weekly'  => $limits['weekly_count'] - $weekCount,
                'monthly' => $limits['monthly_count'] - $monthCount,
            ],
        ];
    }

    /**
     * دریافت اطلاعات محدودیت برای نمایش به کاربر
     */
    public function getLimitsForUser(int $userId, string $currency): array
    {
        $user    = $this->db->fetch("SELECT * FROM users WHERE id = ? LIMIT 1", [$userId]);
        $profile = $this->resolveProfile($user);
        $limits  = $this->getLimits($currency, $profile);

        return array_merge($limits, [
            'used_today'  => $this->getWithdrawalCount($userId, 'day'),
            'used_week'   => $this->getWithdrawalCount($userId, 'week'),
            'used_month'  => $this->getWithdrawalCount($userId, 'month'),
            'profile'     => $profile,
        ]);
    }

    // ─────────────────────────────────────────────────────────
    //  private helpers
    // ─────────────────────────────────────────────────────────

    private function resolveProfile(object $user): string
    {
        if (isset($user->is_admin) && $user->is_admin) {
            return 'admin';
        }
        if (($user->kyc_status ?? '') !== 'verified') {
            return 'no_kyc';
        }
        return match($user->tier_level ?? 'silver') {
            'gold'  => 'gold_kyc',
            'vip'   => 'vip_kyc',
            default => 'silver_kyc',
        };
    }

    private function getLimits(string $currency, string $profile): array
    {
        $p   = self::PROFILES[$profile];
        $cur = strtolower($currency);

        $baseMin = (float)$this->settings->get("min_withdrawal_{$cur}", $cur === 'irt' ? 50000 : 10);
        $baseMax = (float)$this->settings->get("max_withdrawal_{$cur}", $cur === 'irt' ? 10000000 : 1000);

        return [
            'daily_count'   => $p['daily'],
            'weekly_count'  => $p['weekly'],
            'monthly_count' => $p['monthly'],
            'min_amount'    => $baseMin,
            'max_amount'    => $p['multiplier'] > 0 ? ($baseMax * $p['multiplier']) : 0,
            'currency'      => strtoupper($currency),
            'profile_label' => $this->profileLabel($profile),
        ];
    }

    private function getWithdrawalCount(int $userId, string $period): int
    {
        $condition = match($period) {
            'day'   => 'DATE(created_at) = CURDATE()',
            'week'  => 'YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1)',
            'month' => 'YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW())',
            default => '1=0',
        };

        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM withdrawals
             WHERE user_id = ? AND status NOT IN ('rejected','cancelled') AND {$condition}",
            [$userId]
        );
    }

    private function profileLabel(string $profile): string
    {
        return match($profile) {
            'no_kyc'     => 'بدون احراز هویت',
            'silver_kyc' => 'Silver (KYC تأیید شده)',
            'gold_kyc'   => 'Gold (KYC تأیید شده)',
            'vip_kyc'    => 'VIP (KYC تأیید شده)',
            'admin'      => 'ادمین',
            default      => $profile,
        };
    }
}
