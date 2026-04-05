<?php

namespace App\Services;

class CurrencyService
{
    public static function getCurrentMode(): string
    {
        // irt/usdt مطابق استاندارد پروژه
        $mode = (string) setting('currency_mode', 'irt');
        $mode = \strtolower(\trim($mode));
        return \in_array($mode, ['irt','usdt'], true) ? $mode : 'irt';
    }

    public static function isIRT(): bool
    {
        return self::getCurrentMode() === 'irt';
    }

    public static function isUSDT(): bool
    {
        return self::getCurrentMode() === 'usdt';
    }
    
    /**
     * دریافت نماد ارز
     */
    public static function getCurrencySymbol(): string
    {
        return self::isIRT() ? 'تومان' : 'USDT';
    }
    
    /**
     * فرمت کردن مبلغ
     */
    public static function formatAmount(float $amount): string
    {
        if (self::isIRT()) {
            return number_format($amount, 0, '.', ',') . ' تومان';
        } else {
            return number_format($amount, 2, '.', ',') . ' USDT';
        }
    }
    
    /**
     * آیا این قسمت باید USDT باشد؟
     * (سرمایه‌گذاری همیشه USDT است حتی در حالت IRT)
     */
    public static function isInvestmentSection(): bool
    {
        // چک کنیم آیا در مسیر سرمایه‌گذاری هستیم
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return strpos($uri, '/investment') !== false;
    }
    
    /**
     * دریافت ارز برای قسمت فعلی
     */
    public static function getSectionCurrency(): string
    {
        if (self::isInvestmentSection()) {
            return 'USDT'; // سرمایه‌گذاری همیشه USDT
        }
        
        return self::getCurrentMode();
    }
}