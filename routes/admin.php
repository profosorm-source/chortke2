<?php

/**
 * مسیرهای پنل مدیریت
 */

use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;
use App\Controllers\Admin\AuthController          as AdminAuthController;
use App\Controllers\Admin\DashboardController     as AdminDashboardController;
use App\Controllers\Admin\UserController          as AdminUserController;
use App\Controllers\Admin\KYCController           as AdminKYCController;
use App\Controllers\Admin\LogController           as AdminLogController;
use App\Controllers\Admin\NotificationController  as AdminNotificationController;
use App\Controllers\Admin\BankCardController      as AdminBankCardController;
use App\Controllers\Admin\ManualDepositController as AdminManualDepositController;
use App\Controllers\Admin\CryptoDepositController as AdminCryptoDepositController;
use App\Controllers\Admin\WithdrawalController    as AdminWithdrawalController;
use App\Controllers\Admin\TransactionController   as AdminTransactionController;
use App\Controllers\Admin\SocialAccountController as AdminSocialAccountController;
use App\Controllers\Admin\AdTaskController        as AdminAdTaskController;
use App\Controllers\Admin\TaskExecutionController as AdminTaskExecutionController;
use App\Controllers\Admin\TaskDisputeController   as AdminTaskDisputeController;
use App\Controllers\Admin\TaskRecheckController   as AdminTaskRecheckController;
use App\Controllers\Admin\SEOKeywordController;
use App\Controllers\Admin\RoleController          as AdminRoleController;
use App\Controllers\Admin\ReferralController      as AdminReferralController;
use App\Controllers\Admin\LevelController         as AdminLevelController;
use App\Controllers\Admin\CustomTaskController    as AdminCustomTaskController;
use App\Controllers\Admin\StoryController         as AdminStoryController;
use App\Controllers\Admin\ContentController       as AdminContentController;
use App\Controllers\Admin\InvestmentController    as AdminInvestmentController;
use App\Controllers\Admin\LotteryController       as AdminLotteryController;
use App\Controllers\Admin\BannerController        as AdminBannerController;
use App\Controllers\Admin\BugReportController     as AdminBugReportController;
use App\Controllers\Admin\KpiController;
use App\Controllers\Admin\SystemSettingController;
use App\Controllers\Admin\PageController          as AdminPageController;
use App\Controllers\Admin\TicketController        as AdminTicketController;
use App\Controllers\Admin\CouponController        as AdminCouponController;
use App\Controllers\Admin\FraudDashboardController;
use App\Controllers\Admin\FraudManagementController;
use App\Controllers\Admin\AuditTrailController;
use App\Controllers\Admin\AdminExportController;
use App\Controllers\Admin\FeatureFlagController;
use App\Controllers\SearchController;
use App\Controllers\User\TicketController         as UserTicketController;

$admin = [AuthMiddleware::class, AdminMiddleware::class];
$r     = app()->router;

// ── ورود/خروج ──────────────────────────────────────────────────────────────
$r->get('/admin/login',   [AdminAuthController::class, 'showLogin']);
$r->post('/admin/login',  [AdminAuthController::class, 'login']);
$r->post('/admin/logout', [AdminAuthController::class, 'logout']);

// ── داشبورد ────────────────────────────────────────────────────────────────
$r->get('/admin/dashboard',                   [AdminDashboardController::class, 'index'],          $admin);
$r->get('/admin/dashboard/recent-activity',   [AdminDashboardController::class, 'recentActivity'], $admin);
$r->get('/admin/dashboard/system-status',     [AdminDashboardController::class, 'systemStatus'],   $admin);

// ── کاربران ────────────────────────────────────────────────────────────────
$r->get('/admin/users',                [AdminUserController::class, 'index'],      $admin);
$r->get('/admin/users/create',         [AdminUserController::class, 'create'],     $admin);
$r->post('/admin/users/store',         [AdminUserController::class, 'store'],      $admin);
$r->get('/admin/users/{id}/edit',      [AdminUserController::class, 'edit'],       $admin);
$r->post('/admin/users/{id}/update',   [AdminUserController::class, 'update'],     $admin);
$r->post('/admin/users/{id}/ban',      [AdminUserController::class, 'ban'],        $admin);
$r->post('/admin/users/{id}/unban',    [AdminUserController::class, 'unban'],      $admin);
$r->post('/admin/users/{id}/suspend',  [AdminUserController::class, 'suspend'],    $admin);
$r->post('/admin/users/{id}/unsuspend',[AdminUserController::class, 'unsuspend'],  $admin);

