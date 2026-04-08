<?php

namespace App\Controllers\Admin;

use Core\Database;
use App\Services\AntiFraud\IPQualityService;
use App\Controllers\Admin\BaseAdminController;

class FraudDashboardController extends BaseAdminController
{
    private Database $db;
    private IPQualityService $ipQualityService;
    
    public function __construct(\Core\Database $db, \App\Services\AntiFraud\IPQualityService $ipQualityService)
    {
        parent::__construct();
        $this->db = $db;
        $this->ipQualityService = $ipQualityService;
    }
    
    /**
     * داشبورد ضد تقلب
     */
    public function index()
    {
        // آمار کلی
        $stats = $this->getStats();
        
        // فعالیت‌های اخیر مشکوک
        $recentSuspicious = $this->getRecentSuspiciousActivities();
        
        // IP های مشکوک
        $suspiciousIPs = $this->getSuspiciousIPs();
        
        // Fingerprint های تکراری
        $duplicateFingerprints = $this->getDuplicateFingerprints();
        
        return view('admin/fraud/dashboard', [
            'stats' => $stats,
            'recentSuspicious' => $recentSuspicious,
            'suspiciousIPs' => $suspiciousIPs,
            'duplicateFingerprints' => $duplicateFingerprints
        ]);
    }
    
    /**
     * آمار کلی
     */
    private function getStats(): array
    {
        $stats = [];
        
        // کاربران با Fraud Score بالا
        $sql = "SELECT COUNT(*) as count FROM users WHERE fraud_score >= 70";
        $stats['high_risk_users'] = $this->db->fetch($sql)->count ?? 0;
        
        // کاربران در لیست سیاه
        $sql = "SELECT COUNT(*) as count FROM users WHERE is_blacklisted = TRUE";
        $stats['blacklisted_users'] = $this->db->fetch($sql)->count ?? 0;
        
        // فعالیت‌های مشکوک امروز
        $sql = "SELECT COUNT(*) as count FROM activity_logs 
                WHERE action LIKE '%fraud%' OR action LIKE '%suspicious%' 
                AND DATE(created_at) = CURDATE()";
        $stats['today_suspicious'] = $this->db->fetch($sql)->count ?? 0;
        
        // تسک‌های رد شده امروز
        $sql = "SELECT COUNT(*) as count FROM task_executions 
                WHERE status = 'rejected' 
                AND DATE(created_at) = CURDATE()";
        $stats['today_rejected'] = $this->db->fetch($sql)->count ?? 0;
        
        return $stats;
    }
    
    /**
     * فعالیت‌های اخیر مشکوک
     */
    private function getRecentSuspiciousActivities(): array
    {
        $sql = "SELECT al.*, u.full_name, u.email 
                FROM activity_logs al
                JOIN users u ON al.user_id = u.id
                WHERE al.action LIKE '%fraud%' 
                OR al.action LIKE '%suspicious%'
                OR al.action LIKE '%anomaly%'
                ORDER BY al.created_at DESC
                LIMIT 20";
        
        return $this->db->fetchAll($sql);
    }
    
    /**
     * IP های مشکوک
     */
    private function getSuspiciousIPs(): array
    {
        $sql = "SELECT ip_address, COUNT(DISTINCT user_id) as user_count, COUNT(*) as total_sessions
                FROM user_sessions 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY ip_address
                HAVING user_count > 3
                ORDER BY user_count DESC
                LIMIT 20";
        
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Fingerprint های تکراری
     */
    private function getDuplicateFingerprints(): array
    {
        $sql = "SELECT fingerprint, COUNT(DISTINCT user_id) as user_count
                FROM user_fingerprints
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY fingerprint
                HAVING user_count > 2
                ORDER BY user_count DESC
                LIMIT 20";
        
        return $this->db->fetchAll($sql);
    }
}