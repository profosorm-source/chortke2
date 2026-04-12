<?php

use Core\Container;
use Core\Application;
use Core\Session;
use Core\Database;
use Core\Logger;
use App\Models\User;
use App\Services\AuthService;
use App\Services\CaptchaService;
use App\Models\SystemSetting;
use App\Models\ActivityLog;
use App\Models\TwoFactorCode;
use App\Services\AuditTrail;
use App\Services\WalletService;
use App\Services\NotificationService;
use App\Services\UploadService;
use App\Services\WithdrawalLimitService;
use App\Services\WithdrawalService;
use App\Services\ReferralCommissionService;
use App\Services\UserLevelService;
use App\Services\ContentService;
use App\Services\InvestmentService;
use App\Services\LotteryService;
use App\Services\ManualDepositService;
use App\Services\CryptoDepositService;
use App\Services\CryptoVerificationService;
use App\Services\PaymentService;
use App\Services\TaskExecutionService;
use App\Services\TaskDisputeService;
use App\Services\TaskRecheckService;
use App\Services\AdTaskService;
use App\Services\StoryPromotionService;
use App\Services\KYCService;
use App\Services\BannerService;
use App\Services\TwoFactorService;
use App\Services\CustomTaskService;
use App\Services\SEOTaskService;
use App\Services\UserDashboardService;
use App\Models\TaskExecution;
use App\Models\Transaction;
use App\Models\ReferralCommission;
use App\Models\Notification;
use App\Models\SocialAccount;
use App\Models\Investment;
use App\Models\LotteryRound;
use App\Models\CustomTaskDispute;
use App\Models\CustomTask;
use App\Models\CustomTaskSubmission;


// BASE_PATH
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

// Autoloader — vendor/autoload.php + PSR-4 (Core, App)
require_once BASE_PATH . '/core/Autoloader.php';
\Core\Autoloader::register();

// Helpers از طریق composer autoload (files section) لود می‌شوند
// نیازی به require_once دستی نیست

// Container Singleton
$container = Container::getInstance();

// ─── Logger — Singleton مرکزی لاگ ────────────────────────────────────────────
$container->singleton(Logger::class, function() {
    return new Logger('app');
});


// ثبت سرویس‌ها و مدل‌ها
$container->singleton(Session::class, function() {
    return Session::getInstance();
});

$container->singleton(Database::class, function() {
    return new Database(require BASE_PATH . '/config/database.php');
});

$container->singleton(SystemSetting::class, function($c) {
    return new SystemSetting($c->make(Database::class));
});

$container->singleton(AuthService::class, function($c) {
    return new AuthService(
        $c->make(User::class),
        $c->make(\App\Models\PasswordReset::class),
        $c->make(ActivityLog::class),
        $c->make(Session::class),
        $c->make(\Core\RateLimiter::class),
        $c->make(\App\Services\SessionService::class),
        $c->make(\App\Services\EmailService::class)   // BUG FIX 1: ارسال ایمیل reset و welcome
    );
});


$container->singleton(CaptchaService::class, function($c) {
    return new CaptchaService(
        $c->make(Database::class),
        $c->make(SystemSetting::class),
        $c->make(Session::class)
    );
});

$container->singleton(User::class, function($c) {
    return new User($c->make(Database::class));
});

$container->singleton(\App\Services\SettingService::class, function($c) {
    return new \App\Services\SettingService(
        $c->make(\App\Models\Setting::class),
        $c->make(Database::class)
    );
});

$container->singleton(\App\Models\Setting::class, function($c) {
    return new \App\Models\Setting($c->make(Database::class));
});


// ─── Singletons: Simple Services ─────────────────────────────────────────
$container->singleton(WalletService::class, function($c) {
    return new WalletService(
        $c->make(Database::class),
        $c->make(\App\Models\Wallet::class),
        $c->make(\App\Models\Transaction::class),
        $c->make(\Core\IdempotencyKey::class),
        new AuditTrail(),
        $c->make(Logger::class)->withChannel('wallet')
    );
});