// ── KYC ────────────────────────────────────────────────────────────────────
$r->get('/admin/kyc',                       [AdminKYCController::class, 'index'],          $admin);
$r->get('/admin/kyc/review/{id}',           [AdminKYCController::class, 'review'],         $admin);
$r->post('/admin/kyc/verify/{id}',          [AdminKYCController::class, 'verify'],         $admin);
$r->post('/admin/kyc/reject/{id}',          [AdminKYCController::class, 'reject'],         $admin);
$r->post('/admin/kyc/mark-reviewing/{id}',  [AdminKYCController::class, 'markAsReviewing'],$admin);
$r->post('/admin/kyc/delete-image/{id}',    [AdminKYCController::class, 'deleteImage'],    $admin);

// لاگ‌ها
$app->router->get('/admin/logs', [AdminLogController::class, 'index'], [AuthMiddleware::class, AdminMiddleware::class]);
$app->router->post('/admin/logs/cleanup', [AdminLogController::class, 'cleanup'], [AuthMiddleware::class, AdminMiddleware::class]);

// سیستم لاگ پیشرفته
$app->router->get('/admin/logs/dashboard', [AdminLogController::class, 'dashboard'], [AuthMiddleware::class, AdminMiddleware::class]);
$app->router->get('/admin/logs/errors', [AdminLogController::class, 'errors'], [AuthMiddleware::class, AdminMiddleware::class]);
$app->router->get('/admin/logs/error-details', [AdminLogController::class, 'errorDetails'], [AuthMiddleware::class, AdminMiddleware::class]);
$app->router->post('/admin/logs/resolve-error', [AdminLogController::class, 'resolveError'], [AuthMiddleware::class, AdminMiddleware::class]);
$app->router->get('/admin/logs/notification-settings', [AdminLogController::class, 'notificationSettings'], [AuthMiddleware::class, AdminMiddleware::class]);
$app->router->post('/admin/logs/save-channel', [AdminLogController::class, 'saveChannel'], [AuthMiddleware::class, AdminMiddleware::class]);
$app->router->post('/admin/logs/test-channel', [AdminLogController::class, 'testChannel'], [AuthMiddleware::class, AdminMiddleware::class]);
$app->router->get('/admin/logs/api-stats', [AdminLogController::class, 'apiStats'], [AuthMiddleware::class, AdminMiddleware::class]);

// ── اعلان‌ها ────────────────────────────────────────────────────────────────
$r->get('/admin/notifications',                  [AdminNotificationController::class, 'index'],             $admin);
$r->get('/admin/notifications/unread-count',     [AdminNotificationController::class, 'unreadCount'],       $admin);
$r->get('/admin/notifications/fetch',            [AdminNotificationController::class, 'fetch'],             $admin);
$r->get('/admin/notifications/send',             [AdminNotificationController::class, 'showSend'],          $admin);
$r->post('/admin/notifications/send',            [AdminNotificationController::class, 'send'],              $admin);
$r->get('/admin/notifications/stats',            [AdminNotificationController::class, 'stats'],             $admin);
$r->post('/admin/notifications/mark-read/{id}',  [AdminNotificationController::class, 'markAsRead'],        $admin);
$r->post('/admin/notifications/mark-all-read',   [AdminNotificationController::class, 'markAllAsRead'],     $admin);

// ── کارت‌های بانکی ──────────────────────────────────────────────────────────
$r->get('/admin/bank-cards',         [AdminBankCardController::class, 'index'],  $admin);
$r->get('/admin/bank-cards/review',  [AdminBankCardController::class, 'review'], $admin);
$r->post('/admin/bank-cards/verify', [AdminBankCardController::class, 'verify'], $admin);
$r->post('/admin/bank-cards/reject', [AdminBankCardController::class, 'reject'], $admin);

