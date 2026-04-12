<?php

namespace App\Middleware;

use App\Services\AntiFraud\AccountTakeoverService;
use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AntiFraud\IPQualityService;
use App\Services\AntiFraud\SessionAnomalyService;
use App\Services\RiskDecisionService;
use App\Services\SessionService;
use App\Services\UserScoreService;
use Core\Request;
use Core\Response;

class AdvancedFraudMiddleware
{
    private BrowserFingerprintService $fingerprintService;
    private IPQualityService $ipQualityService;
    private SessionAnomalyService $sessionAnomalyService;
    private AccountTakeoverService $accountTakeoverService;
    private SessionService $sessionService;
    private UserScoreService $scoreService;
    private RiskDecisionService $decisionService;

    public function __construct(
        BrowserFingerprintService $fingerprintService,
        IPQualityService $ipQualityService,
        SessionAnomalyService $sessionAnomalyService,
        AccountTakeoverService $accountTakeoverService,
        SessionService $sessionService,
        UserScoreService $scoreService,
        RiskDecisionService $decisionService
    ) {
        $this->fingerprintService = $fingerprintService;
        $this->ipQualityService = $ipQualityService;
        $this->sessionAnomalyService = $sessionAnomalyService;
        $this->accountTakeoverService = $accountTakeoverService;
        $this->sessionService = $sessionService;
        $this->scoreService = $scoreService;
        $this->decisionService = $decisionService;
    }

    public function handle(Request $request, Response $response): bool
    {
        $session = app()->session;
        if (!$session->has('user_id')) {
            return true;
        }

        $userId = (int) $session->get('user_id');
        $ip = get_client_ip();
        $userAgent = get_user_agent();
        $sessionId = $session->getId();

        $geoData = $this->ipQualityService->getGeolocation($ip);
        $this->sessionService->updateActivity($sessionId);

        if (!$session->get('fraud_check_done')) {
            $this->sessionService->recordSession($userId, $sessionId, $geoData);
            $session->set('fraud_check_done', true);
        }

        if ($this->ipQualityService->isIPBlacklisted($ip)) {
            log_activity($userId, 'blocked_ip', 'IP در لیست سیاه');
            $session->destroy();
            $response->redirect(url('/login?error=blocked'));
            return false;
        }

        $ipCheck = $this->ipQualityService->check($ip);
        if ($ipCheck['is_suspicious']) {
            $this->ipQualityService->logIPCheck($userId, $ip, $ipCheck);
            $this->scoreService->incrementFraudRawScore($userId, (float) $ipCheck['score'] / 4, 'ip_quality', [
                'ip' => $ip,
                'reasons' => $ipCheck['reasons'],
            ]);

            if (!empty($ipCheck['details']['is_tor'])) {
                $this->ipQualityService->blacklistIP($ip, 'Tor Network', 86400 * 7);
                $session->destroy();
                $response->redirect(url('/login?error=tor_blocked'));
                return false;
            }
        }

        $sessionCheck = $this->sessionAnomalyService->analyze($userId, $sessionId);
        if ($sessionCheck['is_anomaly']) {
            $this->sessionAnomalyService->logAnomaly($userId, $sessionId, $sessionCheck);
            $this->scoreService->incrementFraudRawScore($userId, (float) $sessionCheck['score'] / 2, 'session_anomaly', [
                'anomalies' => $sessionCheck['anomalies'],
                'session_id' => $sessionId,
            ]);
        }

        $takeoverCheck = $this->accountTakeoverService->detect($userId, $ip, $userAgent);
        if ($takeoverCheck['is_takeover']) {
            $this->accountTakeoverService->logDetection($userId, $ip, $userAgent, $takeoverCheck);
            $this->scoreService->incrementFraudRawScore($userId, (float) $takeoverCheck['risk_score'] / 2, 'account_takeover', [
                'signals' => $takeoverCheck['signals'],
            ]);

            if ($takeoverCheck['action'] === 'notify') {
                notify($userId, 'warning', 'فعالیت مشکوکی از حساب شما شناسایی شد.');
            }
        }

        $decision = $this->decisionService->decide($userId, ['action' => 'general']);

        if ($decision['decision'] === 'block') {
            notify($userId, 'danger', 'به دلیل ریسک بالا، دسترسی شما موقتاً محدود شد.');
            $session->destroy();
            $response->redirect(url('/login?error=high_risk'));
            return false;
        }

        if ($decision['decision'] === 'challenge' && !$session->get('2fa_verified')) {
            $session->setFlash('warning', 'به دلیل ریسک امنیتی، لطفا تایید دو مرحله ای را تکمیل کنید.');
            $response->redirect(url('/verify-2fa'));
            return false;
        }

        return true;
    }
}