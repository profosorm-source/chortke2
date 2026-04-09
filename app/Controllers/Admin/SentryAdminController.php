<?php

namespace App\Controllers\Admin;

use App\Services\Sentry\Analytics\DashboardService;
use App\Services\Sentry\Analytics\TrendAnalyzer;
use App\Services\Sentry\Alerting\AlertRulesEngine;
use App\Services\Sentry\Alerting\EscalationManager;
use App\Services\Sentry\Audit\AdvancedAuditTrail;
use Core\Database;
use Core\Response;

/**
 * 🎛️ SentryAdminController - کنترلر پنل ادمین Sentry
 */
class SentryAdminController extends BaseAdminController
{
    private DashboardService $dashboard;
    private TrendAnalyzer $trendAnalyzer;
    private AlertRulesEngine $alertRules;
    private EscalationManager $escalation;
    private AdvancedAuditTrail $audit;

    public function __construct()
    {
        parent::__construct();
        
        $db = Database::getInstance();
        $this->dashboard = new DashboardService($db);
        $this->trendAnalyzer = new TrendAnalyzer($db);
        $this->alertRules = new AlertRulesEngine($db);
        $this->escalation = new EscalationManager($db);
        $this->audit = new AdvancedAuditTrail($db);
    }

    /**
     * 🏠 Dashboard Overview
     */
    public function index(): void
    {
        $data = [
            'overview' => $this->dashboard->getOverview(),
            'trends' => [
                'errors' => $this->trendAnalyzer->analyzeTrends('errors', 7),
                'performance' => $this->trendAnalyzer->analyzeTrends('performance', 7),
            ],
            'escalation_stats' => $this->escalation->getStatistics(),
        ];

        view('admin/sentry/dashboard', $data);
    }

    /**
     * 🚨 Issues List
     */
    public function issues(): void
    {
        $page = (int)($_GET['page'] ?? 1);
        $status = $_GET['status'] ?? 'unresolved';
        $level = $_GET['level'] ?? null;

        $issues = $this->getIssuesList($page, $status, $level);

        view('admin/sentry/issues', [
            'issues' => $issues,
            'status' => $status,
            'level' => $level,
        ]);
    }

    /**
     * 📝 Issue Details
     */
    public function issueDetails(int $id): void
    {
        $db = Database::getInstance();
        
        $issue = $db->query(
            "SELECT * FROM sentry_issues WHERE id = ?",
            [$id]
        )->fetch(\PDO::FETCH_OBJ);

        if (!$issue) {
            Response::notFound();
            return;
        }

        $events = $db->query(
            "SELECT * FROM sentry_events 
             WHERE issue_id = ? 
             ORDER BY created_at DESC 
             LIMIT 50",
            [$id]
        )->fetchAll(\PDO::FETCH_OBJ);

        view('admin/sentry/issue-details', [
            'issue' => $issue,
            'events' => $events,
        ]);
    }

    /**
     * 🚀 Performance Monitor
     */
    public function performance(): void
    {
        $period = $_GET['period'] ?? '24h';
        
        $data = [
            'stats' => $this->dashboard->getPerformanceStatistics(),
            'slowest_endpoints' => $this->dashboard->getTopSlowestEndpoints(20),
            'time_series' => $this->dashboard->getTimeSeriesData('performance', $period),
            'degradation' => $this->trendAnalyzer->getPerformanceDegradation(),
        ];

        view('admin/sentry/performance', $data);
    }

    /**
     * 📊 Analytics
     */
    public function analytics(): void
    {
        $metric = $_GET['metric'] ?? 'errors';
        $days = (int)($_GET['days'] ?? 7);

        $data = [
            'trends' => $this->trendAnalyzer->analyzeTrends($metric, $days),
            'hotspots' => $this->trendAnalyzer->getErrorHotspots(),
            'error_sources' => $this->dashboard->getTopErrorSources(15),
            'time_series' => $this->dashboard->getTimeSeriesData($metric, "{$days}d", '1h'),
        ];

        view('admin/sentry/analytics', $data);
    }

    /**
     * 🔔 Alerts Management
     */
    public function alerts(): void
    {
        $db = Database::getInstance();
        
        $activeAlerts = $db->query(
            "SELECT * FROM system_alerts 
             WHERE is_active = 1 
             ORDER BY created_at DESC 
             LIMIT 50"
        )->fetchAll(\PDO::FETCH_OBJ);

        $rules = $db->query(
            "SELECT * FROM alert_rules ORDER BY severity DESC, rule_name ASC"
        )->fetchAll(\PDO::FETCH_OBJ);

        view('admin/sentry/alerts', [
            'active_alerts' => $activeAlerts,
            'rules' => $rules,
        ]);
    }