// ── واریز دستی ─────────────────────────────────────────────────────────────
$r->get('/admin/manual-deposits',         [AdminManualDepositController::class, 'index'],  $admin);
$r->get('/admin/manual-deposits/review',  [AdminManualDepositController::class, 'review'], $admin);
$r->post('/admin/manual-deposits/verify', [AdminManualDepositController::class, 'verify'], $admin);
$r->post('/admin/manual-deposits/reject', [AdminManualDepositController::class, 'reject'], $admin);

// ── واریز کریپتو ───────────────────────────────────────────────────────────
$r->get('/admin/crypto-deposits',         [AdminCryptoDepositController::class, 'index'],  $admin);
$r->get('/admin/crypto-deposits/review',  [AdminCryptoDepositController::class, 'review'], $admin);
$r->post('/admin/crypto-deposits/verify', [AdminCryptoDepositController::class, 'verify'], $admin);
$r->post('/admin/crypto-deposits/reject', [AdminCryptoDepositController::class, 'reject'], $admin);

// ── برداشت‌ها ────────────────────────────────────────────────────────────────
$r->get('/admin/withdrawals',         [AdminWithdrawalController::class, 'index'],   $admin);
$r->get('/admin/withdrawals/review',  [AdminWithdrawalController::class, 'review'],  $admin);
$r->post('/admin/withdrawals/process',[AdminWithdrawalController::class, 'process'], $admin);
$r->post('/admin/withdrawals/reject', [AdminWithdrawalController::class, 'reject'],  $admin);

// ── تراکنش‌ها ────────────────────────────────────────────────────────────────
$r->get('/admin/transactions',       [AdminTransactionController::class, 'index'], $admin);
$r->get('/admin/transactions/show',  [AdminTransactionController::class, 'show'],  $admin);

// ── حساب‌های اجتماعی ────────────────────────────────────────────────────────
$r->get('/admin/social-accounts',               [AdminSocialAccountController::class, 'index'],  $admin);
$r->get('/admin/social-accounts/{id}',          [AdminSocialAccountController::class, 'show'],   $admin);
$r->post('/admin/social-accounts/{id}/verify',  [AdminSocialAccountController::class, 'verify'], $admin);
$r->post('/admin/social-accounts/{id}/reject',  [AdminSocialAccountController::class, 'reject'], $admin);

// ── تسک‌ها ──────────────────────────────────────────────────────────────────
$r->get('/admin/ad-tasks',             [AdminAdTaskController::class, 'index'],   $admin);
$r->get('/admin/ad-tasks/{id}',        [AdminAdTaskController::class, 'show'],    $admin);
$r->post('/admin/ad-tasks/{id}/approve',[AdminAdTaskController::class, 'approve'],$admin);
$r->post('/admin/ad-tasks/{id}/reject', [AdminAdTaskController::class, 'reject'], $admin);

$r->get('/admin/task-executions',                [AdminTaskExecutionController::class, 'index'],   $admin);
$r->get('/admin/task-executions/{id}',           [AdminTaskExecutionController::class, 'show'],    $admin);
$r->post('/admin/task-executions/{id}/approve',  [AdminTaskExecutionController::class, 'approve'], $admin);
$r->post('/admin/task-executions/{id}/reject',   [AdminTaskExecutionController::class, 'reject'],  $admin);

$r->get('/admin/task-disputes',                             [AdminTaskDisputeController::class, 'index'],               $admin);
$r->get('/admin/task-disputes/{id}',                        [AdminTaskDisputeController::class, 'show'],                $admin);
$r->post('/admin/task-disputes/{id}/resolve-executor',      [AdminTaskDisputeController::class, 'resolveForExecutor'],  $admin);
$r->post('/admin/task-disputes/{id}/resolve-advertiser',    [AdminTaskDisputeController::class, 'resolveForAdvertiser'],$admin);

$r->get('/admin/task-rechecks',          [AdminTaskRecheckController::class, 'index'], $admin);
$r->post('/admin/task-rechecks/{id}/pass',[AdminTaskRecheckController::class, 'pass'], $admin);
$r->post('/admin/task-rechecks/{id}/fail',[AdminTaskRecheckController::class, 'fail'], $admin);

