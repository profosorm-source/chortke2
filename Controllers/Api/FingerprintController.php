<?php

namespace App\Controllers\Api;

use App\Services\AntiFraud\BrowserFingerprintService;
use Core\Request;
use Core\Response;

/**
 * FingerprintController
 * 
 * دریافت و پردازش browser fingerprint
 */
class FingerprintController extends BaseApiController
{
    private BrowserFingerprintService $fingerprintService;
    
    public function __construct(BrowserFingerprintService $fingerprintService)
    {
        parent::__construct();
        $this->fingerprintService = $fingerprintService;
    }
    
    /**
     * دریافت و ذخیره fingerprint
     */
    public function store(Request $request, Response $response)
    {
        $session = app()->session;
        
        // بررسی احراز هویت
        if (!$session->has('user_id')) {
            return $response->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }
        
        $userId = (int) $session->get('user_id');
        $data = $request->all();
        
        // تولید fingerprint
        $fingerprint = $this->fingerprintService->generate($data);
        
        // ذخیره
        $this->fingerprintService->store($userId, $fingerprint, $data);
        
        // تحلیل
        $analysis = $this->fingerprintService->analyze($userId, $fingerprint);
        
        // لاگ کردن در صورت مشکوک بودن
        if ($analysis['suspicious']) {
            $this->fingerprintService->logAnalysis($userId, $fingerprint, $analysis);
        }
        
        return $response->json([
            'success' => true,
            'fingerprint' => substr($fingerprint, 0, 16) . '...',
            'suspicious' => $analysis['suspicious']
        ]);
    }
}
