<?php

/**
 * چرتکه (Chortke) — Entry Point
 *
 * ترتیب صحیح boot:
 *   ۱. BASE_PATH + ob_start + timezone
 *   ۲. بارگذاری .env
 *   ۳. Security Headers
 *   ۴. Autoloader (Core\Autoloader)
 *   ۵. Helpers
 *   ۶. Application::getInstance()   ← همه چیز از اینجا شروع می‌شه
 *        └─ ExceptionHandler (یک‌بار)
 *        └─ Session::getInstance + start()
 *        └─ Database
 *        └─ Container + registerCoreBindings
 *        └─ Maintenance check
 *   ۷. Routes
 *   ۸. Application::run()
 */

// ── ۱. پایه ─────────────────────────────────────────────────────
define('BASE_PATH', dirname(__DIR__));
define('VIEW_PATH', BASE_PATH . '/views');

ob_start();
ob_implicit_flush(false);

date_default_timezone_set('Asia/Tehran');

// ── ۲. بارگذاری .env ────────────────────────────────────────────
$envPath = BASE_PATH . '/.env';
if (file_exists($envPath)) {
    $env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
    if ($env === false) {
        die('.env file is invalid');
    }
    $appDebug = filter_var($env['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($appDebug) {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
    } else {
        error_reporting(0);
        ini_set('display_errors', '0');
    }
} else {
    // قبل از لود config، حداقل خطاها رو نشان بده
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// ── ۳. Security Headers ──────────────────────────────────────────
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

// CORS - فقط برای production از APP_URL استفاده کنید
$allowedOrigin = $env['APP_URL'] ?? 'http://localhost';
if ($env['APP_ENV'] === 'production') {
    header("Access-Control-Allow-Origin: {$allowedOrigin}");
} else {
    // در development می‌توانید * استفاده کنید
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

header(
    "Content-Security-Policy: default-src 'self'; " .
    "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://code.jquery.com https://www.google.com https://www.gstatic.com; " .
    "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; " .
    "font-src 'self' https://fonts.gstatic.com; " .
    "img-src 'self' data: https:; " .
    "frame-src https://www.google.com; " .
    "connect-src 'self' https://www.google.com;"
);

// ── ۴. Autoloader ────────────────────────────────────────────────
// Autoloader داخلی vendor/autoload.php (Composer) را لود می‌کند
// که شامل PHPMailer، Core\\ و App\\ و helpers می‌شود
require_once BASE_PATH . '/core/Autoloader.php';
\Core\Autoloader::register();

// ── ۵. Helpers ───────────────────────────────────────────────────
// از طریق composer autoload (files section در composer.json) لود می‌شوند

// ── ۶. Application ───────────────────────────────────────────────
//
//  ✅ تمام این‌ها داخل Application::__construct() انجام می‌شه:
//      - ExceptionHandler::register()   (یک‌بار، نه دوبار)
//      - Session::getInstance()->start()
//      - Database::getInstance()
//      - Container::getInstance() + registerCoreBindings()
//      - Maintenance Mode check
//
//  ❌ قبلاً اینجا بودند و اشتباه بود:
//      - new SettingService()->clearCache()   → قبل از DB init
//      - Session::getInstance()->start()      → قبل از Application
//      - ExceptionHandler::register()         → دوبار register
//
$app = \Core\Application::getInstance();

// ── ۷. اطمینان از وجود storage directories ──────────────────────
//    (فقط mkdir — هیچ DB call نیست)
$storageDirs = [
    BASE_PATH . '/storage/uploads/kyc',
    BASE_PATH . '/storage/cache',
    BASE_PATH . '/storage/logs',
];
foreach ($storageDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// ── ۸. Routes ────────────────────────────────────────────────────
require_once BASE_PATH . '/routes/routes.php';

// ── ۹. Run ───────────────────────────────────────────────────────
$app->run();

// ── ۱۰. Flush ───────────────────────────────────────────────────
ob_end_flush();