$container->singleton(\App\Services\EmailService::class, function($c) {
    return new \App\Services\EmailService(
        $c->make(\App\Models\EmailQueue::class),
        $c->make(\App\Models\NotificationPreference::class),
        $c->make(\App\Models\Setting::class),
        $c->make(\App\Models\User::class),
        $c->make(Logger::class)->withChannel('email')
    );
});

$container->singleton(NotificationService::class, function($c) {
    return new NotificationService(
        $c->make(\App\Models\Notification::class),
        $c->make(\App\Models\NotificationPreference::class),
        $c->make(Database::class),
        $c->make(Logger::class)->withChannel('notification')
    );
});
$container->singleton(UploadService::class, fn() => new UploadService());
$container->singleton(WithdrawalLimitService::class, fn() => new WithdrawalLimitService());
$container->singleton(ActivityLog::class, fn() => new ActivityLog());
$container->singleton(TwoFactorCode::class, fn() => new TwoFactorCode());

// ─── Singletons: Services with dependencies ───────────────────────────────
$container->singleton(ReferralCommissionService::class, function($c) {
    return new ReferralCommissionService($c->make(WalletService::class));
});

$container->singleton(UserLevelService::class, function($c) {
    return new UserLevelService(
        $c->make(WalletService::class),
        $c->make(ReferralCommissionService::class)
    );
});


// Models
$container->singleton(App\Models\CustomTask::class, function($c) {
    return new App\Models\CustomTask($c->make(Database::class));
});

$container->singleton(App\Models\CustomTaskSubmission::class, function($c) {
    return new App\Models\CustomTaskSubmission($c->make(Database::class));
});

$container->singleton(App\Models\CustomTaskDispute::class, function($c) {
    return new App\Models\CustomTaskDispute($c->make(Database::class));
});

// Service - با استفاده از Anti-Fraud موجود
$container->singleton(App\Services\CustomTaskService::class, function($c) {
    return new App\Services\CustomTaskService(
        $c->make(Database::class),
        $c->make(App\Services\WalletService::class),
        $c->make(App\Services\UserLevelService::class),
        $c->make(App\Services\ReferralCommissionService::class),
        $c->make(App\Models\CustomTask::class),
        $c->make(App\Models\CustomTaskSubmission::class),
        $c->make(App\Models\CustomTaskDispute::class),
        $c->make(App\Services\AntiFraud\BrowserFingerprintService::class),
        $c->make(App\Services\AntiFraud\IPQualityService::class),
        $c->make(App\Services\AntiFraud\SessionAnomalyService::class)
    );
});

// Controllers
$container->singleton(App\Controllers\User\CustomTaskController::class, function($c) {
    return new App\Controllers\User\CustomTaskController(
        $c->make(App\Models\CustomTask::class),
        $c->make(App\Models\CustomTaskSubmission::class),
        $c->make(App\Services\CustomTaskService::class),
        $c->make(App\Services\UploadService::class)
    );
});

$container->singleton(App\Controllers\Admin\CustomTaskController::class, function($c) {
    return new App\Controllers\Admin\CustomTaskController(
        $c->make(App\Services\CustomTaskService::class),
        $c->make(App\Services\WalletService::class),
        $c->make(App\Models\CustomTask::class),
        $c->make(App\Models\CustomTaskSubmission::class)
    );
});

$container->singleton(ContentService::class, function($c) {
    return new ContentService(
        $c->make(WalletService::class),
        $c->make(NotificationService::class)
    );
});

$container->singleton(InvestmentService::class, function($c) {
    return new InvestmentService(
        $c->make(Database::class),
        $c->make(WalletService::class),
        $c->make(NotificationService::class),
        $c->make(\App\Models\Investment::class),
        $c->make(\App\Models\TradingRecord::class),
        $c->make(\App\Models\InvestmentProfit::class),
        $c->make(\App\Models\InvestmentWithdrawal::class),
        new AuditTrail()
    );
});

