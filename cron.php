<?php

/**
 * cron.php - نقطه ورود زمانبندی وظایف
 *
 * ثبت در crontab سرور (هر دقیقه یکبار اجرا می‌شود):
 *   * * * * * /usr/bin/php /var/www/html/cron.php >> /var/log/chortke-cron.log 2>&1
 *
 * اجرای دستی برای تست:
 *   php cron.php
 *   php cron.php --job=email_queue   (فقط یک job خاص)
 *   php cron.php --dry-run            (فقط نمایش بدون اجرا)
 */

define('CRON_MODE', true);
define('BASE_PATH', __DIR__);

// بارگذاری bootstrap
require_once __DIR__ . '/bootstrap/app.php';

use Core\Scheduler;
use Core\Container;
use App\Services\EmailService;
use App\Services\CryptoVerificationService;
use App\Services\UserLevelService;
use App\Services\LotteryService;
use App\Services\BannerService;
use App\Services\WithdrawalService;
use App\Models\Advertisement;
use Core\Cache;
use Core\Database;

// ==========================================
//  پارامترهای CLI
// ==========================================
$onlyJob = null;
$dryRun  = false;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--job=')) {
        $onlyJob = substr($arg, 6);
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}

if ($dryRun) {
    echo "[DRY-RUN] فقط نمایش وظایف - اجرا نمی‌شوند\n";
}

// ==========================================
//  تعریف وظایف
// ==========================================
$scheduler = new Scheduler();

/**
 * ─────────────────────────────────────────
 * هر دقیقه
 * ─────────────────────────────────────────
 */

// پردازش صف ایمیل‌ها
$scheduler->everyMinute(function () {
    $service = Container::getInstance()->make(EmailService::class);
    $result  = $service->processQueue(20); // حداکثر 20 ایمیل در هر دقیقه
    return [
        'sent'   => $result['sent']   ?? 0,
        'failed' => $result['failed'] ?? 0,
    ];
}, 'email_queue');

// تأیید خودکار واریزهای کریپتو در انتظار
$scheduler->everyMinute(function () {
    $db      = Database::getInstance();
    $service = Container::getInstance()->make(CryptoVerificationService::class);

    // واریزهای pending که هنوز تأیید نشده‌اند (حداکثر ۱۲ ساعت قبل)
    $pending = $db->fetchAll(
        "SELECT id FROM crypto_deposits
         WHERE status = 'pending'
           AND created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
         ORDER BY created_at ASC
         LIMIT 10"
    );

    $verified = 0;
    foreach ($pending as $row) {
        $id     = is_array($row) ? $row['id'] : $row->id;
        $result = $service->autoVerify($id);
        if (($result['verified'] ?? false) === true) {
            $verified++;
        }
    }

    return ['pending_checked' => count($pending), 'verified' => $verified];
}, 'crypto_verify');

/**
 * ─────────────────────────────────────────
 * هر ۵ دقیقه
 * ─────────────────────────────────────────
 */

// پاک‌سازی کش منقضی‌شده
$scheduler->everyMinutes(5, function () {
    $cleaned = Cache::getInstance()->cleanup();
    return ['cleaned_files' => $cleaned];
}, 'cache_cleanup');

/**
 * ─────────────────────────────────────────
 * هر ساعت (دقیقه ۰)
 * ─────────────────────────────────────────
 */

// غیرفعال کردن آگهی‌های منقضی‌شده
$scheduler->hourly(function () {
    $db = Database::getInstance();

    $affected = $db->execute(
        "UPDATE advertisements
         SET status = 'completed', updated_at = NOW()
         WHERE status = 'active'
           AND (
             (end_date IS NOT NULL AND end_date < NOW())
             OR remaining_count <= 0
             OR remaining_budget <= 0
           )"
    );

    return ['expired_ads' => $affected];
}, 'expire_ads');

