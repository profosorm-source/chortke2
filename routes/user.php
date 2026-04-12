<?php

/**
 * مسیرهای پنل کاربری — همه نیاز به AuthMiddleware دارند
 */

use App\Middleware\AuthMiddleware;
use App\Middleware\AdvancedFraudMiddleware;
use App\Controllers\User\DashboardController    as UserDashboardController;
use App\Controllers\User\ProfileController;
use App\Controllers\User\SessionController      as UserSessionController;
use App\Controllers\User\KYCController          as UserKYCController;
use App\Controllers\User\BankCardController     as UserBankCardController;
use App\Controllers\User\NotificationController as UserNotificationController;
use App\Controllers\User\TwoFactorController;
use App\Controllers\User\SocialAccountController;
use App\Controllers\User\SEOTaskController;
use App\Controllers\User\CustomTaskController;
use App\Controllers\User\StoryController;
use App\Controllers\User\ContentController;
use App\Controllers\User\InvestmentController       as UserInvestmentController;
use App\Controllers\User\LotteryController          as UserLotteryController;
use App\Controllers\User\ReferralController         as UserReferralController;
use App\Controllers\User\LevelController            as UserLevelController;
use App\Controllers\User\BugReportController        as UserBugReportController;
use App\Controllers\User\BannerRequestController    as UserBannerRequestController;
use App\Controllers\User\AdvertiserController;
use App\Controllers\User\ApiTokenController;
use App\Controllers\User\CouponController;
use App\Controllers\SearchController;
use App\Controllers\User\CustomTaskAdController;
use App\Controllers\User\SocialTaskController;
use App\Controllers\Api\SocialTaskApiController;

$auth  = [AuthMiddleware::class];
$authF = [AuthMiddleware::class, AdvancedFraudMiddleware::class];
$r     = app()->router;

// ── داشبورد ──────────────────────────────────────────────────────────────
$r->get('/dashboard', [UserDashboardController::class, 'index'], $authF);

// ── پروفایل ──────────────────────────────────────────────────────────────
$r->get('/profile',                  [ProfileController::class, 'index'],          $auth);
$r->post('/profile/update',          [ProfileController::class, 'update'],         $auth);
$r->post('/profile/change-password', [ProfileController::class, 'changePassword'], $auth);
$r->post('/profile/upload-avatar',   [ProfileController::class, 'uploadAvatar'],   $auth);
$r->post('/profile/delete-avatar',   [ProfileController::class, 'deleteAvatar'],   $auth);

// ── احراز هویت دو مرحله‌ای ───────────────────────────────────────────────
$r->get('/two-factor',         [TwoFactorController::class, 'index'],   $auth);
$r->post('/two-factor/enable', [TwoFactorController::class, 'enable'],  $auth);
$r->post('/two-factor/disable',[TwoFactorController::class, 'disable'], $auth);

// ── جلسات فعال ───────────────────────────────────────────────────────────
$r->get('/sessions',                      [UserSessionController::class, 'index'],     $auth);
$r->post('/sessions/terminate/{id}',      [UserSessionController::class, 'terminate'], $auth);

// ── KYC ──────────────────────────────────────────────────────────────────
$r->get('/kyc',           [UserKYCController::class, 'index'],  $auth);
$r->get('/kyc/upload',    [UserKYCController::class, 'upload'], $auth);
$r->post('/kyc/submit',   [UserKYCController::class, 'submit'], $auth);
$r->get('/kyc/status',    [UserKYCController::class, 'status'], $auth);

// ── کارت‌های بانکی ────────────────────────────────────────────────────────
$r->get('/bank-cards',                    [UserBankCardController::class, 'index'],      $auth);
$r->get('/bank-cards/create',             [UserBankCardController::class, 'create'],     $auth);
$r->post('/bank-cards/store',             [UserBankCardController::class, 'store'],      $auth);
$r->post('/bank-cards/delete/{id}',       [UserBankCardController::class, 'delete'],     $auth);
$r->post('/bank-cards/set-default/{id}',  [UserBankCardController::class, 'setDefault'], $auth);

