<?php
/**
 * Rate Limiting Configuration
 * 
 * تنظیمات محدودیت درخواست برای endpoint های مختلف
 * هر endpoint می‌تواند تنظیمات خاص خودش را داشته باشد
 */

return [
    /**
     * تنظیمات پیش‌فرض
     * اگر برای endpoint خاصی تنظیم نشده باشد، این مقادیر استفاده می‌شود
     */
    'default' => [
        'max_attempts' => env('RATE_LIMIT_MAX_ATTEMPTS', 60),
        'decay_minutes' => env('RATE_LIMIT_DECAY_MINUTES', 1),
    ],

    /**
     * Authentication & Security Endpoints
     * محدودیت‌های سختگیرانه برای امنیت
     */
    'auth' => [
        'login' => [
            'max_attempts' => 5,
            'decay_minutes' => 5,
            'message' => 'تعداد تلاش‌های ورود بیش از حد مجاز. لطفاً 5 دقیقه دیگر تلاش کنید.'
        ],
        'register' => [
            'max_attempts' => 3,
            'decay_minutes' => 60,
            'message' => 'تعداد ثبت‌نام‌های شما بیش از حد مجاز است. لطفاً 1 ساعت دیگر تلاش کنید.'
        ],
        'forgot_password' => [
            'max_attempts' => 3,
            'decay_minutes' => 60,
            'message' => 'درخواست‌های بازیابی رمز عبور بیش از حد. لطفاً 1 ساعت دیگر تلاش کنید.'
        ],
        'reset_password' => [
            'max_attempts' => 3,
            'decay_minutes' => 60,
        ],
        'verify_email' => [
            'max_attempts' => 5,
            'decay_minutes' => 60,
        ],
        'resend_verification' => [
            'max_attempts' => 3,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * Financial Operations
     * محدودیت‌های ویژه برای تراکنش‌های مالی
     */
    'financial' => [
        'deposit' => [
            'max_attempts' => 10,
            'decay_minutes' => 60,
            'message' => 'تعداد درخواست واریز بیش از حد. لطفاً 1 ساعت صبر کنید.'
        ],
        'withdrawal' => [
            'max_attempts' => 5,
            'decay_minutes' => 60,
            'message' => 'تعداد درخواست برداشت بیش از حد. لطفاً 1 ساعت صبر کنید.'
        ],
        'transfer' => [
            'max_attempts' => 10,
            'decay_minutes' => 60,
        ],
        'wallet_history' => [
            'max_attempts' => 30,
            'decay_minutes' => 1,
        ],
    ],

    /**
     * File Upload Operations
     * محدودیت برای آپلود فایل
     */
    'upload' => [
        'avatar' => [
            'max_attempts' => 5,
            'decay_minutes' => 60,
            'message' => 'تعداد آپلود تصویر بیش از حد. لطفاً 1 ساعت صبر کنید.'
        ],
        'kyc_document' => [
            'max_attempts' => 3,
            'decay_minutes' => 60,
            'message' => 'تعداد آپلود مدرک بیش از حد. لطفاً 1 ساعت صبر کنید.'
        ],
        'task_file' => [
            'max_attempts' => 20,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * API Endpoints
     * محدودیت‌های API
     */
    'api' => [
        'general' => [
            'max_attempts' => 100,
            'decay_minutes' => 1,
            'message' => 'تعداد درخواست‌های API بیش از حد. لطفاً کمی صبر کنید.'
        ],
        'authenticated' => [
            'max_attempts' => 200,
            'decay_minutes' => 1,
        ],
        'public' => [
            'max_attempts' => 50,
            'decay_minutes' => 1,
        ],
    ],

    /**
     * Task & Execution
     * محدودیت‌های تسک‌ها
     */
    'task' => [
        'create' => [
            'max_attempts' => 10,
            'decay_minutes' => 60,
            'message' => 'تعداد ایجاد تسک بیش از حد. لطفاً 1 ساعت صبر کنید.'
        ],
        'execute' => [
            'max_attempts' => 50,
            'decay_minutes' => 60,
            'message' => 'تعداد اجرای تسک بیش از حد. لطفاً 1 ساعت صبر کنید.'
        ],
        'submit' => [
            'max_attempts' => 30,
            'decay_minutes' => 60,
        ],
        'dispute' => [
            'max_attempts' => 5,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * Social & Communication
     * محدودیت‌های ارتباطی
     */
    'social' => [
        'comment' => [
            'max_attempts' => 20,
            'decay_minutes' => 60,
        ],
        'message' => [
            'max_attempts' => 30,
            'decay_minutes' => 60,
        ],
        'ticket_create' => [
            'max_attempts' => 5,
            'decay_minutes' => 60,
            'message' => 'تعداد ایجاد تیکت بیش از حد. لطفاً 1 ساعت صبر کنید.'
        ],
        'ticket_reply' => [
            'max_attempts' => 20,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * Admin Operations
     * محدودیت‌های ادمین (معمولاً سخت‌گیرتر نیستند)
     */
    'admin' => [
        'login' => [
            'max_attempts' => 3,
            'decay_minutes' => 10,
            'message' => 'تعداد تلاش‌های ورود ادمین بیش از حد. لطفاً 10 دقیقه صبر کنید.'
        ],
        'general' => [
            'max_attempts' => 500,
            'decay_minutes' => 1,
        ],
    ],

    /**
     * Search & Browse
     * محدودیت‌های جستجو
     */
    'search' => [
        'general' => [
            'max_attempts' => 30,
            'decay_minutes' => 1,
        ],
        'advanced' => [
            'max_attempts' => 20,
            'decay_minutes' => 1,
        ],
    ],

    /**
     * Content Creation
     * محدودیت‌های ایجاد محتوا
     */
    'content' => [
        'create' => [
            'max_attempts' => 10,
            'decay_minutes' => 60,
        ],
        'update' => [
            'max_attempts' => 20,
            'decay_minutes' => 60,
        ],
        'delete' => [
            'max_attempts' => 10,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * Reports & Analytics
     * محدودیت‌های گزارش‌گیری
     */
    'reports' => [
        'generate' => [
            'max_attempts' => 5,
            'decay_minutes' => 60,
            'message' => 'تعداد درخواست گزارش بیش از حد. لطفاً 1 ساعت صبر کنید.'
        ],
        'export' => [
            'max_attempts' => 3,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * KYC & Verification
     * محدودیت‌های احراز هویت
     */
    'kyc' => [
        'submit' => [
            'max_attempts' => 3,
            'decay_minutes' => 1440, // 24 ساعت
            'message' => 'تعداد ارسال مدارک احراز هویت بیش از حد. لطفاً 24 ساعت صبر کنید.'
        ],
        'update' => [
            'max_attempts' => 5,
            'decay_minutes' => 1440,
        ],
    ],

    /**
     * Investment Operations
     * محدودیت‌های سرمایه‌گذاری
     */
    'investment' => [
        'create' => [
            'max_attempts' => 10,
            'decay_minutes' => 60,
        ],
        'withdraw' => [
            'max_attempts' => 5,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * Lottery & Games
     * محدودیت‌های قرعه‌کشی
     */
    'lottery' => [
        'participate' => [
            'max_attempts' => 20,
            'decay_minutes' => 60,
        ],
        'vote' => [
            'max_attempts' => 10,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * Referral System
     * محدودیت‌های سیستم دعوت
     */
    'referral' => [
        'check_code' => [
            'max_attempts' => 30,
            'decay_minutes' => 60,
        ],
    ],

    /**
     * Two-Factor Authentication
     * محدودیت‌های 2FA
     */
    'two_factor' => [
        'verify' => [
            'max_attempts' => 5,
            'decay_minutes' => 10,
            'message' => 'تعداد تلاش‌های تایید 2FA بیش از حد. لطفاً 10 دقیقه صبر کنید.'
        ],
        'enable' => [
            'max_attempts' => 5,
            'decay_minutes' => 60,
        ],
        'disable' => [
            'max_attempts' => 3,
            'decay_minutes' => 60,
        ],
    ],
];
