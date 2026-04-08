<?php

namespace App\Controllers\Admin;

use App\Services\AntiFraud\BrowserFingerprintService;
use App\Services\AntiFraud\IPQualityService;
use App\Services\ScoreAdjustmentService;
use Core\Database;
use Core\Request;
use Core\Response;
use InvalidArgumentException;

class FraudManagementController extends BaseAdminController
{
    private Database $db;
    private IPQualityService $ipQualityService;
    private BrowserFingerprintService $fingerprintService;
    private ScoreAdjustmentService $scoreAdjustmentService;

    public function __construct(
        Database $db,
        IPQualityService $ipQualityService,
        BrowserFingerprintService $fingerprintService,
        ScoreAdjustmentService $scoreAdjustmentService
    ) {
        parent::__construct();
        $this->db = $db;
        $this->ipQualityService = $ipQualityService;
        $this->fingerprintService = $fingerprintService;
        $this->scoreAdjustmentService = $scoreAdjustmentService;
    }

    public function ipBlacklist(Request $request, Response $response)
    {
        $ips = $this->db->fetchAll('SELECT * FROM ip_blacklist ORDER BY created_at DESC');
        return view('admin/fraud/ip-blacklist', ['ips' => $ips]);
    }

    public function blockIP(Request $request, Response $response)
    {
        $ip = (string) $request->input('ip');
        $reason = (string) $request->input('reason', 'مسدود شده توسط ادمین');
        $duration = $request->input('duration');

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            app()->session->setFlash('error', 'IP نامعتبر است');
            return $response->redirect(url('/admin/fraud/ip-blacklist'));
        }

        $expiresAt = $duration ? date('Y-m-d H:i:s', time() + (int) $duration) : null;
        $sql = 'INSERT INTO ip_blacklist (ip_address, reason, blocked_by, expires_at) VALUES (?, ?, ?, ?)\n'
             . 'ON DUPLICATE KEY UPDATE reason = VALUES(reason), expires_at = VALUES(expires_at)';
        $this->db->query($sql, [$ip, $reason, app()->session->get('user_id'), $expiresAt]);

        log_activity(app()->session->get('user_id'), 'ip_blocked', "IP {$ip} مسدود شد");
        app()->session->setFlash('success', 'IP با موفقیت مسدود شد');
        return $response->redirect(url('/admin/fraud/ip-blacklist'));
    }

    public function unblockIP(Request $request, Response $response)
    {
        $id = (int) $request->input('id');
        $this->db->query('DELETE FROM ip_blacklist WHERE id = ?', [$id]);
        log_activity(app()->session->get('user_id'), 'ip_unblocked', 'IP آنبلاک شد');
        app()->session->setFlash('success', 'مسدودیت IP برداشته شد');
        return $response->redirect(url('/admin/fraud/ip-blacklist'));
    }

    public function deviceBlacklist(Request $request, Response $response)
    {
        $devices = $this->db->fetchAll('SELECT * FROM device_blacklist ORDER BY created_at DESC');
        return view('admin/fraud/device-blacklist', ['devices' => $devices]);
    }

    public function blockDevice(Request $request, Response $response)
    {
        $fingerprint = (string) $request->input('fingerprint');
        $reason = (string) $request->input('reason', 'مسدود شده توسط ادمین');

        $this->fingerprintService->blacklistFingerprint($fingerprint, $reason);
        log_activity(app()->session->get('user_id'), 'device_blocked', 'دستگاه مسدود شد');
        app()->session->setFlash('success', 'دستگاه با موفقیت مسدود شد');
        return $response->redirect(url('/admin/fraud/device-blacklist'));
    }

    public function unblockDevice(Request $request, Response $response)
    {
        $id = (int) $request->input('id');
        $this->db->query('DELETE FROM device_blacklist WHERE id = ?', [$id]);
        log_activity(app()->session->get('user_id'), 'device_unblocked', 'دستگاه آنبلاک شد');
        app()->session->setFlash('success', 'مسدودیت دستگاه برداشته شد');
        return $response->redirect(url('/admin/fraud/device-blacklist'));
    }

    public function fraudLogs(Request $request, Response $response)
    {
        $page = (int) $request->get('page', 1);
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $sql = 'SELECT fl.*, u.full_name, u.email FROM fraud_logs fl LEFT JOIN users u ON fl.user_id = u.id ORDER BY fl.created_at DESC LIMIT ? OFFSET ?';
        $logs = $this->db->fetchAll($sql, [$perPage, $offset]);
        $total = $this->db->fetch('SELECT COUNT(*) as total FROM fraud_logs')->total ?? 0;

        return view('admin/fraud/logs', [
            'logs' => $logs,
            'page' => $page,
            'totalPages' => (int) ceil($total / $perPage),
        ]);
    }

    /**
     * Instead of hard reset, create auditable set adjustment.
     */
    public function resetFraudScore(Request $request, Response $response)
    {
        $adminId = (int) app()->session->get('user_id');
        $userId = (int) $request->input('user_id');
        $reason = (string) $request->input('reason', 'Reset by admin');

        try {
            $ok = $this->scoreAdjustmentService->adjust(
                $adminId,
                $userId,
                'fraud',
                'set',
                0,
                $reason,
                null
            );

            if ($ok) {
                log_activity($adminId, 'fraud_score_reset', "Fraud score کاربر #{$userId} reset by adjustment");
                app()->session->setFlash('success', 'Fraud score با adjustment ریست شد.');
            } else {
                app()->session->setFlash('error', 'ریست Fraud score ناموفق بود.');
            }
        } catch (InvalidArgumentException $e) {
            app()->session->setFlash('error', $e->getMessage());
        }

        return $response->back();
    }
}