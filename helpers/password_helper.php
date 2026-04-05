<?php

if (!function_exists('hash_password')) {
    function hash_password($password)
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 2,
            ]);
        }

        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 14]);
    }
}

if (!function_exists('verify_password')) {
    function verify_password($password, $hash)
    {
        return password_verify($password, $hash);
    }
}
