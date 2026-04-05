<?php

/**
 * مسیرهای گمشده — اضافه‌شده پس از تقسیم‌بندی routes
 */

use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;

// ── User Controllers ──────────────────────────────────────────────────────
use App\Controllers\User\PredictionController;
use App\Controllers\User\AdsocialController;
use App\Controllers\User\AdtubeController;
use App\Controllers\User\InfluencerController;
use App\Controllers\User\OnlineStoreController;
use App\Controllers\User\SeoAdController;
use App\Controllers\User\StartupBannerController;
use App\Controllers\User\UserBannerController;

// ── Admin Controllers ─────────────────────────────────────────────────────
use App\Controllers\Admin\PredictionController   as AdminPredictionController;
use App\Controllers\Admin\OnlineStoreController  as AdminOnlineStoreController;
use App\Controllers\Admin\SeoAdController        as AdminSeoAdController;
use App\Controllers\Admin\StartupBannerController as AdminStartupBannerController;
use App\Controllers\Admin\LogController          as AdminLogController;
use App\Controllers\Admin\FraudDashboardController;
use App\Controllers\Admin\SystemController       as AdminSystemController;

$auth  = [AuthMiddleware::class];
$admin = [AuthMiddleware::class, AdminMiddleware::class];
$r     = app()->router;

// ════════════════════════════════════════════════════════════════════════════
// USER ROUTES
// ════════════════════════════════════════════════════════════════════════════

// ── پیش‌بینی ─────────────────────────────────────────────────────────────────
$r->get('/prediction',            [PredictionController::class, 'index'],    $auth);
$r->get('/prediction/my-bets',    [PredictionController::class, 'myBets'],   $auth);
$r->get('/prediction/{id}',       [PredictionController::class, 'show'],     $auth);
$r->post('/prediction/place-bet', [PredictionController::class, 'placeBet'], $auth);

// ── تبلیغات شبکه اجتماعی (AdsocialController) ───────────────────────────────
// انجام‌دهنده
$r->get('/adsocial',                           [AdsocialController::class, 'income'],           $auth);
$r->get('/adsocial/history',                   [AdsocialController::class, 'history'],          $auth);
$r->post('/adsocial/start',                    [AdsocialController::class, 'start'],            $auth);
$r->get('/adsocial/{id}/execute',              [AdsocialController::class, 'showExecute'],      $auth);
$r->post('/adsocial/{id}/submit',              [AdsocialController::class, 'submit'],           $auth);
// تبلیغ‌دهنده
$r->get('/adsocial/advertise',                 [AdsocialController::class, 'myAds'],            $auth);
$r->get('/adsocial/advertise/create',          [AdsocialController::class, 'create'],           $auth);
$r->post('/adsocial/advertise/store',          [AdsocialController::class, 'store'],            $auth);
$r->get('/adsocial/advertise/{id}',            [AdsocialController::class, 'show'],             $auth);
$r->post('/adsocial/advertise/{id}/pause',     [AdsocialController::class, 'pause'],            $auth);
$r->post('/adsocial/advertise/{id}/resume',    [AdsocialController::class, 'resume'],           $auth);
$r->post('/adsocial/advertise/{id}/cancel',    [AdsocialController::class, 'cancel'],           $auth);
$r->get('/adsocial/review/{id}',               [AdsocialController::class, 'showReview'],       $auth);
$r->post('/adsocial/review/{id}/approve',      [AdsocialController::class, 'approveExecution'], $auth);
$r->post('/adsocial/review/{id}/reject',       [AdsocialController::class, 'rejectExecution'],  $auth);

