<?php

function url(string $path = ''): string
{
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    $scriptName = $_SERVER['SCRIPT_NAME'];
    $basePath = str_replace('/public/index.php', '', $scriptName);
    $basePath = str_replace('\\', '/', $basePath);
    
    $baseUrl = $protocol . '://' . $host . $basePath;
    
    $path = '/' . ltrim($path, '/');
    
    return rtrim($baseUrl, '/') . $path;
}

function asset(string $path): string
{
    $path = ltrim($path, '/');
    
    $baseUrl = config('app.url', 'http://localhost/chortke');
    $baseUrl = rtrim($baseUrl, '/');
    
    return $baseUrl . '/' . $path;
}

if (!function_exists('redirect')) {
    function redirect(string $path): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
            $appUrl  = env('APP_URL', '');
            $appHost = parse_url($appUrl, PHP_URL_HOST) ?? '';
            $pathHost = parse_url($path, PHP_URL_HOST) ?? '';

            $isSameHost = ($pathHost === $appHost)
                || str_ends_with($pathHost, '.' . $appHost);

            if (!$isSameHost) {
                header('Location: ' . rtrim($appUrl, '/') . '/');
                exit;
            }

            header("Location: {$path}");
            exit;
        }
        
        $basePath = env('APP_BASE_PATH', '');
        $url = rtrim($basePath, '/') . '/' . ltrim($path, '/');
        
        header("Location: {$url}");
        exit;
    }
}

if (!function_exists('back')) {
    function back()
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? url();
        redirect($referer);
    }
}

if (!function_exists('path')) {
    function path(string $path = ''): string
    {
        return \Core\PathResolver::getInstance()->path($path);
    }
}

if (!function_exists('abort')) {
    function abort($code = 404, $message = '')
    {
        http_response_code($code);
        
        $errorPage = __DIR__ . '/../views/errors/' . $code . '.php';
        
        if (file_exists($errorPage)) {
            require $errorPage;
        } else {
            echo "<h1>Error {$code}</h1>";
            if ($message) {
                echo "<p>{$message}</p>";
            }
        }
        
        exit;
    }
}
