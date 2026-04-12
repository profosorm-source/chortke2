<?php

/**
 * ═══════════════════════════════════════════════════════
 * Logging Helper Functions - سیستم یکپارچه
 * ═══════════════════════════════════════════════════════
 * 
 * همه helper ها از DI Container استفاده می‌کنن
 */

if (!function_exists('logger')) {
    /**
     * دریافت Logger Instance
     * 
     * @return \App\Services\Logger
     */
    function logger(): \App\Services\Logger
    {
        static $logger = null;

        if ($logger === null) {
            try {
                $container = \Core\Container::getInstance();
                $logger = $container->make(\App\Services\Logger::class);
            } catch (\Throwable $e) {
                // Fallback
                $db = \Core\Database::getInstance();
                $logger = new \App\Services\Logger($db);
            }
        }

        return $logger;
    }
}

if (!function_exists('activity_logger')) {
    /**
     * دریافت ActivityLogger Instance
     * 
     * @return \App\Services\ActivityLogger
     */
    function activity_logger(): \App\Services\ActivityLogger
    {
        static $activityLogger = null;

        if ($activityLogger === null) {
            try {
                $container = \Core\Container::getInstance();
                $activityLogger = $container->make(\App\Services\ActivityLogger::class);
            } catch (\Throwable $e) {
                // Fallback
                $db = \Core\Database::getInstance();
                $activityLog = new \App\Models\ActivityLog($db);
                $activityLogger = new \App\Services\ActivityLogger($db, $activityLog);
            }
        }

        return $activityLogger;
    }
}

if (!function_exists('audit_logger')) {
    /**
     * دریافت AuditLogger Instance
     * 
     * @return \App\Services\AuditLogger
     */
    function audit_logger(): \App\Services\AuditLogger
    {
        static $auditLogger = null;

        if ($auditLogger === null) {
            try {
                $container = \Core\Container::getInstance();
                $auditLogger = $container->make(\App\Services\AuditLogger::class);
            } catch (\Throwable $e) {
                // Fallback
                $db = \Core\Database::getInstance();
                $auditLogger = new \App\Services\AuditLogger($db);
            }
        }

        return $auditLogger;
    }
}