// ── تبلیغات ویدیویی (AdtubeController) ──────────────────────────────────────
// انجام‌دهنده
$r->get('/adtube',                             [AdtubeController::class, 'index'],       $auth);
$r->get('/adtube/history',                     [AdtubeController::class, 'history'],     $auth);
$r->post('/adtube/start',                      [AdtubeController::class, 'start'],       $auth);
$r->get('/adtube/{id}/execute',                [AdtubeController::class, 'showExecute'], $auth);
$r->post('/adtube/{id}/submit',                [AdtubeController::class, 'submit'],      $auth);
// تبلیغ‌دهنده
$r->get('/adtube/advertise',                   [AdtubeController::class, 'advertise'],   $auth);
$r->get('/adtube/advertise/create',            [AdtubeController::class, 'create'],      $auth);
$r->post('/adtube/advertise/store',            [AdtubeController::class, 'store'],       $auth);
$r->get('/adtube/advertise/{id}',              [AdtubeController::class, 'showAd'],      $auth);
$r->post('/adtube/advertise/{id}/pause',       [AdtubeController::class, 'pause'],       $auth);
$r->post('/adtube/advertise/{id}/resume',      [AdtubeController::class, 'resume'],      $auth);

// ── اینفلوئنسر ───────────────────────────────────────────────────────────────
// پروفایل و سفارش‌های دریافتی (انجام‌دهنده)
$r->get('/influencer',                          [InfluencerController::class, 'myProfile'],      $auth);
$r->get('/influencer/register',                 [InfluencerController::class, 'register'],       $auth);
$r->post('/influencer/register',                [InfluencerController::class, 'storeProfile'],   $auth);
$r->get('/influencer/orders',                   [InfluencerController::class, 'myOrders'],       $auth);
$r->post('/influencer/orders/{id}/respond',     [InfluencerController::class, 'respondOrder'],   $auth);
$r->post('/influencer/orders/{id}/proof',       [InfluencerController::class, 'submitProof'],    $auth);
// تبلیغ‌دهنده
$r->get('/influencer/advertise',                [InfluencerController::class, 'advertise'],      $auth);
$r->get('/influencer/advertise/create',         [InfluencerController::class, 'createOrder'],    $auth);
$r->post('/influencer/advertise/store',         [InfluencerController::class, 'storeOrder'],     $auth);
$r->get('/influencer/advertise/my-orders',      [InfluencerController::class, 'myPlacedOrders'], $auth);

// ── فروشگاه آنلاین ───────────────────────────────────────────────────────────
$r->get('/online-store',                        [OnlineStoreController::class, 'index'],           $auth);
$r->get('/online-store/sell',                   [OnlineStoreController::class, 'mySales'],         $auth);
$r->get('/online-store/sell/create',            [OnlineStoreController::class, 'create'],          $auth);
$r->post('/online-store/sell/store',            [OnlineStoreController::class, 'store'],           $auth);
$r->get('/online-store/my-purchases',           [OnlineStoreController::class, 'myPurchases'],     $auth);
$r->get('/online-store/{id}',                   [OnlineStoreController::class, 'show'],            $auth);
$r->post('/online-store/{id}/buy',              [OnlineStoreController::class, 'buy'],             $auth);
$r->post('/online-store/{id}/confirm-received', [OnlineStoreController::class, 'confirmReceived'], $auth);
$r->post('/online-store/{id}/dispute',          [OnlineStoreController::class, 'dispute'],         $auth);

// ── تبلیغ سئو (کاربر) ────────────────────────────────────────────────────────
$r->get('/seo-ad',               [SeoAdController::class, 'index'],  $auth);
$r->get('/seo-ad/create',        [SeoAdController::class, 'create'], $auth);
$r->post('/seo-ad/store',        [SeoAdController::class, 'store'],  $auth);
$r->get('/seo-ad/{id}',          [SeoAdController::class, 'show'],   $auth);
$r->post('/seo-ad/{id}/pause',   [SeoAdController::class, 'pause'],  $auth);
$r->post('/seo-ad/{id}/resume',  [SeoAdController::class, 'resume'], $auth);

// ── بنر راه‌اندازی (کسب‌وکارهای نوپا) ──────────────────────────────────────
$r->get('/startup-banner',         [StartupBannerController::class, 'index'],  $auth);
$r->get('/startup-banner/create',  [StartupBannerController::class, 'create'], $auth);
$r->post('/startup-banner/store',  [StartupBannerController::class, 'store'],  $auth);
$r->get('/startup-banner/{id}',    [StartupBannerController::class, 'show'],   $auth);