// ── SEO Keywords ─────────────────────────────────────────────────────────────
$r->get('/admin/seo-keywords',                [SEOKeywordController::class, 'index'],        $admin);
$r->get('/admin/seo-keywords/create',         [SEOKeywordController::class, 'showCreate'],   $admin);
$r->post('/admin/seo-keywords/store',         [SEOKeywordController::class, 'store'],        $admin);
$r->get('/admin/seo-keywords/{id}/edit',      [SEOKeywordController::class, 'showEdit'],     $admin);
$r->post('/admin/seo-keywords/{id}/update',   [SEOKeywordController::class, 'update'],       $admin);
$r->post('/admin/seo-keywords/{id}/toggle',   [SEOKeywordController::class, 'toggleActive'], $admin);
$r->post('/admin/seo-keywords/{id}/delete',   [SEOKeywordController::class, 'delete'],       $admin);

// ── نقش‌ها ──────────────────────────────────────────────────────────────────
$r->get('/admin/roles',              [AdminRoleController::class, 'index'],  $admin);
$r->get('/admin/roles/create',       [AdminRoleController::class, 'create'], $admin);
$r->post('/admin/roles/store',       [AdminRoleController::class, 'store'],  $admin);
$r->get('/admin/roles/{id}/edit',    [AdminRoleController::class, 'edit'],   $admin);
$r->post('/admin/roles/{id}/update', [AdminRoleController::class, 'update'], $admin);
$r->post('/admin/roles/{id}/delete', [AdminRoleController::class, 'delete'], $admin);
$r->post('/admin/roles/{id}/toggle', [AdminRoleController::class, 'toggle'], $admin);

// ── Referral ─────────────────────────────────────────────────────────────────
$r->get('/admin/referral',                    [AdminReferralController::class, 'index'],        $admin);
$r->get('/admin/referral/settings',           [AdminReferralController::class, 'settings'],     $admin);
$r->post('/admin/referral/settings/save',     [AdminReferralController::class, 'saveSettings'], $admin);
$r->get('/admin/referral/user/{id}',          [AdminReferralController::class, 'userDetail'],   $admin);
$r->post('/admin/referral/{id}/cancel',       [AdminReferralController::class, 'cancel'],       $admin);
$r->post('/admin/referral/batch-pay',         [AdminReferralController::class, 'batchPay'],     $admin);

// ── سطوح ────────────────────────────────────────────────────────────────────
$r->get('/admin/levels',                          [AdminLevelController::class, 'index'],           $admin);
$r->get('/admin/levels/history',                  [AdminLevelController::class, 'history'],         $admin);
$r->get('/admin/levels/create',                   [AdminLevelController::class, 'create'],          $admin);
$r->post('/admin/levels/create',                  [AdminLevelController::class, 'store'],           $admin);
$r->get('/admin/levels/{id}/edit',                [AdminLevelController::class, 'edit'],            $admin);
$r->post('/admin/levels/{id}/update',             [AdminLevelController::class, 'update'],          $admin);
$r->post('/admin/levels/{id}/delete',             [AdminLevelController::class, 'destroy'],         $admin);
$r->post('/admin/levels/change-user-level',       [AdminLevelController::class, 'changeUserLevel'], $admin);

// ── وظایف سفارشی ─────────────────────────────────────────────────────────────
$r->get('/admin/custom-tasks',                    [AdminCustomTaskController::class, 'index'],          $admin);
$r->post('/admin/custom-tasks/approve',           [AdminCustomTaskController::class, 'approve'],        $admin);
$r->get('/admin/custom-tasks/disputes',           [AdminCustomTaskController::class, 'disputes'],       $admin);
$r->post('/admin/custom-tasks/disputes/resolve',  [AdminCustomTaskController::class, 'resolveDispute'], $admin);

// ── استوری ──────────────────────────────────────────────────────────────────
$r->get('/admin/stories',                        [AdminStoryController::class, 'index'],             $admin);
$r->get('/admin/stories/influencers',            [AdminStoryController::class, 'influencers'],       $admin);
$r->post('/admin/stories/approve-influencer',    [AdminStoryController::class, 'approveInfluencer'], $admin);
$r->post('/admin/stories/verify-proof',          [AdminStoryController::class, 'verifyProof'],       $admin);