$container->singleton(LotteryService::class, function($c) {
    return new LotteryService(
        $c->make(WalletService::class),
        $c->make(NotificationService::class)
    );
});

$container->singleton(ManualDepositService::class, function($c) {
    return new ManualDepositService(
        $c->make(Database::class),
        $c->make(WalletService::class),
        $c->make(NotificationService::class),
        $c->make(\App\Models\ManualDeposit::class),
        $c->make(\App\Models\BankCard::class),
        $c->make(\App\Models\User::class),
        new AuditTrail(),
        $c->make(Logger::class)->withChannel('manual_deposit')
    );
});

$container->singleton(CryptoDepositService::class, function($c) {
    return new CryptoDepositService(
        $c->make(WalletService::class),
        $c->make(NotificationService::class)
    );
});

$container->singleton(CryptoVerificationService::class, function($c) {
    return new CryptoVerificationService(
        $c->make(Database::class),
        $c->make(Logger::class)->withChannel('crypto'),
        $c->make(\App\Models\Setting::class),
        $c->make(\App\Models\CryptoDeposit::class),
        $c->make(WalletService::class),
        $c->make(NotificationService::class)
    );
});

$container->singleton(PaymentService::class, function($c) {
    return new PaymentService(
        $c->make(WalletService::class),
        $c->make(NotificationService::class)
    );
});

$container->singleton(WithdrawalService::class, function($c) {
    return new WithdrawalService(
        $c->make(WalletService::class),
        $c->make(NotificationService::class),
        $c->make(WithdrawalLimitService::class),
        new AuditTrail(),
        $c->make(Logger::class)->withChannel('withdrawal')
    );
});

$container->singleton(TaskExecutionService::class, function($c) {
    return new TaskExecutionService($c->make(WalletService::class));
});

$container->singleton(TaskDisputeService::class, function($c) {
    return new TaskDisputeService(
        $c->make(WalletService::class),
        $c->make(TaskExecutionService::class)
    );
});

$container->singleton(TaskRecheckService::class, function($c) {
    return new TaskRecheckService($c->make(WalletService::class));
});

$container->singleton(AdTaskService::class, function($c) {
    return new AdTaskService($c->make(WalletService::class));
});

$container->singleton(StoryPromotionService::class, function($c) {
    return new StoryPromotionService(
        $c->make(WalletService::class),
        $c->make(ReferralCommissionService::class)
    );
});

$container->singleton(KYCService::class, function($c) {
    return new KYCService(
        $c->make(\App\Models\KYCVerification::class),
        $c->make(\App\Models\User::class),
        $c->make(Database::class),
        $c->make(UploadService::class),
        new AuditTrail(),
        $c->make(NotificationService::class)
    );
});

$container->singleton(BannerService::class, function($c) {
    return new BannerService($c->make(UploadService::class));
});

$container->singleton(TwoFactorService::class, function($c) {
    return new TwoFactorService(
        $c->make(User::class),
        $c->make(TwoFactorCode::class),
        $c->make(Session::class)
    );
});


$container->singleton(SEOTaskService::class, function($c) {
    return new SEOTaskService($c->make(WalletService::class));
});


$container->singleton(UserDashboardService::class, function($c) {
    return new UserDashboardService(
        $c->make(WalletService::class),
        $c->make(UserLevelService::class),
        $c->make(ReferralCommission::class),
        $c->make(Transaction::class),
        $c->make(ActivityLog::class),
        $c->make(Advertisement::class),
        $c->make(TaskExecution::class),
        $c->make(Notification::class),
        $c->make(SocialAccount::class),
        $c->make(Investment::class),
        $c->make(LotteryRound::class)
    );
});


// ─── Auto-generated Model Bindings ─────────────────────────────────────
$container->singleton(App\Models\AdTask::class, function($c) {
    return new App\Models\AdTask($c->make(Database::class));
});

