<?php

namespace App\Middleware;

use App\Models\Permission;
use Core\Session;
use Core\Response;

class PermissionMiddleware
{
    /**
     * بررسی دسترسی کاربر
     * 
     * @param string $permission slug دسترسی مورد نیاز
     * @return bool
     */
    public static function check(string $permission): bool
    {
		if (function_exists('is_admin') && is_admin()) {
    return true;
}
        $session = Session::getInstance();
        $userId = $session->get('user_id');
        
        if (!$userId) {
            return false;
        }
        
        // کش دسترسی‌ها در سشن
        $cachedPermissions = $session->get('user_permissions');
        $cacheTime = $session->get('permissions_cache_time');
        
        // کش 5 دقیقه‌ای
        if ($cachedPermissions === null || $cacheTime === null || (\time() - $cacheTime) > 300) {
            $permModel = new Permission();
            $cachedPermissions = $permModel->getUserPermissions($userId);
            $session->set('user_permissions', $cachedPermissions);
            $session->set('permissions_cache_time', \time());
        }
        
        // super_admin همه دسترسی‌ها را دارد
        $userRole = $session->get('user_role');
        if ($userRole === 'super_admin') {
            return true;
        }
        
        return \in_array($permission, $cachedPermissions, true);
    }
    
    /**
     * بررسی و توقف اگر دسترسی نداشت
     */
    public static function require(string $permission): void
    {
        if (!self::check($permission)) {
            $response = new Response();
            
            // اگر Ajax باشد
            if (self::isAjax()) {
                $response->json([
                    'success' => false,
                    'message' => 'شما دسترسی لازم برای انجام این عملیات را ندارید.'
                ], 403);
                exit;
            }
            
            // صفحه 403
            \http_response_code(403);
            include __DIR__ . '/../../views/errors/403.php';
            exit;
        }
    }
    
    /**
     * بررسی چند دسترسی (حداقل یکی)
     */
    public static function checkAny(array $permissions): bool
    {
        foreach ($permissions as $perm) {
            if (self::check($perm)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * بررسی چند دسترسی (همه)
     */
    public static function checkAll(array $permissions): bool
    {
        foreach ($permissions as $perm) {
            if (!self::check($perm)) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * پاکسازی کش دسترسی‌ها
     */
    public static function clearCache(): void
    {
        $session = Session::getInstance();
        $session->remove('user_permissions');
        $session->remove('permissions_cache_time');
    }
    
    /**
     * بررسی درخواست Ajax
     */
    private static function isAjax(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               \strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}