// ── اعلان‌ها ──────────────────────────────────────────────────────────────
$r->get('/notifications',                          [UserNotificationController::class, 'index'],             $auth);
$r->get('/notifications/get',                      [UserNotificationController::class, 'get'],               $auth);
$r->get('/notifications/unread-count',             [UserNotificationController::class, 'unreadCount'],       $auth);
$r->get('/notifications/preferences',              [UserNotificationController::class, 'preferences'],       $auth);
$r->post('/notifications/mark-read',               [UserNotificationController::class, 'markAsRead'],        $auth);
$r->post('/notifications/mark-all-read',           [UserNotificationController::class, 'markAllAsRead'],     $auth);
$r->post('/notifications/archive',                 [UserNotificationController::class, 'archive'],           $auth);
$r->post('/notifications/preferences/update',      [UserNotificationController::class, 'updatePreferences'], $auth);

// ── حساب‌های اجتماعی ──────────────────────────────────────────────────────
$r->get('/social-accounts',              [SocialAccountController::class, 'index'],      $auth);
$r->get('/social-accounts/create',       [SocialAccountController::class, 'showCreate'], $auth);
$r->post('/social-accounts/store',       [SocialAccountController::class, 'store'],      $auth);
$r->get('/social-accounts/{id}/edit',    [SocialAccountController::class, 'showEdit'],   $auth);
$r->post('/social-accounts/{id}/update', [SocialAccountController::class, 'update'],     $auth);
$r->post('/social-accounts/{id}/delete', [SocialAccountController::class, 'delete'],     $auth);

// ── تسک‌های SEO ───────────────────────────────────────────────────────────
$r->get('/seo-tasks',                [SEOTaskController::class, 'index'],       $auth);
$r->get('/seo-tasks/history',        [SEOTaskController::class, 'history'],     $auth);
$r->post('/seo-tasks/start',         [SEOTaskController::class, 'start'],       $auth);
$r->get('/seo-tasks/{id}/execute',   [SEOTaskController::class, 'showExecute'], $auth);
$r->post('/seo-tasks/{id}/complete', [SEOTaskController::class, 'complete'],    $auth);

// لیست وظایف تبلیغ‌دهنده (My Ads)
$r->get('/custom-tasks', [CustomTaskController::class, 'index'], $auth);

// لیست وظایف موجود برای انجام (Worker)
$r->get('/custom-tasks/available', [CustomTaskController::class, 'available'], $auth);

// تاریخچه انجام‌های من
$r->get('/custom-tasks/my-submissions', [CustomTaskController::class, 'mySubmissions'], $auth);

// ایجاد وظیفه جدید
$r->get('/custom-tasks/create', [CustomTaskController::class, 'create'], $auth);
$r->post('/custom-tasks/store', [CustomTaskController::class, 'store'], $auth);

// جزئیات وظیفه و submission ها
$r->get('/custom-tasks/{id}', [CustomTaskController::class, 'show'], $auth);

// شروع انجام تسک (Ajax)
$r->post('/custom-tasks/start', [CustomTaskController::class, 'start'], $auth);

// ارسال مدرک (Ajax)
$r->post('/custom-tasks/{id}/submit-proof', [CustomTaskController::class, 'submitProof'], $auth);

// تایید/رد توسط تبلیغ‌دهنده (Ajax)
$r->post('/custom-tasks/review', [CustomTaskController::class, 'review'], $auth);

// ── تبلیغات استوری ────────────────────────────────────────────────────────
$r->get('/stories/influencers',         [StoryController::class, 'influencers'],  $auth);
$r->get('/stories/register',            [StoryController::class, 'register'],     $auth);
$r->post('/stories/register',           [StoryController::class, 'storeProfile'], $auth);
$r->get('/stories/create-order',        [StoryController::class, 'createOrder'],  $auth);
$r->post('/stories/store-order',        [StoryController::class, 'storeOrder'],   $auth);
$r->get('/stories/my-orders',           [StoryController::class, 'myOrders'],     $auth);
$r->get('/stories/my-page-orders',      [StoryController::class, 'myPageOrders'], $auth);
$r->post('/stories/respond-order',      [StoryController::class, 'respondOrder'], $auth);
$r->post('/stories/{id}/submit-proof',  [StoryController::class, 'submitProof'],  $auth);

