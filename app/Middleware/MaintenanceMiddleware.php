<?php

namespace App\Middleware;

use Core\Request;
use Core\Response;
use App\Models\SystemSetting;

class MaintenanceMiddleware
{
    /**
     * بررسی حالت تعمیر
     */
    public function handle(Request $request, \Closure $next)
    {
        $settingModel = new SystemSetting();
        $maintenanceMode = $settingModel->get('maintenance_mode', false);
        
        if (!$maintenanceMode) {
            return $next($request);
        }
        
        // استثناء برای ادمین
        if (auth() && is_admin()) {
            return $next($request);
        }
        
        // استثناء برای IP های خاص
        $allowedIPs = $settingModel->get('maintenance_allowed_ips', []);
        $clientIP = get_client_ip();
        
        if (in_array($clientIP, $allowedIPs)) {
            return $next($request);
        }
        
        // نمایش صفحه تعمیر
        $message = $settingModel->get('maintenance_message', 'سایت در حال بروزرسانی است...');
        
        return view('errors/maintenance', [
            'message' => $message
        ]);
    }
}