// ── بنرهای سایزی کاربر (جایگاه‌های مختلف) ──────────────────────────────────
$r->get('/my-banners',               [UserBannerController::class, 'index'],  $auth);
$r->get('/my-banners/create',        [UserBannerController::class, 'create'], $auth);
$r->post('/my-banners/store',        [UserBannerController::class, 'store'],  $auth);
$r->get('/my-banners/{id}',          [UserBannerController::class, 'show'],   $auth);
$r->post('/my-banners/{id}/cancel',  [UserBannerController::class, 'cancel'], $auth);

// ════════════════════════════════════════════════════════════════════════════
// ADMIN ROUTES
// ════════════════════════════════════════════════════════════════════════════

// ── پیش‌بینی (ادمین) ─────────────────────────────────────────────────────────
$r->get('/admin/prediction',                     [AdminPredictionController::class, 'index'],       $admin);
$r->get('/admin/prediction/create',              [AdminPredictionController::class, 'create'],      $admin);
$r->post('/admin/prediction/store',              [AdminPredictionController::class, 'store'],       $admin);
$r->get('/admin/prediction/{id}',                [AdminPredictionController::class, 'show'],        $admin);
$r->post('/admin/prediction/{id}/settle',        [AdminPredictionController::class, 'settle'],      $admin);
$r->post('/admin/prediction/{id}/cancel',        [AdminPredictionController::class, 'cancel'],      $admin);
$r->post('/admin/prediction/{id}/close-betting', [AdminPredictionController::class, 'closeBetting'],$admin);

// ── فروشگاه آنلاین (ادمین) ───────────────────────────────────────────────────
$r->get('/admin/online-store',                   [AdminOnlineStoreController::class, 'index'],       $admin);
$r->post('/admin/online-store/{id}/approve',     [AdminOnlineStoreController::class, 'approve'],     $admin);
$r->post('/admin/online-store/{id}/reject',      [AdminOnlineStoreController::class, 'reject'],      $admin);
$r->get('/admin/online-store/{id}/dispute',      [AdminOnlineStoreController::class, 'showDispute'], $admin);
$r->post('/admin/online-store/{id}/resolve',     [AdminOnlineStoreController::class, 'resolve'],     $admin);
$r->post('/admin/online-store/{id}/release',     [AdminOnlineStoreController::class, 'releaseFunds'],$admin);
$r->post('/admin/online-store/{id}/refund',      [AdminOnlineStoreController::class, 'refund'],      $admin);

// ── تبلیغ سئو (ادمین) ────────────────────────────────────────────────────────
$r->get('/admin/seo-ad',                   [AdminSeoAdController::class, 'index'],   $admin);
$r->post('/admin/seo-ad/{id}/approve',     [AdminSeoAdController::class, 'approve'], $admin);
$r->post('/admin/seo-ad/{id}/reject',      [AdminSeoAdController::class, 'reject'],  $admin);
$r->post('/admin/seo-ad/{id}/pause',       [AdminSeoAdController::class, 'pause'],   $admin);

// ── بنر راه‌اندازی (ادمین) ───────────────────────────────────────────────────
$r->get('/admin/startup-banners',                  [AdminStartupBannerController::class, 'index'],   $admin);
$r->post('/admin/startup-banners/{id}/approve',    [AdminStartupBannerController::class, 'approve'], $admin);
$r->post('/admin/startup-banners/{id}/reject',     [AdminStartupBannerController::class, 'reject'],  $admin);
$r->post('/admin/startup-banners/{id}/toggle',     [AdminStartupBannerController::class, 'toggle'],  $admin);

// ── لاگ فعالیت‌ها (route گمشده: activityLogs) ────────────────────────────────
$r->get('/admin/logs/activity', [AdminLogController::class, 'activityLogs'], $admin);

// ── fraud — redirect مستقیم /admin/fraud به داشبورد fraud ───────────────────
$r->get('/admin/fraud', [FraudDashboardController::class, 'index'], $admin);

