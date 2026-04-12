<?php

if (!function_exists('env')) {
    function env($key, $default = null)
    {
        static $env = null;
        
        if ($env === null) {
            $envFile = __DIR__ . '/../.env';
            if (file_exists($envFile)) {
                $env = parse_ini_file($envFile);
            } else {
                $env = [];
            }
        }
        
        if (isset($env[$key])) {
            $value = $env[$key];
            
            if ($value === 'true') return true;
            if ($value === 'false') return false;
            if ($value === 'null') return null;
            
            return $value;
        }
        
        return $default;
    }
}

if (!function_exists('config')) {
    function config(string $key, $default = null)
    {
        static $config = null;
        
        if ($config === null) {
            $configFile = __DIR__ . '/../config/config.php';
            if (file_exists($configFile)) {
                $config = require $configFile;
            } else {
                $config = [];
            }
        }
        
        $keys = explode('.', $key);
        $value = $config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
}

if (!function_exists('feature')) {
    function feature(string $name, ?int $userId = null): bool
    {
        static $service = null;
        
        if ($service === null) {
            $container = \Core\Container::getInstance();
            $service = $container->make(\App\Services\FeatureFlagService::class);
        }
        
        return $service->isEnabled($name, $userId);
    }
}

if (!function_exists('app')) {
    function app()
    {
        return \Core\Application::getInstance();
    }
}

function db(): \Core\Database
{
    return \Core\Container::getInstance()->make(\Core\Database::class);
}

function cache(): \Core\Cache
{
    return \Core\Cache::getInstance();
}

function settings(bool $forceReload = false): array
{
    static $data = null;

    if ($forceReload) {
        $data = null;
    }

    if ($data !== null) return $data;

    $container = \Core\Container::getInstance();
    $service = $container->make(\App\Services\SettingService::class);
    $data = $service->load();
    return $data;
}

function setting(string $key, mixed $default = null): mixed
{
    $all = settings();
    return $all[$key] ?? $default;
}