// ── محتوا ────────────────────────────────────────────────────────────────────
$r->get('/admin/content',                           [AdminContentController::class, 'index'],        $admin);
$r->get('/admin/content/revenues',                  [AdminContentController::class, 'revenues'],     $admin);
$r->get('/admin/content/{id}',                      [AdminContentController::class, 'show'],         $admin);
$r->post('/admin/content/{id}/approve',             [AdminContentController::class, 'approve'],      $admin);
$r->post('/admin/content/{id}/reject',              [AdminContentController::class, 'reject'],       $admin);
$r->post('/admin/content/{id}/publish',             [AdminContentController::class, 'publish'],      $admin);
$r->post('/admin/content/{id}/suspend',             [AdminContentController::class, 'suspend'],      $admin);
$r->get('/admin/content/{id}/revenue/create',       [AdminContentController::class, 'revenueCreate'],$admin);
$r->post('/admin/content/{id}/revenue/store',       [AdminContentController::class, 'revenueStore'], $admin);
$r->post('/admin/content/revenue/{rid}/approve',    [AdminContentController::class, 'revenueApprove'],$admin);
$r->post('/admin/content/revenue/{rid}/pay',        [AdminContentController::class, 'revenuePay'],   $admin);

// ── سرمایه‌گذاری ─────────────────────────────────────────────────────────────
$r->get('/admin/investment',                              [AdminInvestmentController::class, 'index'],         $admin);
$r->get('/admin/investment/trades',                       [AdminInvestmentController::class, 'trades'],        $admin);
$r->get('/admin/investment/trades/create',                [AdminInvestmentController::class, 'tradeCreate'],   $admin);
$r->post('/admin/investment/trades/store',                [AdminInvestmentController::class, 'tradeStore'],    $admin);
$r->post('/admin/investment/trades/{id}/close',           [AdminInvestmentController::class, 'tradeClose'],    $admin);
$r->get('/admin/investment/apply-profit',                 [AdminInvestmentController::class, 'applyProfitForm'],$admin);
$r->post('/admin/investment/apply-profit',                [AdminInvestmentController::class, 'applyProfit'],   $admin);
$r->get('/admin/investment/withdrawals',                  [AdminInvestmentController::class, 'withdrawals'],   $admin);
$r->post('/admin/investment/withdrawals/{id}/approve',    [AdminInvestmentController::class, 'withdrawalApprove'],$admin);
$r->post('/admin/investment/withdrawals/{id}/reject',     [AdminInvestmentController::class, 'withdrawalReject'], $admin);
$r->get('/admin/investment/{id}',                         [AdminInvestmentController::class, 'show'],          $admin);
$r->post('/admin/investment/{id}/suspend',                [AdminInvestmentController::class, 'suspend'],       $admin);

// ── قرعه‌کشی ─────────────────────────────────────────────────────────────────
$r->get('/admin/lottery',                          [AdminLotteryController::class, 'index'],         $admin);
$r->get('/admin/lottery/create',                   [AdminLotteryController::class, 'create'],        $admin);
$r->post('/admin/lottery/store',                   [AdminLotteryController::class, 'store'],         $admin);
$r->get('/admin/lottery/{id}',                     [AdminLotteryController::class, 'show'],          $admin);
$r->post('/admin/lottery/{id}/generate-numbers',   [AdminLotteryController::class, 'generateNumbers'],$admin);
$r->post('/admin/lottery/daily/{did}/finalize',    [AdminLotteryController::class, 'finalizeDaily'], $admin);
$r->post('/admin/lottery/{id}/select-winner',      [AdminLotteryController::class, 'selectWinner'],  $admin);
$r->post('/admin/lottery/{id}/cancel',             [AdminLotteryController::class, 'cancel'],        $admin);

