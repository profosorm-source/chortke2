<?php

/**
 * ═══════════════════════════════════════════════════════════════
 *  Security Helpers - پروژه چرتکه
 * ═══════════════════════════════════════════════════════════════
 *  توابع امنیتی اضافی
 * ═══════════════════════════════════════════════════════════════
 */

/**
 * Sanitize Input
 */
if (!function_exists('sanitize')) {
    function sanitize(mixed $input): mixed
    {
        if (is_array($input)) {
            return array_map('sanitize', $input);
        }
        
        if (is_string($input)) {
            return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
        }
        
        return $input;
    }
}

/**
 * بررسی IP معتبر
 */
if (!function_exists('is_valid_ip')) {
function is_valid_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP) !== false;
}
}

/**
 * دریافت IP واقعی کاربر
 */
if (!function_exists('get_real_ip')) {
function get_real_ip(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR'
    ];
    
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header]) && is_valid_ip($_SERVER[$header])) {
            return $_SERVER[$header];
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
}

/**
 * دریافت User Agent
 */
if (!function_exists('get_user_agent')) {
function get_user_agent(): string
{
    return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
}
}

/**
 * تولید Hash امن
 */
if (!function_exists('secure_hash')) {
function secure_hash(string $data, string $algo = 'sha256'): string
{
    $allowedAlgos = ['sha256', 'sha384', 'sha512', 'blake2b'];
    if (!in_array($algo, $allowedAlgos, true)) {
        $algo = 'sha256';
    }
    return hash_hmac($algo, $data, (string)env('APP_KEY', ''));
}
}

/**
 * بررسی قدرت Password
 */
if (!function_exists('is_strong_password')) {
function is_strong_password(string $password): bool
{
    if (strlen($password) < 8) return false;
    if (!preg_match('/[A-Z]/', $password)) return false;
    if (!preg_match('/[a-z]/', $password)) return false;
    if (!preg_match('/[0-9]/', $password)) return false;
    if (!preg_match('/[\W_]/', $password)) return false;
    return true;
}
}

/**
 * فیلتر Filename برای آپلود
 */
if (!function_exists('safe_filename')) {
function safe_filename(string $filename): string
{
    // حذف path traversal
    $filename = basename($filename);
    // فقط حروف، اعداد، خط تیره، زیرخط و نقطه مجاز است
    $filename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename);
    // حداکثر طول
    if (strlen($filename) > 200) {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $filename = substr($filename, 0, 195) . ($ext ? '.' . $ext : '');
    }
    return $filename;
}
}

/**
 * بررسی Extension فایل
 */
if (!function_exists('is_allowed_extension')) {
function is_allowed_extension(string $filename, array $allowed = []): bool
{
    if (empty($allowed)) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
    }
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, $allowed, true);
}
}

/**
 * دریافت IP کاربر با پشتیبانی از Proxy و CDN
 * این تابع برای logging تراکنش‌های مالی استفاده می‌شود
 * 
 * @return string IP address (IPv4 or IPv6)
 */
if (!function_exists('get_client_ip')) {
function get_client_ip(): string
{
    // FIX S-1: IP Spoofing — هدرهای X-Forwarded-For و HTTP_CLIENT_IP
    // توسط هر کسی قابل جعل هستند.
    // فقط زمانی به این هدرها اعتماد می‌کنیم که REMOTE_ADDR در لیست
    // trusted proxy‌های تنظیم‌شده در .env باشد.

    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // لیست پروکسی‌های معتبر از env (کاماسپیس یا فاصله جداشده)
    $trustedProxiesEnv = env('TRUSTED_PROXIES', '');
    $trustedProxies = array_filter(array_map('trim', explode(',', $trustedProxiesEnv)));

    // اگر هیچ trusted proxy تعریف نشده، فقط REMOTE_ADDR قابل اعتماد است
    if (empty($trustedProxies)) {
        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '0.0.0.0';
    }

    // آیا REMOTE_ADDR یکی از trusted proxy‌هاست؟
    if (!in_array($remoteAddr, $trustedProxies, true)) {
        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '0.0.0.0';
    }

    // REMOTE_ADDR یک proxy معتبر است — هدرهای forwarded قابل اعتمادند
    $forwardedHeaders = [
        'HTTP_CF_CONNECTING_IP',  // Cloudflare
        'HTTP_TRUE_CLIENT_IP',    // Cloudflare Enterprise
        'HTTP_X_REAL_IP',         // Nginx proxy
        'HTTP_X_FORWARDED_FOR',   // Standard forwarded
    ];

    foreach ($forwardedHeaders as $header) {
        if (!empty($_SERVER[$header])) {
            // X-Forwarded-For می‌تواند چند IP داشته باشد: client, proxy1, proxy2
            // آخرین IP که توسط proxy معتبر ما اضافه شده، واقعی‌ترین است
            $ips = array_map('trim', explode(',', $_SERVER[$header]));
            // از آخر به اول بررسی کن تا اولین IP غیر-proxy بیابیم
            foreach (array_reverse($ips) as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $trustedProxies, true)) {
                    return $ip;
                }
            }
        }
    }

    return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '0.0.0.0';
}
}

/**
 * تولید Device Fingerprint برای شناسایی دستگاه کاربر
 * این fingerprint برای تشخیص fraud و suspicious activity استفاده می‌شود
 * 
 * @return string SHA256 hash of device characteristics
 */