// ── محتوا ─────────────────────────────────────────────────────────────────
$r->get('/content',           [ContentController::class, 'index'],    $auth);
$r->get('/content/create',    [ContentController::class, 'create'],   $auth);
$r->post('/content/store',    [ContentController::class, 'store'],    $auth);
$r->get('/content/revenues',  [ContentController::class, 'revenues'], $auth);
$r->get('/content/{id}',      [ContentController::class, 'show'],     $auth);

// ── سرمایه‌گذاری ──────────────────────────────────────────────────────────
$r->get('/investment',                 [UserInvestmentController::class, 'index'],         $auth);
$r->get('/investment/create',          [UserInvestmentController::class, 'create'],        $auth);
$r->post('/investment/store',          [UserInvestmentController::class, 'store'],         $auth);
$r->post('/investment/withdraw',       [UserInvestmentController::class, 'withdraw'],      $auth);
$r->get('/investment/profit-history',  [UserInvestmentController::class, 'profitHistory'], $auth);

// ── قرعه‌کشی ──────────────────────────────────────────────────────────────
$r->get('/lottery',       [UserLotteryController::class, 'index'], $auth);
$r->post('/lottery/join', [UserLotteryController::class, 'join'],  $auth);
$r->post('/lottery/vote', [UserLotteryController::class, 'vote'],  $auth);

// ── زیرمجموعه‌گیری ────────────────────────────────────────────────────────
$r->get('/referral',                [UserReferralController::class, 'index'],        $auth);
$r->get('/referral/commissions',    [UserReferralController::class, 'commissions'],  $auth);
$r->get('/referral/referred-users', [UserReferralController::class, 'referredUsers'],$auth);

// ── سطح‌بندی ──────────────────────────────────────────────────────────────
$r->get('/level',           [UserLevelController::class, 'index'],    $auth);
$r->post('/level/purchase', [UserLevelController::class, 'purchase'], $auth);

// ── گزارش باگ ─────────────────────────────────────────────────────────────
$r->get('/bug-reports',                   [UserBugReportController::class, 'index'],      $auth);
$r->post('/bug-reports/store',            [UserBugReportController::class, 'store'],      $auth);
$r->get('/bug-reports/{id}',              [UserBugReportController::class, 'show'],       $auth);
$r->post('/bug-reports/{id}/comment',     [UserBugReportController::class, 'addComment'], $auth);

// ── توکن‌های API کاربر ────────────────────────────────────────────────────
$r->get('/api-tokens',              [ApiTokenController::class, 'index'],  $auth);
$r->post('/api-tokens/create',      [ApiTokenController::class, 'create'], $auth);
$r->post('/api-tokens/{id}/revoke', [ApiTokenController::class, 'revoke'], $auth);

// ── کوپن ─────────────────────────────────────────────────────────────────
$r->post('/coupons/validate', [CouponController::class, 'validate'], $auth);
$r->get('/coupons/history',   [CouponController::class, 'history'],  $auth);

/*
|--------------------------------------------------------------------------
| Social Tasks — Executor
|--------------------------------------------------------------------------
*/
$r->get('/social-tasks',                [SocialTaskController::class, 'index'],              $auth);
$r->get('/social-tasks/dashboard',      [SocialTaskController::class, 'executorDashboard'],  $auth);
$r->get('/social-tasks/history',        [SocialTaskController::class, 'history'],            $auth);
$r->post('/social-tasks/start',         [SocialTaskController::class, 'start'],              $auth);
$r->get('/social-tasks/{id}/execute',   [SocialTaskController::class, 'showExecute'],        $auth);
$r->post('/social-tasks/{id}/submit',   [SocialTaskController::class, 'submit'],             $auth);