// ── کپچا (تنظیمات) ────────────────────────────────────────────────────────────
// /admin/captcha/settings از طریق SystemSettingController سرو می‌شود
// چون فرم آن به /admin/settings/update پست می‌کند (بررسی view تأیید کرد)
// ریدایرکت ساده به صفحه تنظیمات:
$r->get('/admin/captcha/settings', function() {
    app()->response->redirect(url('/admin/settings?section=captcha'));
}, $admin);

// ════════════════════════════════════════════════════════════════════════════
// ADTASK — تسک‌های سفارشی (مسیر /adtask — مجزا از /ad-tasks و /custom-tasks)
// ════════════════════════════════════════════════════════════════════════════

use App\Controllers\User\AdTaskController as AdtaskController;

// انجام‌دهنده
$r->get('/adtask/available',                    [AdtaskController::class, 'available'],    $auth);
$r->get('/adtask/my-submissions',               [AdtaskController::class, 'mySubmissions'],$auth);
$r->get('/adtask/{id}',                         [AdtaskController::class, 'show'],         $auth);
$r->post('/adtask/start',                       [AdtaskController::class, 'start'],        $auth);
$r->post('/adtask/{id}/submit-proof',           [AdtaskController::class, 'submitProof'],  $auth);
$r->post('/adtask/dispute',                     [AdtaskController::class, 'dispute'],      $auth);
// تبلیغ‌دهنده
$r->get('/adtask/advertise',                    [AdtaskController::class, 'myTasks'],      $auth);
$r->get('/adtask/advertise/create',             [AdtaskController::class, 'create'],       $auth);
$r->post('/adtask/advertise/store',             [AdtaskController::class, 'store'],        $auth);
$r->get('/adtask/advertise/{id}',               [AdtaskController::class, 'showTask'],     $auth);
$r->post('/adtask/review',                      [AdtaskController::class, 'review'],       $auth);

// ════════════════════════════════════════════════════════════════════════════
// WALLET SHORTCUTS — مسیرهای کوتاه که view ها مستقیم استفاده می‌کنند
// ════════════════════════════════════════════════════════════════════════════

use App\Controllers\User\ManualDepositController;
use App\Controllers\User\CryptoDepositController;
use App\Controllers\User\WithdrawalController;

// واریز دستی — shortcut
$r->get('/manual-deposit/create',   [ManualDepositController::class, 'create'], $auth);
$r->get('/manual-deposits',         [ManualDepositController::class, 'index'],  $auth);

// واریز کریپتو — shortcut
$r->get('/crypto-deposit/create',   [CryptoDepositController::class, 'create'], $auth);
$r->get('/crypto-deposits',         [CryptoDepositController::class, 'index'],  $auth);

// برداشت — shortcut
$r->get('/withdrawal/create',       [WithdrawalController::class, 'create'],     $auth);

// ════════════════════════════════════════════════════════════════════════════
// BANK CARDS — مسیرهای POST بدون {id} در URL (id از body می‌آید)
// ════════════════════════════════════════════════════════════════════════════

use App\Controllers\User\BankCardController as UserBankCardController;

$r->post('/bank-cards/delete',      [UserBankCardController::class, 'delete'],     $auth);
$r->post('/bank-cards/set-default', [UserBankCardController::class, 'setDefault'], $auth);

// ════════════════════════════════════════════════════════════════════════════
// DASHBOARD SHORTCUTS — لینک‌های مستقیم داشبورد کاربر
// ════════════════════════════════════════════════════════════════════════════

use App\Controllers\User\AdTaskController   as UserAdTaskController;
use App\Controllers\User\LotteryController  as UserLotteryController;

// لینک "کمپین جدید" داشبورد → فرم ساخت adtask
$r->get('/user/ad-tasks/create', [UserAdTaskController::class, 'create'], $auth);

// vote لاتاری از داشبورد (fetch مستقیم)
$r->post('/user/lottery/vote',   [UserLotteryController::class, 'vote'],  $auth);
