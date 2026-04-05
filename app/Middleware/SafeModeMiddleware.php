<?php

namespace App\Middleware;

use Core\Request;
use Core\Response;
use App\Models\FeatureFlag;

class SafeModeMiddleware
{
    private FeatureFlag $featureModel;
    
    public function __construct()
    {
        $this->featureModel = new FeatureFlag();
    }
    
    /**
     * بررسی Safe Mode
     */
    public function handle(Request $request, \Closure $next)
    {
        $safeMode = $this->featureModel->isEnabled('safe_mode');
        
        if (!$safeMode) {
            return $next($request);
        }
        
        // استثناء برای ادمین
        if (auth() && is_admin()) {
            return $next($request);
        }
        
        // محدودیت‌ها در Safe Mode
        $uri = $request->uri();
        
        // جلوگیری از برداشت
        if (strpos($uri, '/wallet/withdraw') !== false) {
            $response = new Response();
            
            if ($request->isAjax()) {
                return $response->json([
                    'success' => false,
                    'message' => 'به دلیل بررسی‌های امنیتی، برداشت‌ها موقتاً غیرفعال است.'
                ]);
            }
            
            session()->setFlash('error', 'به دلیل بررسی‌های امنیتی، برداشت‌ها موقتاً غیرفعال است.');
            return redirect('/wallet');
        }
        
        return $next($request);
    }
}