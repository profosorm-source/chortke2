<?php

namespace App\Services;

use App\Models\UserSession;


class SessionService
{
    private UserSession $sessionModel;

    public function __construct(
        \App\Models\UserSession $sessionModel
    )
    {
        $this->sessionModel = $sessionModel;
    }

    /**
     * ثبت نشست جدید
     */
    public function recordSession(int $userId, string $sessionId, ?array $geoData = null): bool
    {
        // پارس اطلاعات دستگاه
        $deviceInfo = $this->parseUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '');

        // اگر session از قبل در دیتابیس موجود است فقط last_activity را آپدیت کن
        $existing = $this->sessionModel->findBySessionId($sessionId);
        if ($existing) {
            return $this->sessionModel->updateActivity($sessionId);
        }

        return (bool)$this->sessionModel->create([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'ip_address' => get_client_ip(),
            'user_agent' => get_user_agent(),
            'device_type' => $deviceInfo['device_type'],
            'browser' => $deviceInfo['browser'],
            'os' => $deviceInfo['os'],
            'country' => $geoData['country'] ?? null,
            'city' => $geoData['city'] ?? null,
            'latitude' => $geoData['latitude'] ?? null,
            'longitude' => $geoData['longitude'] ?? null,
            'fingerprint' => generate_device_fingerprint()
        ]);
    }

    /**
     * به‌روزرسانی فعالیت
     */
    public function updateActivity(string $sessionId): bool
    {
        return $this->sessionModel->updateActivity($sessionId);
    }

    /**
     * دریافت نشست‌های فعال
     */
    public function getActiveSessions(int $userId): array
    {
        return $this->sessionModel->getActiveSessions($userId);
    }

    /**
     * حذف نشست خاص
     */
    public function terminateSession(int $sessionId, int $userId): array
    {
        $session = $this->sessionModel->findBySessionId($sessionId);

        if (!$session || $session->user_id !== $userId) {
            return ['success' => false, 'message' => 'نشست یافت نشد'];
        }

        $result = $this->sessionModel->deactivate($session->id);

        if ($result) {
            return ['success' => true, 'message' => 'نشست با موفقیت حذف شد'];
        }

        return ['success' => false, 'message' => 'خطا در حذف نشست'];
    }

    /**
     * پاک‌سازی نشست‌های منقضی شده
     */
    public function cleanupSessions(): bool
    {
        $this->sessionModel->deleteExpired();
        return $this->sessionModel->deleteInactive();
    }

    /**
     * پارس User-Agent
     */
    private function parseUserAgent(string $userAgent): array
    {
        $deviceType = 'desktop';
        $browser = 'نامشخص';
        $os = 'نامشخص';

        // تشخیص دستگاه
        if (\preg_match('/mobile|android|iphone|ipad/i', $userAgent)) {
            $deviceType = 'mobile';
        } elseif (\preg_match('/tablet|ipad/i', $userAgent)) {
            $deviceType = 'tablet';
        }

        // تشخیص مرورگر
        if (\preg_match('/Chrome/i', $userAgent)) {
            $browser = 'Chrome';
        } elseif (\preg_match('/Firefox/i', $userAgent)) {
            $browser = 'Firefox';
        } elseif (\preg_match('/Safari/i', $userAgent)) {
            $browser = 'Safari';
        } elseif (\preg_match('/Edge/i', $userAgent)) {
            $browser = 'Edge';
        }

        // تشخیص سیستم‌عامل
        if (\preg_match('/Windows/i', $userAgent)) {
            $os = 'Windows';
        } elseif (\preg_match('/Mac/i', $userAgent)) {
            $os = 'macOS';
        } elseif (\preg_match('/Linux/i', $userAgent)) {
            $os = 'Linux';
        } elseif (\preg_match('/Android/i', $userAgent)) {
            $os = 'Android';
        } elseif (\preg_match('/iOS|iPhone|iPad/i', $userAgent)) {
            $os = 'iOS';
        }

        return [
            'device_type' => $deviceType,
            'browser' => $browser,
            'os' => $os
        ];
    }
    
    /**
     * شمارش session های همزمان
     */
    public function countConcurrentSessions(int $userId): int
    {
        return $this->sessionModel->countActive($userId);
    }
    
    /**
     * پایان دادن به session
     */
    public function endSession(string $sessionId): void
    {
        $session = $this->sessionModel->findBySessionId($sessionId);
        if ($session) {
            $this->sessionModel->deactivate($session->id);
        }
    }
    
    /**
     * دریافت آخرین IP کاربر
     */
    public function getLastIP(int $userId): ?string
    {
        $sessions = $this->sessionModel->getActiveSessions($userId);
        return $sessions[0]->ip_address ?? null;
    }
    
    /**
     * دریافت آخرین User-Agent کاربر
     */
    public function getLastUserAgent(int $userId): ?string
    {
        $sessions = $this->sessionModel->getActiveSessions($userId);
        return $sessions[0]->user_agent ?? null;
    }
}