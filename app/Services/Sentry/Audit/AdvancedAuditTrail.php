<?php

namespace App\Services\Sentry\Audit;

use Core\Database;
use Core\Logger;

/**
 * 📋 AdvancedAuditTrail - سیستم پیشرفته Audit Trail
 * 
 * قابلیت‌ها:
 * - High-performance Logging
 * - Advanced Search & Filter
 * - Compliance Reports
 * - Data Retention
 * - Export capabilities
 * - Change Detection
 */
class AdvancedAuditTrail
{
    private Database $db;
    private Logger $logger;
    
    private array $config = [
        'retention_days' => 90,
        'batch_size' => 100,
        'enable_compression' => true,
    ];

    public function __construct(Database $db, array $config = [])
    {
        $this->db = $db;
        $this->logger = Logger::getInstance()->withChannel('audit');
        $this->config = array_merge($this->config, $config);
    }

    /**
     * 📝 Record Event
     */
    public function record(
        string $event,
        ?int $userId = null,
        array $context = [],
        ?int $actorId = null,
        string $category = 'general'
    ): void {
        try {
            // Enrich context
            $enrichedContext = $this->enrichContext($context);

            // Actor detection
            if ($actorId === null) {
                $actorId = $this->detectActor();
            }

            // Insert
            $this->db->query(
                "INSERT INTO audit_trail (
                    event, user_id, actor_id, category, context, 
                    ip_address, user_agent, request_uri, request_method,
                    session_id, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $event,
                    $userId,
                    $actorId,
                    $category,
                    json_encode($enrichedContext, JSON_UNESCAPED_UNICODE),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
                    $_SERVER['REQUEST_URI'] ?? null,
                    $_SERVER['REQUEST_METHOD'] ?? null,
                    session_id(),
                ]
            );

        } catch (\Throwable $e) {
            $this->logger->error('Audit record failed', [
                'event' => $event,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 🔍 Advanced Search
     */
    public function search(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        // User filter
        if (!empty($filters['user_id'])) {
            $where[] = '(at.user_id = ? OR at.actor_id = ?)';
            $params[] = $filters['user_id'];
            $params[] = $filters['user_id'];
        }

        // Event filter
        if (!empty($filters['event'])) {
            $where[] = 'at.event LIKE ?';
            $params[] = '%' . $filters['event'] . '%';
        }

        // Category filter
        if (!empty($filters['category'])) {
            $where[] = 'at.category = ?';
            $params[] = $filters['category'];
        }

        // Date range
        if (!empty($filters['date_from'])) {
            $where[] = 'at.created_at >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'at.created_at <= ?';
            $params[] = $filters['date_to'];
        }

        // IP filter
        if (!empty($filters['ip_address'])) {
            $where[] = 'at.ip_address = ?';
            $params[] = $filters['ip_address'];
        }

        // Context search (JSON)
        if (!empty($filters['context_search'])) {
            $where[] = 'at.context LIKE ?';
            $params[] = '%' . $filters['context_search'] . '%';
        }

        // Pagination
        $page = $filters['page'] ?? 1;
        $perPage = $filters['per_page'] ?? 50;
        $offset = ($page - 1) * $perPage;

        $whereClause = implode(' AND ', $where);

        // Get total
        $total = (int)$this->db->query(
            "SELECT COUNT(*) FROM audit_trail at WHERE {$whereClause}",
            $params
        )->fetchColumn();

        // Get records
        $records = $this->db->query(
            "SELECT 
                at.*,
                u.full_name as user_name, u.email as user_email,
                a.full_name as actor_name, a.email as actor_email
             FROM audit_trail at
             LEFT JOIN users u ON u.id = at.user_id
             LEFT JOIN users a ON a.id = at.actor_id
             WHERE {$whereClause}
             ORDER BY at.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll(\PDO::FETCH_OBJ);

        return [
            'records' => $records,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
        ];
    }

    /**
     * 📊 Generate Compliance Report
     */
    public function generateComplianceReport(string $startDate, string $endDate, string $type = 'full'): array
    {
        $report = [
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'generated_at' => date('Y-m-d H:i:s'),
            'type' => $type,
        ];

        // Summary Statistics
        $report['summary'] = $this->getReportSummary($startDate, $endDate);

        // Events by Category
        $report['by_category'] = $this->getEventsByCategory($startDate, $endDate);

        // Critical Events
        $report['critical_events'] = $this->getCriticalEvents($startDate, $endDate);

        // User Activity
        if ($type === 'full' || $type === 'user_activity') {
            $report['user_activity'] = $this->getUserActivity($startDate, $endDate);
        }

        // Access Patterns
        if ($type === 'full' || $type === 'access_patterns') {
            $report['access_patterns'] = $this->getAccessPatterns($startDate, $endDate);
        }

        // Failed Operations
        if ($type === 'full' || $type === 'security') {
            $report['failed_operations'] = $this->getFailedOperations($startDate, $endDate);
        }

        return $report;
    }

    /**
     * 💾 Export to CSV
     */
    public function exportToCSV(array $filters, string $filename): string
    {
        $data = $this->search(array_merge($filters, ['per_page' => 10000]));
        
        $csv = fopen('php://temp', 'r+');
        
        // Headers
        fputcsv($csv, [
            'ID', 'Event', 'Category', 'User', 'Actor', 
            'IP Address', 'Created At', 'Context'
        ]);

        // Data
        foreach ($data['records'] as $record) {
            fputcsv($csv, [
                $record->id,
                $record->event,
                $record->category,
                $record->user_email ?? '-',
                $record->actor_email ?? '-',
                $record->ip_address,
                $record->created_at,
                $record->context,
            ]);
        }

        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        // Save to file
        $path = dirname(__DIR__, 4) . '/storage/exports/' . $filename;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * 🗑️ Data Retention - پاکسازی خودکار
     */
    public function cleanupOldRecords(): int
    {
        try {
            $cutoffDate = date('Y-m-d', strtotime("-{$this->config['retention_days']} days"));

            // Archive قبل از حذف (اختیاری)
            if ($this->config['enable_compression']) {
                $this->archiveOldRecords($cutoffDate);
            }

            // حذف
            $result = $this->db->query(
                "DELETE FROM audit_trail WHERE created_at < ?",
                [$cutoffDate]
            );

            $deleted = $result->rowCount();

            if ($deleted > 0) {
                $this->logger->info("Cleaned up {$deleted} old audit records");
            }

            return $deleted;

        } catch (\Throwable $e) {
            $this->logger->error('Cleanup failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * 📦 Archive Old Records
     */
    private function archiveOldRecords(string $cutoffDate): void
    {
        try {
            // Export به فایل فشرده
            $archiveFile = "audit_archive_" . date('Y-m-d') . ".json.gz";
            $archivePath = dirname(__DIR__, 4) . '/storage/archives/' . $archiveFile;

            // دریافت رکوردهای قدیمی
            $oldRecords = $this->db->query(
                "SELECT * FROM audit_trail WHERE created_at < ? ORDER BY created_at ASC",
                [$cutoffDate]
            )->fetchAll(\PDO::FETCH_OBJ);

            if (empty($oldRecords)) {
                return;
            }

            // فشرده‌سازی و ذخیره
            $json = json_encode($oldRecords, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $compressed = gzencode($json, 9);
            
            if (!is_dir(dirname($archivePath))) {
                mkdir(dirname($archivePath), 0755, true);
            }
            
            file_put_contents($archivePath, $compressed);

            $this->logger->info("Archived " . count($oldRecords) . " records to {$archiveFile}");

        } catch (\Throwable $e) {
            $this->logger->error('Archive failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 🔄 Compare Changes
     */
    public function compareChanges(int $recordId1, int $recordId2): array
    {
        $record1 = $this->getRecordById($recordId1);
        $record2 = $this->getRecordById($recordId2);

        if (!$record1 || !$record2) {
            return ['error' => 'Records not found'];
        }

        $context1 = json_decode($record1->context, true) ?? [];
        $context2 = json_decode($record2->context, true) ?? [];

        $changes = $this->arrayDiff($context1, $context2);

        return [
            'record1' => [
                'id' => $record1->id,
                'event' => $record1->event,
                'created_at' => $record1->created_at,
            ],
            'record2' => [
                'id' => $record2->id,
                'event' => $record2->event,
                'created_at' => $record2->created_at,
            ],
            'changes' => $changes,
        ];
    }

    /**
     * 📈 Get Activity Timeline
     */
    public function getActivityTimeline(int $userId, int $days = 30): array
    {
        return $this->db->query(
            "SELECT 
                DATE(created_at) as date,
                COUNT(*) as activity_count,
                GROUP_CONCAT(DISTINCT category) as categories
             FROM audit_trail
             WHERE (user_id = ? OR actor_id = ?)
             AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date DESC",
            [$userId, $userId, $days]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    private function enrichContext(array $context): array
    {
        return array_merge($context, [
            '_timestamp' => microtime(true),
            '_server_time' => date('Y-m-d H:i:s'),
            '_request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8)),
        ]);
    }

    private function detectActor(): ?int
    {
        try {
            $session = \Core\Session::getInstance();
            return $session->get('user_id') ? (int)$session->get('user_id') : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getRecordById(int $id): ?object
    {
        return $this->db->query(
            "SELECT * FROM audit_trail WHERE id = ?",
            [$id]
        )->fetch(\PDO::FETCH_OBJ) ?: null;
    }

    private function arrayDiff(array $old, array $new): array
    {
        $changes = [];
        
        // Check for changes and additions
        foreach ($new as $key => $value) {
            if (!isset($old[$key])) {
                $changes[$key] = ['added' => $value];
            } elseif ($old[$key] !== $value) {
                $changes[$key] = [
                    'from' => $old[$key],
                    'to' => $value,
                ];
            }
        }

        // Check for deletions
        foreach ($old as $key => $value) {
            if (!isset($new[$key])) {
                $changes[$key] = ['removed' => $value];
            }
        }

        return $changes;
    }

    private function getReportSummary(string $start, string $end): array
    {
        $stats = $this->db->query(
            "SELECT 
                COUNT(*) as total_events,
                COUNT(DISTINCT user_id) as unique_users,
                COUNT(DISTINCT category) as unique_categories
             FROM audit_trail
             WHERE created_at BETWEEN ? AND ?",
            [$start, $end]
        )->fetch(\PDO::FETCH_OBJ);

        return [
            'total_events' => (int)$stats->total_events,
            'unique_users' => (int)$stats->unique_users,
            'unique_categories' => (int)$stats->unique_categories,
        ];
    }

    private function getEventsByCategory(string $start, string $end): array
    {
        return $this->db->query(
            "SELECT category, COUNT(*) as count
             FROM audit_trail
             WHERE created_at BETWEEN ? AND ?
             GROUP BY category
             ORDER BY count DESC",
            [$start, $end]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    private function getCriticalEvents(string $start, string $end): array
    {
        // Events که معمولاً critical هستن
        $criticalEvents = [
            'user.deleted', 'admin.role_changed', 'security.breach',
            'payment.failed', 'data.exported', 'admin.impersonate'
        ];

        $placeholders = implode(',', array_fill(0, count($criticalEvents), '?'));
        
        return $this->db->query(
            "SELECT * FROM audit_trail
             WHERE event IN ({$placeholders})
             AND created_at BETWEEN ? AND ?
             ORDER BY created_at DESC",
            [...$criticalEvents, $start, $end]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    private function getUserActivity(string $start, string $end): array
    {
        return $this->db->query(
            "SELECT 
                u.id, u.email, u.full_name,
                COUNT(*) as activity_count,
                MAX(at.created_at) as last_activity
             FROM audit_trail at
             INNER JOIN users u ON u.id = at.user_id
             WHERE at.created_at BETWEEN ? AND ?
             GROUP BY u.id
             ORDER BY activity_count DESC
             LIMIT 50",
            [$start, $end]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    private function getAccessPatterns(string $start, string $end): array
    {
        return $this->db->query(
            "SELECT 
                ip_address,
                COUNT(*) as access_count,
                COUNT(DISTINCT user_id) as unique_users
             FROM audit_trail
             WHERE created_at BETWEEN ? AND ?
             GROUP BY ip_address
             HAVING access_count > 100
             ORDER BY access_count DESC
             LIMIT 20",
            [$start, $end]
        )->fetchAll(\PDO::FETCH_OBJ);
    }

    private function getFailedOperations(string $start, string $end): array
    {
        return $this->db->query(
            "SELECT *
             FROM audit_trail
             WHERE event LIKE '%.failed'
             AND created_at BETWEEN ? AND ?
             ORDER BY created_at DESC
             LIMIT 100",
            [$start, $end]
        )->fetchAll(\PDO::FETCH_OBJ);
    }
}
