<?php

use Core\Session;

if (!function_exists('auth')) {
    function auth(): ?object
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $session = \Core\Session::getInstance();
        if (!$session->has('user_id')) {
            $cached = null;
            return null;
        }

        $userId = (int)$session->get('user_id');
        $cached = (new \App\Models\User())->findById($userId) ?: null;

        return $cached;
    }
}

if (!function_exists('auth_user')) {
    function auth_user()
    {
        $userId = app()->session->get('user_id');
        
        if (!$userId) {
            return null;
        }
        
        static $user = null;

        if ($user === null) {
            $user = (new \App\Models\User())->findById($userId);
        }

        return $user;
    }
}

function user_id(): ?int
{
    $session = Session::getInstance();
    $id = $session->get('user_id');
    return $id ? (int)$id : null;
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        $session = Session::getInstance();
        return ($session->get('user_role') === 'admin');
    }
}

function is_kyc_verified(?int $userId = null): bool
{
    $userId = $userId ?? user_id();
    if (!$userId) return false;

    $user = db()->query("SELECT kyc_status FROM users WHERE id = ?", [$userId])->fetch();
    return $user && $user->kyc_status === 'verified';
}
