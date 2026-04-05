<?php

namespace App\Controllers\Admin;

use Core\Database;
use Core\Request;
use Core\Response;
use App\Services\AntiFraud\IPQualityService;
use App\Services\AntiFraud\BrowserFingerprintService;

/**
 * FraudManagementController
 * 
 * مدیریت تقلب - بلاک/آنبلاک IP و Fingerprint
 */
class FraudManagementController extends BaseAdminController
{
    private Database $db;
    private IPQualityService $ipQualityService;
    private BrowserFingerprintService $fingerprintService;
    
    public function __construct(
        Database $db, 
        IPQualityService $ipQualityService,
        BrowserFingerprintService $fingerprintService
    ) {
        parent::__construct();
        $this->db = $db;
        $this->ipQualityService = $ipQualityService;
        $this->fingerprintService = $fingerprintService;
    }
    
    /**
     * لیست IP های مسدود
     */
    public function ipBlacklist(Request $request, Response $response)
    {
        $sql = "SELECT * FROM ip_blacklist ORDER BY created_at DESC";
        $ips = $this->db->fetchAll($sql);
        
        return view('admin/fraud/ip-blacklist', ['ips' => $ips]);
    }
    
    /**
     * مسدود کردن IP
     */
    public function blockIP(Request $request, Response $response)
    {
        $ip = $request->input('ip');
        $reason = $request->input('reason', 'مسدود شده توسط ادمین');
        $duration = $request->input('duration'); // seconds or null
        
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            app()->session->setFlash('error', 'IP نامعتبر است');
            return $response->redirect(url('/admin/fraud/ip-blacklist'));
        }
        
        $expiresAt = $duration ? date('Y-m-d H:i:s', time() + $duration) : null;
        
        $sql = "INSERT INTO ip_blacklist (ip_address, reason, blocked_by, expires_at) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                reason = VALUES(reason), 
                expires_at = VALUES(expires_at)";
        
        $this->db->query($sql, [$ip, $reason, app()->session->get('user_id'), $expiresAt]);
        
        log_activity(app()->session->get('user_id'), 'ip_blocked', "IP {$ip} مسدود شد");
        
        app()->session->setFlash('success', 'IP با موفقیت مسدود شد');
        return $response->redirect(url('/admin/fraud/ip-blacklist'));
    }
    
    /**
     * رفع مسدودیت IP
     */
    public function unblockIP(Request $request, Response $response)
    {
        $id = (int) $request->input('id');
        
        $sql = "DELETE FROM ip_blacklist WHERE id = ?";
        $this->db->query($sql, [$id]);
        
        log_activity(app()->session->get('user_id'), 'ip_unblocked', "IP آنبلاک شد");
        
        app()->session->setFlash('success', 'مسدودیت IP برداشته شد');
        return $response->redirect(url('/admin/fraud/ip-blacklist'));
    }
    
    /**
     * لیست Fingerprint های مسدود
     */
    public function deviceBlacklist(Request $request, Response $response)
    {
        $sql = "SELECT * FROM device_blacklist ORDER BY created_at DESC";
        $devices = $this->db->fetchAll($sql);
        
        return view('admin/fraud/device-blacklist', ['devices' => $devices]);
    }
    
    /**
     * مسدود کردن دستگاه
     */
    public function blockDevice(Request $request, Response $response)
    {
        $fingerprint = $request->input('fingerprint');
        $reason = $request->input('reason', 'مسدود شده توسط ادمین');
        
        $this->fingerprintService->blacklistFingerprint($fingerprint, $reason);
        
        log_activity(app()->session->get('user_id'), 'device_blocked', "دستگاه مسدود شد");
        
        app()->session->setFlash('success', 'دستگاه با موفقیت مسدود شد');
        return $response->redirect(url('/admin/fraud/device-blacklist'));
    }
    
    /**
     * رفع مسدودیت دستگاه
     */
    public function unblockDevice(Request $request, Response $response)
    {
        $id = (int) $request->input('id');
        
        $sql = "DELETE FROM device_blacklist WHERE id = ?";
        $this->db->query($sql, [$id]);
        
        log_activity(app()->session->get('user_id'), 'device_unblocked', "دستگاه آنبلاک شد");
        
        app()->session->setFlash('success', 'مسدودیت دستگاه برداشته شد');
        return $response->redirect(url('/admin/fraud/device-blacklist'));
    }
    
    /**
     * لاگ‌های تقلب
     */
    public function fraudLogs(Request $request, Response $response)
    {
        $page = (int) $request->get('page', 1);
        $perPage = 50;
        $offset = ($page - 1) * $perPage;
        
        $sql = "SELECT fl.*, u.full_name, u.email 
                FROM fraud_logs fl
                LEFT JOIN users u ON fl.user_id = u.id
                ORDER BY fl.created_at DESC
                LIMIT ? OFFSET ?";
        
        $logs = $this->db->fetchAll($sql, [$perPage, $offset]);
        
        $countSql = "SELECT COUNT(*) as total FROM fraud_logs";
        $total = $this->db->fetch($countSql)->total ?? 0;
        
        return view('admin/fraud/logs', [
            'logs' => $logs,
            'page' => $page,
            'totalPages' => ceil($total / $perPage)
        ]);
    }
    
    /**
     * ریست fraud score کاربر
     */
    public function resetFraudScore(Request $request, Response $response)
    {
        $userId = (int) $request->input('user_id');
        
        $sql = "UPDATE users SET fraud_score = 0 WHERE id = ?";
        $this->db->query($sql, [$userId]);
        
        log_activity(
            app()->session->get('user_id'), 
            'fraud_score_reset', 
            "Fraud score کاربر #{$userId} ریست شد"
        );
        
        app()->session->setFlash('success', 'Fraud score با موفقیت ریست شد');
        return $response->back();
    }
}