if (!function_exists('generate_device_fingerprint')) {
function generate_device_fingerprint(): string
{
    // جمع‌آوری اطلاعات دستگاه
    $components = [
        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'Unknown',
        $_SERVER['HTTP_ACCEPT_ENCODING'] ?? 'Unknown',
        $_SERVER['HTTP_ACCEPT'] ?? 'Unknown',
    ];
    
    // افزودن IP به fingerprint (اختیاری - بسته به use case)
    // $components[] = get_client_ip();
    
    // Screen resolution اگر از جاوااسکریپت ارسال شده باشد
    if (isset($_SERVER['HTTP_X_SCREEN_RESOLUTION'])) {
        $components[] = $_SERVER['HTTP_X_SCREEN_RESOLUTION'];
    }
    
    // Timezone
    if (isset($_SERVER['HTTP_X_TIMEZONE'])) {
        $components[] = $_SERVER['HTTP_X_TIMEZONE'];
    }
    
    // Canvas fingerprint اگر ارسال شده باشد
    if (isset($_SERVER['HTTP_X_CANVAS_FP'])) {
        $components[] = $_SERVER['HTTP_X_CANVAS_FP'];
    }
    
    // ترکیب و hash کردن
    $fingerprint = implode('|', $components);
    
    return hash('sha256', $fingerprint);
}
}

/**
 * دریافت یا تولید Request ID یکتا برای trace کردن درخواست
 * این ID در تمام لاگ‌ها و تراکنش‌های یک درخواست یکسان است
 * 
 * @param bool $reset ایجاد request ID جدید
 * @return string Unique request identifier
 */
if (!function_exists('get_request_id')) {
function get_request_id(bool $reset = false): string
{
    static $requestId = null;

    if ($requestId === null || $reset) {
        // FIX S-6: Log Injection — مقدار header را sanitize می‌کنیم
        // فقط کاراکترهای hex، خط تیره و زیرخط مجاز هستند
        if (isset($_SERVER['HTTP_X_REQUEST_ID']) && !empty($_SERVER['HTTP_X_REQUEST_ID'])) {
            $raw = (string)$_SERVER['HTTP_X_REQUEST_ID'];
            // فقط اگر فرمت مجاز داشت استفاده کن
            if (preg_match('/^[a-zA-Z0-9\-_]{8,64}$/', $raw)) {
                $requestId = $raw;
            } else {
                // فرمت نامعتبر — ID جدید تولید کن
                $requestId = sprintf('REQ_%s_%s', date('YmdHis'), bin2hex(random_bytes(8)));
            }
        } else {
            $requestId = sprintf('REQ_%s_%s', date('YmdHis'), bin2hex(random_bytes(8)));
        }

        $_SERVER['REQUEST_ID'] = $requestId;
    }

    return $requestId;
}
}

/**
 * ذخیره متادیتای درخواست برای استفاده در تراکنش‌ها
 * این تابع تمام اطلاعات لازم برای audit trail را جمع‌آوری می‌کند
 * 
 * @param array $additionalData داده‌های اضافی
 * @return array Complete request metadata
 */
if (!function_exists('get_request_metadata')) {
}

/**
 * Verify Device Fingerprint - بررسی تطابق fingerprint با درخواست قبلی
 * برای تشخیص account takeover استفاده می‌شود
 * 
 * @param int $userId
 * @param string $currentFingerprint
 * @return array ['is_suspicious' => bool, 'reason' => string|null]
 */
if (!function_exists('verify_device_fingerprint')) {
function verify_device_fingerprint(int $userId, string $currentFingerprint): array
{
    try {
        // از طریق Transaction Model — بدون دسترسی مستقیم به DB
        $transactionModel    = new \App\Models\Transaction();
        $knownFingerprints   = $transactionModel->getKnownDeviceFingerprints($userId, 30, 5);

        if (!empty($knownFingerprints) && !in_array($currentFingerprint, $knownFingerprints)) {
            return [
                'is_suspicious' => true,
                'reason'        => 'New device detected',
                'known_devices' => count($knownFingerprints),
            ];
        }

        return ['is_suspicious' => false];

    } catch (\Exception $e) {
        error_log("Error verifying device fingerprint: " . $e->getMessage());
        return ['is_suspicious' => false, 'error' => $e->getMessage()];
    }
}
}

/**
 * Sanitize URL - جلوگیری از تزریق javascript: و data: protocol
 *
 * تابع e() (htmlspecialchars) جلوی href="javascript:..." را نمی‌گیرد.
 * این تابع مطمئن می‌شود که URL تنها با http:// یا https:// شروع می‌شود.
 * در غیر این صورت '#' برمی‌گرداند.
 *
 * @param mixed $url
 * @return string
 */
if (!function_exists('sanitize_url')) {
    function sanitize_url(mixed $url): string
    {
        if (empty($url)) {
            return '#';
        }

        $url = trim((string)$url);

        // پروتکل‌های مجاز
        if (preg_match('/^https?:\/\//i', $url)) {
            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        }

        // رد هر چیزی که با javascript:, data:, vbscript: و مشابه شروع شود
        return '#';
    }
}

/**
 * Log Security Event - ثبت رویداد امنیتی
 * 
 * @param string $eventType
 * @param int|null $userId
 * @param array $details
 */
if (!function_exists('log_security_event')) {
function log_security_event(string $eventType, ?int $userId, array $details = []): void
{
    try {
        // از طریق SecurityEvent Model — بدون دسترسی مستقیم به DB
        $securityEventModel = new \App\Models\SecurityEvent();
        $securityEventModel->log([
            'event_type'         => $eventType,
            'user_id'            => $userId,
            'ip_address'         => get_client_ip(),
            'device_fingerprint' => generate_device_fingerprint(),
            'request_id'         => get_request_id(),
            'details'            => $details,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

    } catch (\PDOException $e) {
        // اگر جدول وجود نداشت، فقط لاگ کن
        error_log("🔐 Security Event [{$eventType}] | User: {$userId} | IP: " . get_client_ip() . " | " . json_encode($details));
    }
}
}