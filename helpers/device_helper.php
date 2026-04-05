<?php

function generate_device_fingerprint(): string
{
    $data = [
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
    ];
    
    return hash('sha256', implode('|', $data));
}
