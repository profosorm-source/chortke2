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
use App\Controllers\User\AdTaskController;
use App\Controllers\User\TaskController;
use App\Controllers\User\SEOTaskController;
use App\Controllers\User\CustomTaskController;
use App\Controllers\User\StoryController;
use App\Controllers\User\ContentController;
use App\Controllers\User\InvestmentController   as UserInvestmentController;
use App\Controllers\User\LotteryController      as UserLotteryController;
use App\Controllers\User\ReferralController     as UserReferralController;
use App\Controllers\User\LevelController        as UserLevelController;
use App\Controllers\User\BugReportController    as UserBugReportController;
use App\Controllers\User\AdvertiserController;
use App\Controllers\User\ApiTokenController;
use App\Controllers\User\CouponController;
use App\Controllers\SearchController;

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

// ── تسک‌ها — تبلیغ‌دهنده ──────────────────────────────────────────────────
$r->get('/ad-tasks',                       [AdTaskController::class, 'myTasks'],          $auth);
$r->get('/ad-tasks/create',                [AdTaskController::class, 'showCreate'],       $auth);
$r->post('/ad-tasks/store',                [AdTaskController::class, 'store'],            $auth);
$r->get('/ad-tasks/{id}',                  [AdTaskController::class, 'show'],             $auth);
$r->post('/ad-tasks/{id}/pause',           [AdTaskController::class, 'pause'],            $auth);
$r->post('/ad-tasks/{id}/resume',          [AdTaskController::class, 'resume'],           $auth);
$r->post('/ad-tasks/{id}/cancel',          [AdTaskController::class, 'cancel'],           $auth);
$r->get('/ad-tasks/review/{id}',           [AdTaskController::class, 'showReview'],       $auth);
$r->post('/ad-tasks/review/{id}/approve',  [AdTaskController::class, 'approveExecution'], $auth);
$r->post('/ad-tasks/review/{id}/reject',   [AdTaskController::class, 'rejectExecution'],  $auth);

// ── تسک‌ها — انجام‌دهنده ──────────────────────────────────────────────────
$r->get('/tasks',              [TaskController::class, 'index'],       $authF);
$r->get('/tasks/history',      [TaskController::class, 'history'],     $auth);
$r->post('/tasks/start',       [TaskController::class, 'start'],       $authF);
$r->get('/tasks/{id}/execute', [TaskController::class, 'showExecute'], $authF);
$r->post('/tasks/{id}/submit', [TaskController::class, 'submit'],      $authF);
$r->post('/tasks/{id}/dispute',[TaskController::class, 'dispute'],     $auth);

// ── تسک‌های SEO ───────────────────────────────────────────────────────────
$r->get('/seo-tasks',                [SEOTaskController::class, 'index'],       $auth);
$r->get('/seo-tasks/history',        [SEOTaskController::class, 'history'],     $auth);
$r->post('/seo-tasks/start',         [SEOTaskController::class, 'start'],       $auth);
$r->get('/seo-tasks/{id}/execute',   [SEOTaskController::class, 'showExecute'], $auth);
$r->post('/seo-tasks/{id}/complete', [SEOTaskController::class, 'complete'],    $auth);

// ── وظایف سفارشی ──────────────────────────────────────────────────────────
$r->get('/custom-tasks',                        [CustomTaskController::class, 'index'],         $auth);
$r->get('/custom-tasks/available',              [CustomTaskController::class, 'available'],      $auth);
$r->get('/custom-tasks/create',                 [CustomTaskController::class, 'create'],         $auth);
$r->post('/custom-tasks/store',                 [CustomTaskController::class, 'store'],          $auth);
$r->get('/custom-tasks/my-submissions',         [CustomTaskController::class, 'mySubmissions'],  $auth);
$r->get('/custom-tasks/{id}',                   [CustomTaskController::class, 'show'],           $auth);
$r->post('/custom-tasks/start',                 [CustomTaskController::class, 'start'],          $auth);
$r->post('/custom-tasks/{id}/submit-proof',     [CustomTaskController::class, 'submitProof'],    $auth);
$r->post('/custom-tasks/review',                [CustomTaskController::class, 'review'],         $auth);
$r->post('/custom-tasks/dispute',               [CustomTaskController::class, 'dispute'],        $auth);

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

// ── پنل تبلیغ‌دهنده ───────────────────────────────────────────────────────
$r->get('/advertiser',                       [AdvertiserController::class, 'index'],     $auth);
$r->get('/advertiser/campaigns',             [AdvertiserController::class, 'campaigns'], $auth);
$r->get('/advertiser/analytics',             [AdvertiserController::class, 'analytics'], $auth);
$r->get('/advertiser/reviews',               [AdvertiserController::class, 'reviews'],   $auth);
$r->post('/advertiser/reviews/{id}/approve', [AdvertiserController::class, 'approve'],   $auth);
$r->post('/advertiser/reviews/{id}/reject',  [AdvertiserController::class, 'reject'],    $auth);
$r->get('/advertiser/chart-data',            [AdvertiserController::class, 'chartData'], $auth);

// ── توکن‌های API کاربر ────────────────────────────────────────────────────
$r->get('/api-tokens',              [ApiTokenController::class, 'index'],  $auth);
$r->post('/api-tokens/create',      [ApiTokenController::class, 'create'], $auth);
$r->post('/api-tokens/{id}/revoke', [ApiTokenController::class, 'revoke'], $auth);

// ── کوپن ─────────────────────────────────────────────────────────────────
$r->post('/coupons/validate', [CouponController::class, 'validate'], $auth);
$r->get('/coupons/history',   [CouponController::class, 'history'],  $auth);

// ── جستجو ─────────────────────────────────────────────────────────────────
$r->get('/search',      [SearchController::class, 'fullResults'], $auth);
$r->get('/search/ajax', [SearchController::class, 'userSearch'],  $auth);