$container->singleton(App\Models\BankCard::class, function($c) {
    return new App\Models\BankCard($c->make(Database::class));
});

$container->singleton(App\Models\Banner::class, function($c) {
    return new App\Models\Banner($c->make(Database::class));
});

$container->singleton(App\Models\BannerPlacement::class, function($c) {
    return new App\Models\BannerPlacement($c->make(Database::class));
});

$container->singleton(App\Models\BugReport::class, function($c) {
    return new App\Models\BugReport($c->make(Database::class));
});

$container->singleton(App\Models\BugReportComment::class, function($c) {
    return new App\Models\BugReportComment($c->make(Database::class));
});

$container->singleton(App\Models\ContentAgreement::class, function($c) {
    return new App\Models\ContentAgreement($c->make(Database::class));
});

$container->singleton(App\Models\ContentRevenue::class, function($c) {
    return new App\Models\ContentRevenue($c->make(Database::class));
});

$container->singleton(App\Models\ContentSubmission::class, function($c) {
    return new App\Models\ContentSubmission($c->make(Database::class));
});

$container->singleton(App\Models\CryptoDeposit::class, function($c) {
    return new App\Models\CryptoDeposit($c->make(Database::class));
});

$container->singleton(App\Models\CryptoDepositIntent::class, function($c) {
    return new App\Models\CryptoDepositIntent($c->make(Database::class));
});


$container->singleton(App\Models\EmailQueue::class, function($c) {
    return new App\Models\EmailQueue($c->make(Database::class));
});

$container->singleton(App\Models\InfluencerProfile::class, function($c) {
    return new App\Models\InfluencerProfile($c->make(Database::class));
});

$container->singleton(App\Models\InvestmentProfit::class, function($c) {
    return new App\Models\InvestmentProfit($c->make(Database::class));
});

$container->singleton(App\Models\InvestmentWithdrawal::class, function($c) {
    return new App\Models\InvestmentWithdrawal($c->make(Database::class));
});

$container->singleton(App\Models\KYCVerification::class, function($c) {
    return new App\Models\KYCVerification($c->make(Database::class));
});

$container->singleton(App\Models\LotteryDailyNumber::class, function($c) {
    return new App\Models\LotteryDailyNumber($c->make(Database::class));
});

$container->singleton(App\Models\LotteryParticipation::class, function($c) {
    return new App\Models\LotteryParticipation($c->make(Database::class));
});

$container->singleton(App\Models\LotteryVote::class, function($c) {
    return new App\Models\LotteryVote($c->make(Database::class));
});

$container->singleton(App\Models\ManualDeposit::class, function($c) {
    return new App\Models\ManualDeposit($c->make(Database::class));
});

$container->singleton(App\Models\NotificationPreference::class, function($c) {
    return new App\Models\NotificationPreference($c->make(Database::class));
});

$container->singleton(App\Models\Page::class, function($c) {
    return new App\Models\Page($c->make(Database::class));
});

$container->singleton(App\Models\PasswordReset::class, function($c) {
    return new App\Models\PasswordReset($c->make(Database::class));
});

$container->singleton(App\Models\SEOExecution::class, function($c) {
    return new App\Models\SEOExecution($c->make(Database::class));
});

$container->singleton(App\Models\SEOKeyword::class, function($c) {
    return new App\Models\SEOKeyword($c->make(Database::class));
});

$container->singleton(App\Models\StoryOrder::class, function($c) {
    return new App\Models\StoryOrder($c->make(Database::class));
});

$container->singleton(App\Models\TaskDispute::class, function($c) {
    return new App\Models\TaskDispute($c->make(Database::class));
});

$container->singleton(App\Models\TaskRecheck::class, function($c) {
    return new App\Models\TaskRecheck($c->make(Database::class));
});

$container->singleton(App\Models\Ticket::class, function($c) {
    return new App\Models\Ticket($c->make(Database::class));
});

