<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class HomeController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        // ═══ داده‌های تستی (بعداً از دیتابیس خوانده می‌شوند) ═══

        // آمار زنده
        $stats = (object)[
            'users'        => 12583,
            'tasks'        => 45720,
            'transactions' => 8234,
            'winners'      => 3150,
        ];

        // بنرهای تبلیغاتی
        $banners = [
            (object)[
                'id'          => 1,
                'title'       => 'فروشگاه اینترنتی دیجی‌استایل',
                'description' => 'بزرگترین فروشگاه اینترنتی پوشاک با تخفیف‌های ویژه تا ۷۰ درصد! ارسال رایگان به سراسر ایران. همین الان از تخفیف‌های باورنکردنی بهره‌مند شوید.',
                'image_path'  => '',
                'link'        => 'https://example.com/shop1',
                'price'       => 0,
                'active'      => 1,
            ],
            (object)[
                'id'          => 2,
                'title'       => 'آکادمی آموزش برنامه‌نویسی',
                'description' => 'یادگیری برنامه‌نویسی از صفر تا صد با بهترین اساتید ایران. دوره‌های PHP، JavaScript، Python و React با گواهینامه معتبر.',
                'image_path'  => '',
                'link'        => 'https://example.com/academy',
                'price'       => 50000,
                'active'      => 1,
            ],
            (object)[
                'id'          => 3,
                'title'       => 'اپلیکیشن مدیریت مالی هوشمند',
                'description' => 'با اپلیکیشن ما درآمد و هزینه‌های خود را هوشمندانه مدیریت کنید. رایگان دانلود کنید و از امکانات حرفه‌ای بهره‌مند شوید.',
                'image_path'  => '',
                'link'        => 'https://example.com/app',
                'price'       => 0,
                'active'      => 1,
            ],
        ];

        // اینفلوئنسرها
        $influencers = [
            (object)[
                'id'                 => 1,
                'instagram_username' => 'fashion_style_ir',
                'follower_count'     => 520000,
                'story_price'        => 850000,
                'status'             => 'verified',
                'full_name'          => 'سارا محمدی',
                'avatar'             => '',
            ],
            (object)[
                'id'                 => 2,
                'instagram_username' => 'tech_review_fa',
                'follower_count'     => 380000,
                'story_price'        => 650000,
                'status'             => 'verified',
                'full_name'          => 'علی رضایی',
                'avatar'             => '',
            ],
            (object)[
                'id'                 => 3,
                'instagram_username' => 'cook_with_maryam',
                'follower_count'     => 290000,
                'story_price'        => 450000,
                'status'             => 'verified',
                'full_name'          => 'مریم احمدی',
                'avatar'             => '',
            ],
            (object)[
                'id'                 => 4,
                'instagram_username' => 'fitness_iran_pro',
                'follower_count'     => 215000,
                'story_price'        => 380000,
                'status'             => 'verified',
                'full_name'          => 'رضا کریمی',
                'avatar'             => '',
            ],
            (object)[
                'id'                 => 5,
                'instagram_username' => 'travel_with_amir',
                'follower_count'     => 175000,
                'story_price'        => 320000,
                'status'             => 'verified',
                'full_name'          => 'امیر حسینی',
                'avatar'             => '',
            ],
            (object)[
                'id'                 => 6,
                'instagram_username' => 'beauty_tips_naz',
                'follower_count'     => 145000,
                'story_price'        => 280000,
                'status'             => 'verified',
                'full_name'          => 'نازنین عباسی',
                'avatar'             => '',
            ],
            (object)[
                'id'                 => 7,
                'instagram_username' => 'car_magazine_ir',
                'follower_count'     => 130000,
                'story_price'        => 250000,
                'status'             => 'verified',
                'full_name'          => 'محمد طاهری',
                'avatar'             => '',
            ],
            (object)[
                'id'                 => 8,
                'instagram_username' => 'music_daily_fa',
                'follower_count'     => 98000,
                'story_price'        => 180000,
                'status'             => 'verified',
                'full_name'          => 'فاطمه نوری',
                'avatar'             => '',
            ],
            (object)[
                'id'                 => 9,
                'instagram_username' => 'startup_hub_ir',
                'follower_count'     => 85000,
                'story_price'        => 150000,
                'status'             => 'verified',
                'full_name'          => 'حسین جعفری',
                'avatar'             => '',
            ],
            (object)[
                'id'                 => 10,
                'instagram_username' => 'pet_lovers_iran',
                'follower_count'     => 72000,
                'story_price'        => 120000,
                'status'             => 'verified',
                'full_name'          => 'زهرا صادقی',
                'avatar'             => '',
            ],
        ];

        // برندگان قرعه‌کشی
        $winners = [
            (object)[
                'id'           => 1,
                'user_id'      => 101,
                'full_name'    => 'محمد رضایی',
                'prize_amount' => 5000000,
                'created_at'   => '2025-06-15 14:30:00',
            ],
            (object)[
                'id'           => 2,
                'user_id'      => 205,
                'full_name'    => 'علی کریمی',
                'prize_amount' => 2000000,
                'created_at'   => '2025-06-14 18:45:00',
            ],
            (object)[
                'id'           => 3,
                'user_id'      => 312,
                'full_name'    => 'فاطمه محمدی',
                'prize_amount' => 1500000,
                'created_at'   => '2025-06-13 20:10:00',
            ],
            (object)[
                'id'           => 4,
                'user_id'      => 418,
                'full_name'    => 'سارا احمدی',
                'prize_amount' => 1000000,
                'created_at'   => '2025-06-12 16:20:00',
            ],
            (object)[
                'id'           => 5,
                'user_id'      => 527,
                'full_name'    => 'رضا حسینی',
                'prize_amount' => 750000,
                'created_at'   => '2025-06-11 12:00:00',
            ],
        ];

        // سوالات متداول
        $faqs = [
            [
                'question' => 'چرتکه چیست؟',
                'answer'   => 'چرتکه یک پلتفرم حرفه‌ای کسب درآمد آنلاین است. با انجام تسک‌های شبکه‌های اجتماعی، سرمایه‌گذاری، قرعه‌کشی روزانه، تولید محتوا و معرفی دوستان درآمد واقعی کسب کنید.',
            ],
            [
                'question' => 'چگونه ثبت‌نام کنم؟',
                'answer'   => 'با کلیک روی دکمه «ثبت‌نام» و وارد کردن نام، ایمیل، شماره موبایل و رمز عبور در کمتر از ۳۰ ثانیه عضو شوید. ثبت‌نام کاملاً رایگان است.',
            ],
            [
                'question' => 'حداقل مبلغ برداشت چقدر است؟',
                'answer'   => 'حداقل مبلغ برداشت بر اساس تنظیمات فعلی سایت تعیین می‌شود. برای اطلاع دقیق از مبلغ به بخش کیف پول در پنل کاربری خود مراجعه کنید.',
            ],
            [
                'question' => 'آیا سرمایه‌گذاری ریسک دارد؟',
                'answer'   => 'بله، سرمایه‌گذاری همواره ریسک سود و ضرر دارد. لطفاً قبل از سرمایه‌گذاری هشدارهای ریسک را به دقت مطالعه کنید. سیستم هیچ تضمینی برای سود نمی‌دهد.',
            ],
            [
                'question' => 'سیستم قرعه‌کشی چگونه کار می‌کند؟',
                'answer'   => 'هر روز ۳ عدد تصادفی تولید می‌شود و شما یکی را انتخاب می‌کنید. سیستم وزن‌دهی خودکار و عادلانه برنده نهایی را انتخاب می‌کند. هیچ کاربری حذف نمی‌شود و امید همه تا آخر حفظ می‌شود.',
            ],
            [
                'question' => 'چگونه احراز هویت انجام دهم؟',
                'answer'   => 'در بخش پروفایل، تصویر کارت ملی و سلفی با دست‌نوشته آپلود کنید. پس از بررسی توسط تیم ما، حساب شما تأیید خواهد شد و دسترسی کامل خواهید داشت.',
            ],
            [
                'question' => 'آیا می‌توانم با تتر (USDT) کار کنم؟',
                'answer'   => 'بله. بخش سرمایه‌گذاری بر اساس تتر عمل می‌کند. همچنین اگر سایت در حالت تتری فعال باشد، تمام بخش‌ها با تتر کار خواهند کرد.',
            ],
        ];

        return view('welcome', [
            'stats'       => $stats,
            'banners'     => $banners,
            'influencers' => $influencers,
            'winners'     => $winners,
            'faqs'        => $faqs,
        ]);
    }
	
}