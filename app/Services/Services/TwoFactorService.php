<?php

namespace App\Services;

use App\Models\User;
use App\Models\TwoFactorCode;

/**
 * Two Factor Authentication Service
 */
class TwoFactorService
{
    private User $userModel;
    private TwoFactorCode $codeModel;

    private \Core\Session $session;

    public function __construct(User $userModel, TwoFactorCode $codeModel, \Core\Session $session)
    {
        $this->userModel = $userModel;
        $this->codeModel = $codeModel;
        $this->session   = $session;
    }

    /**
     * تولید Secret Key
     */
    public function generateSecret(): string
    {
        $secret = '';
        $chars  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        for ($i = 0; $i < 32; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $secret;
    }

    /**
     * تولید QR Code URL
     */
    public function getQRCodeUrl(string $username, string $secret): string
    {
        $appName    = urlencode(config('app.name'));
        $username   = urlencode($username);
        $otpauthUrl = "otpauth://totp/{$appName}:{$username}?secret={$secret}&issuer={$appName}";

        return "https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=" . urlencode($otpauthUrl);
    }

    /**
     * تایید کد 2FA (TOTP یا کد بازیابی)
     */
    public function verifyCode(string $secret, string $code): bool
    {
        $timeSlice = floor(time() / 30);

        for ($i = -1; $i <= 1; $i++) {
            if ($this->timingSafeEquals($this->generateTOTP($secret, $timeSlice + $i), $code)) {
                return true;
            }
        }

        return $this->verifyRecoveryCode($code);
    }

    /**
     * تولید کدهای بازیابی
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }
        return $codes;
    }

    /**
     * فعالسازی 2FA — از طریق TwoFactorCode Model
     */
    public function enable(int $userId, string $code): array
    {
        $user = $this->userModel->find($userId);

        if (!$user || !$user['two_factor_secret']) {
            return ['success' => false, 'message' => 'Secret key یافت نشد.'];
        }

        if (!$this->verifyCode($user['two_factor_secret'], $code)) {
            return ['success' => false, 'message' => 'کد وارد شده نامعتبر است.'];
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        // ذخیره کدها از طریق Model
        $this->saveRecoveryCodes($userId, $recoveryCodes);

        // فعالسازی از طریق User Model
        $this->userModel->update($userId, ['two_factor_enabled' => 1]);

        return [
            'success'        => true,
            'message'        => 'احراز هویت دو مرحله‌ای فعال شد.',
            'recovery_codes' => $recoveryCodes,
        ];
    }

    /**
     * غیرفعالسازی 2FA — از طریق TwoFactorCode Model
     */
    public function disable(int $userId, string $password): array
    {
        if (!$this->userModel->verifyPassword($userId, $password)) {
            return ['success' => false, 'message' => 'رمز عبور اشتباه است.'];
        }

        $this->userModel->disable2FA($userId);

        // حذف کدها از طریق Model
        $this->codeModel->deleteByUserId($userId);

        return ['success' => true, 'message' => 'احراز هویت دو مرحله‌ای غیرفعال شد.'];
    }

    // ─────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * ذخیره کدهای بازیابی — از طریق TwoFactorCode Model
     */
    private function saveRecoveryCodes(int $userId, array $codes): void
    {
        // حذف کدهای قبلی از طریق Model
        $this->codeModel->deleteByUserId($userId);

        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));

        foreach ($codes as $code) {
            $this->codeModel->insertCode($userId, hash('sha256', $code), $expiresAt);
        }
    }

    /**
     * بررسی کد بازیابی — از طریق TwoFactorCode Model
     */
    private function verifyRecoveryCode(string $code): bool
    {
        // BUG FIX 9: کلید صحیح session برای مرحله 2FA (باید pending_2fa_user باشد)
        $userId = $this->session->get('pending_2fa_user') ?? $this->session->get('user_id');

        if (!$userId) {
            return false;
        }

        $hashedCode = hash('sha256', strtoupper($code));

        // جستجو از طریق Model
        $record = $this->codeModel->findValidCode((int)$userId, $hashedCode);

        if ($record) {
            // علامت‌گذاری از طریق Model
            $this->codeModel->markAsUsed($record['id']);

            logger('info', '2FA recovery code used', [
                'user_id' => $userId,
                'code_id' => $record['id'],
            ]);

            return true;
        }

        return false;
    }

    private function generateTOTP(string $secret, int $timeSlice): string
    {
        $secret = $this->base32Decode($secret);
        $time   = pack('N*', 0) . pack('N*', $timeSlice);
        $hash   = hash_hmac('sha1', $time, $secret, true);
        $offset = ord($hash[19]) & 0xf;

        $code = (
            ((ord($hash[$offset + 0]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;

        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $base32chars        = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32charsFlipped = array_flip(str_split($base32chars));
        $paddedSecret       = str_pad($secret, strlen($secret) + (8 - strlen($secret) % 8) % 8, '=');

        $bits = '';
        for ($i = 0; $i < strlen($paddedSecret); $i++) {
            if ($paddedSecret[$i] === '=') {
                continue;
            }
            $bits .= sprintf('%05b', $base32charsFlipped[$paddedSecret[$i]]);
        }

        $bytes = '';
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $bytes .= chr(bindec(substr($bits, $i, 8)));
        }

        return $bytes;
    }

    private function timingSafeEquals(string $safe, string $user): bool
    {
        return function_exists('hash_equals')
            ? hash_equals($safe, $user)
            : (strlen($safe) === strlen($user) && substr_count($safe ^ $user, "\0") === strlen($safe));
    }
}