$container->singleton(App\Models\TicketCategory::class, function($c) {
    return new App\Models\TicketCategory($c->make(Database::class));
});

$container->singleton(App\Models\TicketMessage::class, function($c) {
    return new App\Models\TicketMessage($c->make(Database::class));
});

$container->singleton(App\Models\TradingRecord::class, function($c) {
    return new App\Models\TradingRecord($c->make(Database::class));
});

$container->singleton(App\Models\UserBankCard::class, function($c) {
    return new App\Models\UserBankCard($c->make(Database::class));
});

$container->singleton(App\Models\Withdrawal::class, function($c) {
    return new App\Models\Withdrawal($c->make(Database::class));
});

$container->singleton(App\Models\WithdrawalLimit::class, function($c) {
    return new App\Models\WithdrawalLimit($c->make(Database::class));
});

// ─── Auto-generated Service Bindings ───────────────────────────────────
$container->singleton(App\Services\AdvertiserDashboardService::class, function($c) {
    return new App\Services\AdvertiserDashboardService(
        $c->make(Database::class)
    );
});

$container->singleton(App\Services\BankCardService::class, function($c) {
    return new App\Services\BankCardService(
        $c->make(Database::class),
        $c->make(\App\Models\UserBankCard::class)
    );
});

$container->singleton(App\Services\BugReportService::class, function($c) {
    return new App\Services\BugReportService(
        $c->make(Database::class),
        $c->make(\App\Models\BugReport::class)
    );
});

$container->singleton(App\Services\ExportService::class, function($c) {
    return new App\Services\ExportService(
        $c->make(Database::class)
    );
});

$container->singleton(App\Services\GlobalSearchService::class, function($c) {
    return new App\Services\GlobalSearchService(
        $c->make(Database::class)
    );
});

$container->singleton(App\Services\KpiService::class, function($c) {
    return new App\Services\KpiService(
        $c->make(Database::class)
    );
});

$container->singleton(App\Services\SEOExecutionService::class, function($c) {
    return new App\Services\SEOExecutionService(
        $c->make(Database::class),
        $c->make(\App\Models\SEOExecution::class)
    );
});

$container->singleton(App\Services\SEOKeywordService::class, function($c) {
    return new App\Services\SEOKeywordService(
        $c->make(Database::class),
        $c->make(\App\Models\SEOKeyword::class)
    );
});

$container->singleton(App\Services\SessionService::class, function($c) {
    return new App\Services\SessionService(
        $c->make(Database::class),
        $c->make(\App\Models\UserSession::class)
    );
});

$container->singleton(App\Services\SitemapService::class, function($c) {
    return new App\Services\SitemapService(
        $c->make(Database::class)
    );
});

$container->singleton(App\Services\SocialAccountService::class, function($c) {
    return new App\Services\SocialAccountService(
        $c->make(Database::class),
        $c->make(\App\Models\SocialAccount::class)
    );
});

$container->singleton(App\Services\UserService::class, function($c) {
    return new App\Services\UserService(
        $c->make(Database::class),
        $c->make(User::class)
    );
});

// ─── AntiFraud Services ───────────────────────────────────────────────────
$container->singleton(\App\Services\AntiFraud\IPQualityService::class, function($c) {
    return new \App\Services\AntiFraud\IPQualityService($c->make(Database::class));
});

$container->singleton(\App\Services\AntiFraud\BrowserFingerprintService::class, function($c) {
    return new \App\Services\AntiFraud\BrowserFingerprintService($c->make(Database::class));
});

$container->singleton(\App\Services\AntiFraud\SessionAnomalyService::class, function($c) {
    return new \App\Services\AntiFraud\SessionAnomalyService($c->make(Database::class));
});

$container->singleton(\App\Services\AntiFraud\AccountTakeoverService::class, function($c) {
    return new \App\Services\AntiFraud\AccountTakeoverService(
        $c->make(Database::class),
        $c->make(\App\Services\AntiFraud\SessionAnomalyService::class),
        $c->make(\App\Services\AntiFraud\IPQualityService::class)
    );
});