/*
|--------------------------------------------------------------------------
| Social Ads — Advertiser
|--------------------------------------------------------------------------
*/
$r->get('/social-ads',                          [SocialTaskController::class, 'myAds'],              $auth);
$r->get('/social-ads/dashboard',                [SocialTaskController::class, 'advertiserDashboard'], $auth);
$r->get('/social-ads/create',                   [SocialTaskController::class, 'create'],             $auth);
$r->post('/social-ads/store',                   [SocialTaskController::class, 'store'],              $auth);
$r->get('/social-ads/{id}',                     [SocialTaskController::class, 'show'],               $auth);
$r->post('/social-ads/{id}/pause',              [SocialTaskController::class, 'pause'],              $auth);
$r->post('/social-ads/{id}/resume',             [SocialTaskController::class, 'resume'],             $auth);
$r->post('/social-ads/{id}/cancel',             [SocialTaskController::class, 'cancel'],             $auth);
$r->get('/social-ads/execution/{id}',           [SocialTaskController::class, 'executionDetail'],    $auth);
$r->post('/social-ads/execution/{id}/approve',  [SocialTaskController::class, 'approveExecution'],   $auth);
$r->post('/social-ads/execution/{id}/reject',   [SocialTaskController::class, 'rejectExecution'],    $auth);

/*
|--------------------------------------------------------------------------
| Social Tasks — API (موبایل)
|--------------------------------------------------------------------------
*/
$r->post('/api/social-tasks/behavior',        [SocialTaskApiController::class, 'recordBehavior'], $auth);
$r->post('/api/social-tasks/camera-verify',   [SocialTaskApiController::class, 'cameraVerify'],   $auth);
$r->get('/api/social-tasks/trust-status',     [SocialTaskApiController::class, 'trustStatus'],    $auth);

// ── جستجو ─────────────────────────────────────────────────────────────────
$r->get('/search',      [SearchController::class, 'fullResults'], $auth);
$r->get('/search/ajax', [SearchController::class, 'userSearch'],  $auth);

// لیست درخواست‌های بنر کاربر
$r->get('/banner-request',    [BannerRequestController::class, 'index'], $auth);
$r->get('/banner-request/create', [BannerRequestController::class, 'create'], $auth);
$r->post('/banner-request/store',  [BannerRequestController::class, 'store'], $auth);
$r->get('/banner-request/{id}',    [BannerRequestController::class, 'show'], $auth);

/*
|--------------------------------------------------------------------------
| Custom Tasks - Advertiser
|--------------------------------------------------------------------------
*/
$router->get('/custom-tasks/ad', [CustomTaskAdController::class, 'index']);
$router->get('/custom-tasks/ad/create', [CustomTaskAdController::class, 'create']);
$router->post('/custom-tasks/ad', [CustomTaskAdController::class, 'store']);
$router->get('/custom-tasks/ad/{id}', [CustomTaskAdController::class, 'show']);

$router->post('/custom-tasks/ad/{id}/publish', [CustomTaskAdController::class, 'publish']);
$router->post('/custom-tasks/ad/{id}/pause', [CustomTaskAdController::class, 'pause']);
$router->post('/custom-tasks/ad/{id}/cancel', [CustomTaskAdController::class, 'cancel']);

$router->post('/custom-tasks/ad/submissions/{id}/approve', [CustomTaskAdController::class, 'approveSubmission']);
$router->post('/custom-tasks/ad/submissions/{id}/reject', [CustomTaskAdController::class, 'rejectSubmission']);

/*
|--------------------------------------------------------------------------
| Custom Tasks - Executor
|--------------------------------------------------------------------------
*/
$router->get('/custom-tasks', [CustomTaskController::class, 'available']); // لیست تسک‌های قابل انجام
$router->get('/custom-tasks/{id}', [CustomTaskController::class, 'show']); // جزئیات تسک
$router->post('/custom-tasks/{id}/start', [CustomTaskController::class, 'start']);
$router->post('/custom-tasks/submissions/{id}/submit', [CustomTaskController::class, 'submitProof']);

$router->get('/custom-tasks/my-submissions', [CustomTaskController::class, 'mySubmissions']);
$router->get('/custom-tasks/disputes', [CustomTaskController::class, 'disputes']);
$router->post('/custom-tasks/submissions/{id}/dispute', [CustomTaskController::class, 'storeDispute']);