    /**
     * ✅ Acknowledge Alert
     */
    public function acknowledgeAlert(): void
    {
        $alertId = (int)($_POST['alert_id'] ?? 0);
        $note = $_POST['note'] ?? null;
        $userId = $this->session->get('user_id');

        if ($this->escalation->acknowledgeAlert($alertId, $userId, $note)) {
            Response::json(['success' => true]);
        } else {
            Response::json(['success' => false, 'error' => 'Failed to acknowledge']);
        }
    }

    /**
     * 📋 Audit Trail
     */
    public function auditTrail(): void
    {
        $filters = [
            'user_id' => $_GET['user_id'] ?? null,
            'event' => $_GET['event'] ?? null,
            'category' => $_GET['category'] ?? null,
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
            'page' => (int)($_GET['page'] ?? 1),
            'per_page' => 50,
        ];

        $results = $this->audit->search($filters);

        view('admin/sentry/audit-trail', [
            'results' => $results,
            'filters' => $filters,
        ]);
    }

    /**
     * 📄 Generate Compliance Report
     */
    public function generateReport(): void
    {
        $startDate = $_POST['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $_POST['end_date'] ?? date('Y-m-d');
        $type = $_POST['type'] ?? 'full';

        $report = $this->audit->generateComplianceReport($startDate, $endDate, $type);

        Response::json($report);
    }

    /**
     * 💾 Export Audit
     */
    public function exportAudit(): void
    {
        $filters = [
            'user_id' => $_GET['user_id'] ?? null,
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
        ];

        $filename = 'audit_export_' . date('Y-m-d_H-i-s') . '.csv';
        $path = $this->audit->exportToCSV($filters, $filename);

        // دانلود فایل
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        readfile($path);
        exit;
    }

    /**
     * 🔧 Resolve Issue
     */
    public function resolveIssue(): void
    {
        $issueId = (int)($_POST['issue_id'] ?? 0);
        $note = $_POST['note'] ?? '';
        $userId = $this->session->get('user_id');

        $db = Database::getInstance();
        $db->query(
            "UPDATE sentry_issues 
             SET status = 'resolved',
                 resolved_by = ?,
                 resolved_at = NOW(),
                 metadata = JSON_SET(
                     COALESCE(metadata, '{}'),
                     '$.resolution_note',
                     ?
                 )
             WHERE id = ?",
            [$userId, $note, $issueId]
        );

        Response::json(['success' => true]);
    }

    /**
     * 🔕 Mute Issue
     */
    public function muteIssue(): void
    {
        $issueId = (int)($_POST['issue_id'] ?? 0);
        $duration = $_POST['duration'] ?? '7d'; // 7 days default

        $db = Database::getInstance();
        $db->query(
            "UPDATE sentry_issues 
             SET status = 'muted',
                 metadata = JSON_SET(
                     COALESCE(metadata, '{}'),
                     '$.muted_until',
                     DATE_ADD(NOW(), INTERVAL ? DAY)
                 )
             WHERE id = ?",
            [(int)$duration, $issueId]
        );

        Response::json(['success' => true]);
    }

    /**
     * 📊 Get Chart Data (API)
     */
    public function getChartData(): void
    {
        $metric = $_GET['metric'] ?? 'errors';
        $period = $_GET['period'] ?? '24h';
        $interval = $_GET['interval'] ?? '1h';

        $data = $this->dashboard->getTimeSeriesData($metric, $period, $interval);

        Response::json($data);
    }

    /**
     * 💚 Health Check (API)
     */
    public function healthCheck(): void
    {
        $health = $this->dashboard->calculateHealthScore();
        Response::json($health);
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    private function getIssuesList(int $page, string $status, ?string $level): array
    {
        $db = Database::getInstance();
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $where = ['i.status = ?'];
        $params = [$status];

        if ($level) {
            $where[] = 'i.level = ?';
            $params[] = $level;
        }

        $whereClause = implode(' AND ', $where);

        $total = (int)$db->query(
            "SELECT COUNT(*) FROM sentry_issues i WHERE {$whereClause}",
            $params
        )->fetchColumn();

        $issues = $db->query(
            "SELECT 
                i.*,
                (SELECT COUNT(*) FROM sentry_events WHERE issue_id = i.id) as total_events,
                (SELECT COUNT(*) FROM sentry_events WHERE issue_id = i.id AND DATE(created_at) = CURDATE()) as today_events
             FROM sentry_issues i
             WHERE {$whereClause}
             ORDER BY i.last_seen DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll(\PDO::FETCH_OBJ);

        return [
            'items' => $issues,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
        ];
    }
}
