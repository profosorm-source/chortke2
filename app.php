<?php

/**
 * app.php (ریشه پروژه)
 *
 * این فایل نقطه ورود نیست — public/index.php نقطه ورود اصلی است.
 * این فایل برای فراخوانی از CLI یا سرویس‌های خارجی استفاده می‌شود.
 *
 * ─── ترتیب صحیح ───────────────────────────────────────────────
 *   ۱. BASE_PATH
 *   ۲. Autoloader (Core\Autoloader — نه vendor/autoload)
 *   ۳. Helpers
 *   ۴. Application::getInstance()
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__);
}

// Autoloader داخلی پروژه
require_once BASE_PATH . '/core/Autoloader.php';
\Core\Autoloader::register();

// Helpers
require_once BASE_PATH . '/helpers/functions.php';
if (file_exists(BASE_PATH . '/helpers/label_helpers.php')) {
    require_once BASE_PATH . '/helpers/label_helpers.php';
}

// Application
$app = \Core\Application::getInstance();

return $app;