// ── بنرها ────────────────────────────────────────────────────────────────────
$r->get('/admin/banners',                          [AdminBannerController::class, 'index'],           $admin);
$r->get('/admin/banners/placements',               [AdminBannerController::class, 'placements'],      $admin);
$r->get('/admin/banners/create',                   [AdminBannerController::class, 'showCreate'],      $admin);
$r->post('/admin/banners/store',                   [AdminBannerController::class, 'store'],           $admin);
$r->get('/admin/banners/{id}/edit',                [AdminBannerController::class, 'showEdit'],        $admin);
$r->get('/admin/banners/{id}/stats',               [AdminBannerController::class, 'stats'],           $admin);
$r->post('/admin/banners/{id}/update',             [AdminBannerController::class, 'update'],          $admin);
$r->post('/admin/banners/{id}/delete',             [AdminBannerController::class, 'delete'],          $admin);
$r->post('/admin/banners/{id}/toggle',             [AdminBannerController::class, 'toggle'],          $admin);
$r->post('/admin/banners/placements/{id}/update',  [AdminBannerController::class, 'updatePlacement'], $admin);
$r->post('/admin/banners/placements/{id}/toggle',  [AdminBannerController::class, 'togglePlacement'], $admin);

// ── گزارش باگ ────────────────────────────────────────────────────────────────
$r->get('/admin/bug-reports',                  [AdminBugReportController::class, 'index'],           $admin);
$r->get('/admin/bug-reports/{id}',             [AdminBugReportController::class, 'show'],            $admin);
$r->post('/admin/bug-reports/{id}/status',     [AdminBugReportController::class, 'updateStatus'],    $admin);
$r->post('/admin/bug-reports/{id}/priority',   [AdminBugReportController::class, 'updatePriority'],  $admin);
$r->post('/admin/bug-reports/{id}/comment',    [AdminBugReportController::class, 'addComment'],      $admin);
$r->post('/admin/bug-reports/{id}/suspicious', [AdminBugReportController::class, 'toggleSuspicious'],$admin);
$r->post('/admin/bug-reports/{id}/delete',     [AdminBugReportController::class, 'delete'],          $admin);

// ── KPI ──────────────────────────────────────────────────────────────────────
$r->get('/admin/kpi',                       [KpiController::class, 'index'],               $admin);
$r->get('/admin/kpi/financial',             [KpiController::class, 'financial'],            $admin);
$r->get('/admin/kpi/users',                 [KpiController::class, 'users'],               $admin);
$r->get('/admin/kpi/chart-data',            [KpiController::class, 'chartData'],           $admin);
$r->get('/admin/kpi/export/users',          [KpiController::class, 'exportUsers'],         $admin);
$r->get('/admin/kpi/export/transactions',   [KpiController::class, 'exportTransactions'],  $admin);
$r->get('/admin/kpi/export/summary',        [KpiController::class, 'exportSummary'],       $admin);

// ── تنظیمات ──────────────────────────────────────────────────────────────────
$r->get('/admin/settings',               [SystemSettingController::class, 'index'],       $admin);
$r->post('/admin/settings/{id}/update',  [SystemSettingController::class, 'update'],      $admin);
$r->post('/admin/settings/upload-image', [SystemSettingController::class, 'uploadImage'], $admin);
$r->post('/admin/settings/remove-image', [SystemSettingController::class, 'removeImage'], $admin);

// ── صفحات استاتیک ─────────────────────────────────────────────────────────────
$r->get('/admin/pages',             [AdminPageController::class, 'index'],  $admin);
$r->get('/admin/pages/create',      [AdminPageController::class, 'create'], $admin);
$r->post('/admin/pages/store',      [AdminPageController::class, 'store'],  $admin);
$r->get('/admin/pages/edit/{id}',   [AdminPageController::class, 'edit'],   $admin);
$r->post('/admin/pages/update/{id}',[AdminPageController::class, 'update'], $admin);
$r->post('/admin/pages/delete',     [AdminPageController::class, 'delete'], $admin);

// ── تیکت‌ها ───────────────────────────────────────────────────────────────────
$r->get('/tickets',             [UserTicketController::class, 'index'],  [AuthMiddleware::class]);
$r->get('/tickets/create',      [UserTicketController::class, 'create'], [AuthMiddleware::class]);
$r->post('/tickets/store',      [UserTicketController::class, 'store'],  [AuthMiddleware::class]);
$r->get('/tickets/show/{id}',   [UserTicketController::class, 'show'],   [AuthMiddleware::class]);
$r->post('/tickets/reply',      [UserTicketController::class, 'reply'],  [AuthMiddleware::class]);
$r->post('/tickets/close',      [UserTicketController::class, 'close'],  [AuthMiddleware::class]);