// غیرفعال کردن بنرهای منقضی‌شده
$scheduler->hourly(function () {
    $service = Container::getInstance()->make(BannerService::class);
    $count   = $service->deactivateExpiredBanners();
    return ['deactivated_banners' => $count];
}, 'expire_banners');

// انقضای نشست‌های قدیمی کاربران (بیش از ۳۰ روز)
$scheduler->hourly(function () {
    $db      = Database::getInstance();
    $affected = $db->execute(
        "DELETE FROM user_sessions
         WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    return ['deleted_sessions' => $affected];
}, 'cleanup_sessions');

// پاک‌سازی توکن‌های reset password منقضی
$scheduler->hourly(function () {
    $db      = Database::getInstance();
    $affected = $db->execute(
        "DELETE FROM password_resets
         WHERE expires_at < NOW()"
    );
    return ['deleted_tokens' => $affected];
}, 'cleanup_password_resets');

/**
 * ─────────────────────────────────────────
 * روزانه ساعت ۰۲:۰۰
 * ─────────────────────────────────────────
 */

// بررسی سطح کاربران (downgrade/upgrade/expire)
$scheduler->daily('02:00', function () {
    $service = Container::getInstance()->make(UserLevelService::class);

    $downgrades = $service->checkDowngrades();
    $expired    = $service->checkExpiredPurchases();

    return [
        'downgraded' => count($downgrades),
        'expired'    => $expired,
    ];
}, 'user_levels');

// پاک‌سازی لاگ‌های قدیمی (بیش از ۹۰ روز)
$scheduler->daily('02:30', function () {
    $db      = Database::getInstance();
    $affected = $db->execute(
        "DELETE FROM activity_logs
         WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
    );
    return ['deleted_logs' => $affected];
}, 'cleanup_logs');

// پاک‌سازی ایمیل‌های ارسال‌شده قدیمی (بیش از ۳۰ روز)
$scheduler->daily('03:00', function () {
    $db      = Database::getInstance();
    $affected = $db->execute(
        "DELETE FROM email_queue
         WHERE status = 'sent'
           AND sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    return ['deleted_emails' => $affected];
}, 'cleanup_email_queue');

// پاک‌سازی تصاویر KYC رد شده قدیمی (۶۰ روز)
$scheduler->daily('03:30', function () {
    $db   = Database::getInstance();
    $rows = $db->fetchAll(
        "SELECT id, document_front, document_back, selfie
         FROM kyc_verifications
         WHERE status = 'rejected'
           AND updated_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
           AND documents_deleted = 0"
    );

    $cleaned = 0;
    foreach ($rows as $row) {
        $row = (array)$row;
        foreach (['document_front', 'document_back', 'selfie'] as $field) {
            if (!empty($row[$field])) {
                $path = BASE_PATH . '/storage/uploads/kyc/' . $row[$field];
                if (file_exists($path)) {
                    unlink($path);
                }
            }
        }
        $db->execute(
            "UPDATE kyc_verifications SET documents_deleted = 1 WHERE id = ?",
            [$row['id']]
        );
        $cleaned++;
    }

    return ['cleaned_kyc_files' => $cleaned];
}, 'cleanup_kyc_files');

/**
 * ─────────────────────────────────────────
 * روزانه ساعت ۰۴:۰۰ - ریست ماهانه
 * ─────────────────────────────────────────
 */

// ریست آمار ماهانه سطح کاربران (اول هر ماه)
$scheduler->daily('04:00', function () {
    if ((int)date('j') !== 1) {
        return ['skipped' => 'not first day of month'];
    }
    $service = Container::getInstance()->make(UserLevelService::class);
    $reset   = $service->monthlyReset();
    return ['reset_users' => $reset];
}, 'monthly_level_reset');

/**
 * ─────────────────────────────────────────
 * هفتگی - یکشنبه ساعت ۰۵:۰۰
 * ─────────────────────────────────────────
 */

// گزارش هفتگی KPI به ادمین
$scheduler->weekly('Sunday', '05:00', function () {
    $db = Database::getInstance();

    // تعداد ثبت‌نام‌های هفته گذشته
    $newUsers = (int)$db->fetchColumn(
        "SELECT COUNT(*) FROM users
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    );

    // مجموع تراکنش‌های هفته گذشته
    $txVolume = (float)$db->fetchColumn(
        "SELECT COALESCE(SUM(amount), 0) FROM transactions
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
           AND status = 'completed'"
    );

    // ذخیره در cache برای داشبورد ادمین
    Cache::getInstance()->put('kpi_weekly_report', [
        'new_users'    => $newUsers,
        'tx_volume'    => $txVolume,
        'generated_at' => date('Y-m-d H:i:s'),
    ], 10080); // یک هفته

    return ['new_users' => $newUsers, 'tx_volume' => $txVolume];
}, 'weekly_kpi_report');

// ==========================================
//  SocialTask Jobs
// ==========================================

use App\Services\SocialTask\TrustScoreService  as SocialTrustService;
use App\Services\SocialTask\SocialTaskService   as SocialTaskSvc;

// ── هر شب ساعت ۱ — Web/Mobile Split (محاسبه median reward)
$scheduler->daily('01:00', function () {
    $svc = Container::getInstance()->make(SocialTaskSvc::class);
    $median = $svc->updateMedianReward();
    return ['median_reward' => $median];
}, 'social_task_median_reward');

// ── هر شب ساعت ۱:۳۰ — Trust Score هفتگی (بهبود + جریمه soft_excess)
$scheduler->daily('01:30', function () {
    $svc    = Container::getInstance()->make(SocialTrustService::class);
    $result = $svc->processWeeklyRecovery();
    return $result;
}, 'social_task_trust_recovery');

// ── هر ساعت — انقضای execution های زمان‌گذشته (بیش از ۲۴ ساعت pending)
$scheduler->hourly(function () {
    $db = Database::getInstance();
    $affected = $db->query(
        "UPDATE social_task_executions
         SET status = 'expired', updated_at = NOW()
         WHERE status = 'pending'
           AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    $count = $db->rowCount() ?? 0;
    if ($count > 0) {
        // بازگرداندن slot به آگهی
        $db->query(
            "UPDATE social_ads sa
             JOIN (
                 SELECT ad_id, COUNT(*) AS cnt
                 FROM social_task_executions
                 WHERE status = 'expired'
                   AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
                 GROUP BY ad_id
             ) ex ON ex.ad_id = sa.id
             SET sa.remaining_slots = sa.remaining_slots + ex.cnt
             WHERE sa.status = 'active'"
        );
    }
    return ['expired' => $count];
}, 'social_task_expire_pending');

// ==========================================
//  اجرا
// ==========================================

echo '[' . date('Y-m-d H:i:s') . '] شروع اجرای cron jobs' . PHP_EOL;

if ($dryRun) {
    echo "وظایف ثبت‌شده - اجرا نشدند (dry-run mode)\n";
    exit(0);
}

$results = $scheduler->run();

// نمایش نتایج
foreach ($results as $name => $result) {
    $status = $result['status'];
    $icon   = match($status) {
        'ok'      => '✓',
        'error'   => '✗',
        'skipped' => '⟳',
        default   => '?',
    };

    echo "[{$icon}] {$name}: {$status}";

    if ($status === 'ok' && isset($result['output'])) {
        $out = $result['output'];
        if (is_array($out)) {
            echo ' - ' . implode(', ', array_map(
                fn($k, $v) => "{$k}={$v}",
                array_keys($out),
                array_values($out)
            ));
        }
    }

    if ($status === 'error') {
        echo ' - ' . ($result['message'] ?? '');
    }

    echo PHP_EOL;
}

echo '[' . date('Y-m-d H:i:s') . '] پایان' . PHP_EOL;