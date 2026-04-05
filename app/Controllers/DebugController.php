<?php

namespace App\Controllers;

use App\Controllers\BaseController;

class DebugController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    public function router(): void
    {
                
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!\in_array($ip, ['127.0.0.1', '::1'], true)) {
            $this->response->html('Forbidden', 403);
            return;
        }

        // اعتبارسنجی و sanitize ورودی path — جلوگیری از Path Traversal
        $rawPath = $_GET['path'] ?? '/file/view/kyc/test.jpg';

        // فقط مسیرهایی با فرمت /file/view/{folder}/{filename} مجاز هستند
        $safe   = '#^/file/view/([^/]+)/([^/]+)$#u';
        $strict = '#^/file/view/(\w+)/(\w+)$#u';

        // اطمینان از عدم وجود null byte، double-dot و کاراکترهای خطرناک
        $path = preg_replace('/[\x00-\x1f\x7f]/', '', $rawPath);
        $path = str_replace(['..', '\\', "\0"], '', $path);
        $path = '/' . ltrim($path, '/');

        $m1 = [];
        $m2 = [];

        $out = "=== APP ROUTER DEBUG ===\n";
        $out .= "IP: {$ip}\n";
        $out .= "Test path: {$path}\n\n";

        $out .= "[SAFE ([^/]+)] => " . (string)\preg_match($safe, $path, $m1) . "\n";
        $out .= "Matches: " . \json_encode($m1, JSON_UNESCAPED_UNICODE) . "\n\n";

        $out .= "[STRICT (\\w+)] => " . (string)\preg_match($strict, $path, $m2) . "\n";
        $out .= "Matches: " . \json_encode($m2, JSON_UNESCAPED_UNICODE) . "\n\n";

        // اسکن Router.php برای دیدن اینکه placeholder ها چطور تبدیل میشن
        $routerFile = __DIR__ . '/../../core/Router.php';
        $out .= "Router.php: {$routerFile}\n";
        if (\file_exists($routerFile)) {
            $lines = \file($routerFile);
            $out .= "---- lines containing preg_replace/placeholder ----\n";
            foreach ($lines as $i => $line) {
                $l = \trim($line);
                if (\strpos($l, 'preg_replace') !== false && (\strpos($l, '{') !== false || \strpos($l, '\\{') !== false)) {
                    $out .= \str_pad((string)($i+1), 5, ' ', STR_PAD_LEFT) . " | " . $l . "\n";
                }
            }
        } else {
            $out .= "Router.php NOT FOUND\n";
        }

        $this->response->html('<pre style="direction:ltr;text-align:left;">' . \htmlspecialchars($out, ENT_QUOTES, 'UTF-8') . '</pre>');
    }
}