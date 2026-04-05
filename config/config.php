<?php
/**
 * تنظیمات اصلی سیستم
 * 
 * این فایل تنظیمات از .env را بارگذاری می‌کند
 */

return [
    'app' => [
        'name' => env('APP_NAME', 'Chortke'),
        'env' => env('APP_ENV', 'production'),
        'debug' => env('APP_DEBUG', false),
        'url' => env('APP_URL', 'http://localhost'),
        'timezone' => env('APP_TIMEZONE', 'Asia/Tehran'),
        'key' => env('APP_KEY', ''),
    ],
    
    'database' => [
        'host' => env('DB_HOST', 'localhost'),
        'port' => env('DB_PORT', 3306),
        'name' => env('DB_NAME', 'chortke'),
        'user' => env('DB_USER', 'root'),
        'pass' => env('DB_PASS', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
    ],
    
    'session' => [
        'lifetime' => env('SESSION_LIFETIME', 7200),
        'name' => 'CHORTKE_SESSION',
        'secure' => env('APP_ENV') === 'production',
        'httponly' => true,
        'samesite' => 'Strict',
    ],
    
    'csrf' => [
        'token_name' => env('CSRF_TOKEN_NAME', '_csrf_token'),
        'token_length' => 64,
    ],
    
    'rate_limit' => [
        'max_attempts' => env('RATE_LIMIT_MAX_ATTEMPTS', 5),
        'decay_minutes' => env('RATE_LIMIT_DECAY_MINUTES', 1),
    ],
    
    'upload' => [
        'max_size' => env('MAX_UPLOAD_SIZE', 10485760), // 10MB
        'allowed_images' => explode(',', env('ALLOWED_IMAGE_TYPES', 'jpg,jpeg,png,gif')),
        'allowed_videos' => explode(',', env('ALLOWED_VIDEO_TYPES', 'mp4,avi,mov')),
        'path' => __DIR__ . '/../public/uploads/',
    ],
    
    'mail' => [
        'driver' => env('MAIL_DRIVER', 'smtp'),
        'host' => env('MAIL_HOST'),
        'port' => env('MAIL_PORT', 587),
        'username' => env('MAIL_USERNAME'),
        'password' => env('MAIL_PASSWORD'),
        'encryption' => env('MAIL_ENCRYPTION', 'tls'),
        'from' => [
            'address' => env('MAIL_FROM_ADDRESS'),
            'name' => env('MAIL_FROM_NAME'),
        ],
    ],
    
    'payment' => [
        'zarinpal' => [
            'merchant_id' => env('ZARINPAL_MERCHANT_ID'),
        ],
        'nextpay' => [
            'api_key' => env('NEXTPAY_API_KEY'),
        ],
        'idpay' => [
            'api_key' => env('IDPAY_API_KEY'),
        ],
        'dgpay' => [
            'api_key' => env('DGPAY_API_KEY'),
        ],
    ],
    
    'crypto' => [
        'usdt' => [
            'bnb20' => env('USDT_BNB20_ADDRESS'),
            'trc20' => env('USDT_TRC20_ADDRESS'),
            'erc20' => env('USDT_ERC20_ADDRESS'),
            'ton' => env('USDT_TON_ADDRESS'),
            'sol' => env('USDT_SOL_ADDRESS'),
        ],
    ],
	'captcha' => [
  'recaptcha_site_key'   => env('RECAPTCHA_SITE_KEY', ''),
  'recaptcha_secret_key' => env('RECAPTCHA_SECRET_KEY', ''),
],
];