$r->get('/admin/tickets',                  [AdminTicketController::class, 'index'],        $admin);
$r->get('/admin/tickets/show/{id}',        [AdminTicketController::class, 'show'],         $admin);
$r->post('/admin/tickets/reply',           [AdminTicketController::class, 'reply'],        $admin);
$r->post('/admin/tickets/change-status',   [AdminTicketController::class, 'changeStatus'], $admin);
$r->post('/admin/tickets/assign',          [AdminTicketController::class, 'assign'],       $admin);

// ── کوپن ─────────────────────────────────────────────────────────────────────
$r->get('/admin/coupons',                  [AdminCouponController::class, 'index'],       $admin);
$r->get('/admin/coupons/create',           [AdminCouponController::class, 'create'],      $admin);
$r->get('/admin/coupons/redemptions',      [AdminCouponController::class, 'redemptions'], $admin);
$r->get('/admin/coupons/statistics',       [AdminCouponController::class, 'statistics'],  $admin);
$r->post('/admin/coupons/store',           [AdminCouponController::class, 'store'],       $admin);
$r->post('/admin/coupons/delete',          [AdminCouponController::class, 'delete'],      $admin);
$r->post('/admin/coupons/toggle-active',   [AdminCouponController::class, 'toggleActive'],$admin);
$r->get('/admin/coupons/{id}/edit',        [AdminCouponController::class, 'edit'],        $admin);
$r->post('/admin/coupons/{id}/update',     [AdminCouponController::class, 'update'],      $admin);
$r->get('/admin/coupons/{id}',             [AdminCouponController::class, 'details'],     $admin);

// ── تقلب ─────────────────────────────────────────────────────────────────────
$r->get('/admin/fraud/dashboard',          [FraudDashboardController::class,  'index'],         $admin);
$r->get('/admin/fraud/logs',               [FraudManagementController::class, 'fraudLogs'],     $admin);
$r->get('/admin/fraud/ip-blacklist',       [FraudManagementController::class, 'ipBlacklist'],   $admin);
$r->post('/admin/fraud/ip-block',          [FraudManagementController::class, 'blockIP'],       $admin);
$r->post('/admin/fraud/ip-unblock',        [FraudManagementController::class, 'unblockIP'],     $admin);
$r->get('/admin/fraud/device-blacklist',   [FraudManagementController::class, 'deviceBlacklist'],$admin);
$r->post('/admin/fraud/device-block',      [FraudManagementController::class, 'blockDevice'],   $admin);
$r->post('/admin/fraud/device-unblock',    [FraudManagementController::class, 'unblockDevice'], $admin);
$r->post('/admin/fraud/reset-score',       [FraudManagementController::class, 'resetFraudScore'],$admin);

// ── Feature Flags ─────────────────────────────────────────────────────────────
$r->get('/admin/features',        [FeatureFlagController::class, 'index'],  $admin);
$r->post('/admin/features/toggle',[FeatureFlagController::class, 'toggle'], $admin);

// ── Audit Trail ───────────────────────────────────────────────────────────────
$r->get('/admin/audit-trail',        [AuditTrailController::class, 'index'],  $admin);
$r->get('/admin/audit-trail/export', [AuditTrailController::class, 'export'], $admin);

// ── Export ────────────────────────────────────────────────────────────────────
$r->get('/admin/export',              [AdminExportController::class, 'index'],        $admin);
$r->get('/admin/export/users',        [AdminExportController::class, 'users'],        $admin);
$r->get('/admin/export/transactions', [AdminExportController::class, 'transactions'], $admin);
$r->get('/admin/export/withdrawals',  [AdminExportController::class, 'withdrawals'],  $admin);
$r->get('/admin/export/audit-trail',  [AdminExportController::class, 'auditTrail'],   $admin);

// ── جستجو ─────────────────────────────────────────────────────────────────────
$r->get('/admin/search', [SearchController::class, 'adminSearch'], $admin);
