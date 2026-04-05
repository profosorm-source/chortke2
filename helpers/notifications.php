<?php

function notify_admins(string $type, string $title, string $message, ?string $url = null, ?array $data = null): int
{
    $container = \Core\Container::getInstance();
    $service = $container->make(\App\Services\NotificationService::class);
    $result = $service->sendToAll($title, $message, $type, $url, null, 'normal', $data);
    return $result['sent'] ?? 0;
}

function unread_notifications_count(?int $userId = null): int
{
    $container = \Core\Container::getInstance();
    $service = $container->make(\App\Services\NotificationService::class);
    return $service->getUnreadCount($userId);
}