// ─── UserSession Model ────────────────────────────────────────────────────
$container->singleton(\App\Models\UserSession::class, function($c) {
    return new \App\Models\UserSession($c->make(Database::class));
});

// ─── FeatureFlagService ───────────────────────────────────────────────────
$container->singleton(\App\Services\FeatureFlagService::class, function($c) {
    return new \App\Services\FeatureFlagService(
        $c->make(\App\Models\FeatureFlag::class),
        $c->make(Database::class)
    );
});

// ─── Core Services ────────────────────────────────────────────────────────
$container->singleton(\Core\RateLimiter::class, function($c) {
    return new \Core\RateLimiter();
});



// ─── AdminDashboardService ────────────────────────────────────────────────────
$container->singleton(\App\Services\AdminDashboardService::class, function($c) {
    return new \App\Services\AdminDashboardService(
        $c->make(Database::class),
        $c->make(Logger::class)->withChannel('admin')
    );
});

// ─── VitrineService ───────────────────────────────────────────────────────────
$container->singleton(\App\Services\VitrineService::class, function($c) {
    return new \App\Services\VitrineService(
        $c->make(\App\Models\VitrineListing::class),
        $c->make(\App\Models\VitrineRequest::class),
        $c->make(WalletService::class),
        $c->make(NotificationService::class),
        $c->make(\App\Services\FeatureFlagService::class),
        $c->make(Database::class),
        $c->make(Logger::class)->withChannel('vitrine')
    );
});

// ─── SocialTask Module ────────────────────────────────────────────────────
$container->singleton(\App\Services\SocialTask\TrustScoreService::class, function($c) {
    return new \App\Services\SocialTask\TrustScoreService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\UserScoreService::class)
    );
});

$container->singleton(\App\Services\SocialTask\SocialTaskScoringService::class, function($c) {
    return new \App\Services\SocialTask\SocialTaskScoringService();
});

$container->singleton(\App\Services\SocialTask\SilentAntiFraudService::class, function($c) {
    return new \App\Services\SocialTask\SilentAntiFraudService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\AntiFraud\IPQualityService::class),
        $c->make(\App\Services\AntiFraud\BrowserFingerprintService::class),
        $c->make(\App\Services\AntiFraud\SessionAnomalyService::class),
        $c->make(\App\Services\SocialTask\TrustScoreService::class),
        $c->make(\App\Services\SocialTask\SocialTaskScoringService::class),
        $c->make(\App\Services\AuditTrail::class),
        $c->make(\App\Services\NotificationService::class)
    );
});

$container->singleton(\App\Services\SocialTask\SocialTaskService::class, function($c) {
    return new \App\Services\SocialTask\SocialTaskService(
        $c->make(\Core\Database::class),
        $c->make(\App\Services\SocialTask\SocialTaskScoringService::class),
        $c->make(\App\Services\SocialTask\TrustScoreService::class),
        $c->make(\App\Services\SocialTask\SilentAntiFraudService::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\App\Services\NotificationService::class),
        $c->make(\App\Services\ApiRateLimiter::class),
        $c->make(\Core\Logger::class)
    );
});
 
// ─── Admin SocialTask Controller ─────────────────────────────────────────
$container->singleton(\App\Controllers\Admin\SocialTaskController::class, function($c) {
    return new \App\Controllers\Admin\SocialTaskController(
        $c->make(\App\Services\SocialTask\SocialTaskService::class),
        $c->make(\App\Services\SocialTask\TrustScoreService::class),
        $c->make(\App\Services\SocialTask\SilentAntiFraudService::class),
        $c->make(\App\Services\WalletService::class),
        $c->make(\Core\Database::class)
    );
});

// Application — باید آخرین خط باشد
$app = Application::getInstance();
return $app;