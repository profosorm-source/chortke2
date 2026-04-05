<?php

namespace App\Services\AntiFraud;

use Core\Database;

/**
 * TorListUpdater
 * 
 * به‌روزرسانی لیست Tor exit nodes
 */
class TorListUpdater
{
    private Database $db;
    
    public function __construct(Database $db)
    {
        $this->db = $db;
    }
    
    /**
     * دانلود و به‌روزرسانی لیست Tor
     */
    public function update(): array
    {
        $url = 'https://check.torproject.org/torbulkexitlist';
        
        try {
            // دانلود لیست
            $content = @file_get_contents($url);
            
            if ($content === false) {
                return [
                    'success' => false,
                    'message' => 'خطا در دانلود لیست Tor'
                ];
            }
            
            // پارس IP ها
            $ips = array_filter(array_map('trim', explode("\n", $content)));
            $validIPs = [];
            
            foreach ($ips as $ip) {
                // فقط IP های معتبر
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $validIPs[] = $ip;
                }
            }
            
            if (empty($validIPs)) {
                return [
                    'success' => false,
                    'message' => 'هیچ IP معتبری یافت نشد'
                ];
            }
            
            // حذف لیست قدیمی
            $this->db->query("TRUNCATE TABLE tor_exit_nodes");
            
            // اضافه کردن IP های جدید
            $sql = "INSERT INTO tor_exit_nodes (ip_address, last_verified) VALUES (?, NOW())";
            $inserted = 0;
            
            foreach ($validIPs as $ip) {
                if ($this->db->query($sql, [$ip])) {
                    $inserted++;
                }
            }
            
            return [
                'success' => true,
                'message' => "{$inserted} Tor exit node به‌روز شد",
                'count' => $inserted
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * دریافت تعداد Tor nodes
     */
    public function getCount(): int
    {
        $result = $this->db->fetch("SELECT COUNT(*) as count FROM tor_exit_nodes");
        return $result ? (int) $result->count : 0;
    }
    
    /**
     * دریافت آخرین زمان به‌روزرسانی
     */
    public function getLastUpdate(): ?string
    {
        $result = $this->db->fetch("SELECT MAX(last_verified) as last_update FROM tor_exit_nodes");
        return $result ? $result->last_update : null;
    }
}
