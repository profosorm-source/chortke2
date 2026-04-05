<?php

namespace App\Middleware;

use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AntiFraud\IPQualityService;
use App\Services\AntiFraud\SessionAnomalyService;
use App\Services\AntiFraud\AccountTakeoverService;
use App\Services\SessionService;
use Core\Database;
use Core\Request;
use Core\Response;

/**
 * AdvancedFraudMiddleware
 *
 * میدلور تشخیص تقلب و فعالیت‌های مشکوک
 */
class AdvancedFraudMiddleware
{
    private BrowserFingerprintService $fingerprintService;
    private IPQualityService          $ipQualityService;
    private SessionAnomalyService     $sessionAnomalyService;
    private AccountTakeoverService    $accountTakeoverService;
    private SessionService            $sessionService;
    private Database                  $db;

    public function __construct(
        BrowserFingerprintService $fingerprintService,
        IPQualityService          $ipQualityService,
        SessionAnomalyService     $sessionAnomalyService,
        AccountTakeoverService    $accountTakeoverService,
        SessionService            $sessionService,
        Database                  $db
    ) {
        $this->fingerprintService    = $fingerprintService;
        $this->ipQualityService      = $ipQualityService;
        $this->sessionAnomalyService = $sessionAnomalyService;
        $this->accountTakeoverService = $accountTakeoverService;
        $this->sessionService        = $sessionService;
        $this->db                    = $db;
    }

    public function handle(Request $request, Response $response): bool
    {
        $session = app()->session;

        if (!$session->has('user_id')) {
            return true;
        }

        $userId    = (int)$session->get('user_id');
        $ip        = get_client_ip();
        $userAgent = get_user_agent();
        $sessionId = $session->getId();

        // ── ۰. به‌روزرسانی session ──────────────────────────────
        $geoData = $this->ipQualityService->getGeolocation($ip);
        $this->sessionService->updateActivity($sessionId);
        
        // اگر session جدیده، ثبتش کن
        if (!$session->get('fraud_check_done')) {
            $this->sessionService->recordSession($userId, $sessionId, $geoData);
            $session->set('fraud_check_done', true);
        }

        // ── ۱. بررسی IP در لیست سیاه ───────────────────────────
        if ($this->ipQualityService->isIPBlacklisted($ip)) {
            log_activity($userId, 'blocked_ip', 'IP در لیست سیاه');
            $session->destroy();
            $response->redirect(url('/login?error=blocked'));
            return false;
        }

        // ── ۲. بررسی IP Quality ───────────────────────────────────
        $ipCheck = $this->ipQualityService->check($ip);
        
        if ($ipCheck['is_suspicious']) {
            $this->ipQualityService->logIPCheck($userId, $ip, $ipCheck);
        }
        
        if ($ipCheck['score'] >= 80) {
            log_activity($userId, 'high_risk_ip', 'IP با ریسک بالا: ' . implode(', ', $ipCheck['reasons']));
            $session->setFlash('warning', 'فعالیت شما از IP مشکوک شناسایی شد.');
            
            // اگر از Tor استفاده می‌کند، مسدود کن
            if (isset($ipCheck['details']['is_tor']) && $ipCheck['details']['is_tor']) {
                $this->ipQualityService->blacklistIP($ip, 'Tor Network', 86400 * 7); // 7 روز
                $session->destroy();
                $response->redirect(url('/login?error=tor_blocked'));
                return false;
            }
        }

        // ── ۳. بررسی Session Anomaly ──────────────────────────────
        $sessionCheck = $this->sessionAnomalyService->analyze($userId, $sessionId);

        if ($sessionCheck['is_anomaly']) {
            $this->sessionAnomalyService->logAnomaly($userId, $sessionId, $sessionCheck);
            log_activity($userId, 'session_anomaly', implode(', ', $sessionCheck['anomalies']));

            $current  = $this->db->fetch("SELECT fraud_score FROM users WHERE id = ?", [$userId]);
            $newScore = ($current->fraud_score ?? 0) + ($sessionCheck['score'] / 2);
            $this->db->query("UPDATE users SET fraud_score = ? WHERE id = ?", [$newScore, $userId]);
        }

        // ── ۴. بررسی Account Takeover ─────────────────────────────
        $takeoverCheck = $this->accountTakeoverService->detect($userId, $ip, $userAgent);

        if ($takeoverCheck['is_takeover']) {
            $this->accountTakeoverService->logDetection($userId, $ip, $userAgent, $takeoverCheck);
            log_activity($userId, 'account_takeover_detected', implode(', ', $takeoverCheck['signals']));

            switch ($takeoverCheck['action']) {
                case 'block':
                    $this->db->query("UPDATE users SET active_status = 'suspended', fraud_score = 100 WHERE id = ?", [$userId]);
                    notify($userId, 'danger', 'به دلیل فعالیت مشکوک، حساب شما موقتاً مسدود شد.');
                    notify_admin("حساب کاربر #{$userId} به دلیل Account Takeover مسدود شد");
                    $session->destroy();
                    $response->redirect(url('/login?error=account_suspended'));
                    return false;

                case 'challenge':
                    if (!$session->get('2fa_verified')) {
                        $session->setFlash('warning', 'به دلیل فعالیت مشکوک، نیاز به تأیید هویت دارید.');
                        $response->redirect(url('/verify-2fa'));
                        return false;
                    }
                    break;

                case 'notify':
                    notify($userId, 'warning', 'فعالیت مشکوکی از حساب شما شناسایی شد.');
                    break;
            }
        }

        return true;
    }
}
