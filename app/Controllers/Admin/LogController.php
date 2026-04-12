<?php

namespace App\Controllers\Admin;

use Core\Database;
use App\Models\ActivityLog;
use App\Services\ErrorLogService;
use App\Services\PerformanceMonitorService;
use App\Services\LogNotificationService;
use App\Controllers\Admin\BaseAdminController;

class LogController extends BaseAdminController
{
    private Database $db;
    private ActivityLog $activityLog;
    private \App\Models\User $userModel;
    private ?ErrorLogService $errorService = null;
    private ?PerformanceMonitorService $perfService = null;
    private ?LogNotificationService $notificationService = null;

    public function __construct(
        Database $db,
        \App\Models\User $userModel,
        \App\Models\ActivityLog $activityLog
    ) {
        parent::__construct();
        $this->db          = $db;
        $this->activityLog = $activityLog;
        $this->userModel   = $userModel;

        // بررسی وجود جداول پیشرفته
        try {
            $tableExists = $this->db->query("SHOW TABLES LIKE 'error_logs'")->fetch();
            if ($tableExists) {
                $this->errorService = new ErrorLogService($db);
                $this->perfService = new PerformanceMonitorService($db);
                $this->notificationService = new LogNotificationService($db);
            }
        } catch (\Throwable $e) {
            // جداول نیستن، از حالت ساده استفاده می‌کنیم
        }
    }

    /**
     * نمایش لیست لاگ‌های فعالیت
     */
    public function index(): void
    {
        $page    = max(1, (int)$this->request->get('page', 1));
        $perPage = 50;
        $userId  = $this->request->get('user_id');
        $action  = $this->request->get('action');
        $search  = $this->request->get('search');

        $result = $this->activityLog->getPaginated(
            $page,
            $perPage,
            $userId ? (int)$userId : null,
            $action,
            $search
        );

        view('admin/logs/index', [
            'title'      => 'لاگ‌های فعالیت',
            'logs'       => $result['logs'],
            'total'      => $result['total'],
            'page'       => $result['page'],
            'totalPages' => $result['totalPages'],
            'perPage'    => $result['perPage'],
        ]);
    }

    /**
     * نمایش لاگ‌های فعالیت (مسیر جداگانه /admin/activity-logs)
     */
    public function activityLogs(): void
    {
        $this->index();
    }

    /**
     * حذف لاگ‌های قدیمی
     */
    public function cleanup(): void
    {
        $days = (int)$this->request->post('days', 90);

        if ($days < 30) {
            $this->session->setFlash('error', 'حداقل 30 روز باید باقی بماند.');
            redirect('/admin/logs');
            return;
        }

        $deleted = $this->activityLog->deleteOlderThan($days);

        log_activity('cleanup_logs', "حذف {$deleted} لاگ قدیمی‌تر از {$days} روز");

        $this->session->setFlash('success', "{$deleted} رکورد حذف شد.");
        redirect('/admin/logs');
    }

    // ========== متدهای جدید برای سیستم پیشرفته ==========

    /**
     * داشبورد اصلی (اگر سیستم پیشرفته فعال باشه)
     */
    public function dashboard(): void
    {
        if (!$this->errorService) {
            $this->session->setFlash('error', 'سیستم لاگ پیشرفته نصب نشده. Migration را اجرا کنید.');
            redirect('/admin/logs');
            return;
        }

        $period = $this->request->get('period', 'today');

        $errorStats = $this->errorService->getStatistics($period);
        $performanceStats = $this->perfService->getStatistics($period);
        $predictions = $this->perfService->predictIssues();
        
        $activeAlerts = $this->db->query(
            "SELECT * FROM system_alerts 
             WHERE is_active = 1 
             ORDER BY created_at DESC 
             LIMIT 10"
        )->fetchAll(\PDO::FETCH_OBJ);

        $todayStats = [
            'total_errors' => $errorStats['total'],
            'critical_errors' => $this->getCriticalErrorCount(),
            'slow_requests' => $performanceStats['slow_count'],
            'active_alerts' => count($activeAlerts),
        ];

        $yesterdayStats = $this->errorService->getStatistics('yesterday');
        $comparison = [
            'errors_change' => $this->calculateChange(
                $errorStats['total'], 
                $yesterdayStats['total']
            ),
        ];

        view('admin/logs/dashboard', [
            'title' => 'داشبورد لاگ‌ها',
            'errorStats' => $errorStats,
            'performanceStats' => $performanceStats,
            'predictions' => $predictions,
            'activeAlerts' => $activeAlerts,
            'todayStats' => $todayStats,
            'comparison' => $comparison,
            'period' => $period
        ]);
    }

    /**
     * لیست خطاها
     */
    public function errors(): void
    {
        if (!$this->errorService) {
            redirect('/admin/logs');
            return;
        }

        $page = max(1, (int)$this->request->get('page', 1));
        $level = $this->request->get('level');

        $result = $this->errorService->getGroupedErrors($page, 20, $level);

        view('admin/logs/errors', [
            'title' => 'مدیریت خطاها',
            'errors' => $result['errors'],
            'total' => $result['total'],
            'page' => $result['page'],
            'totalPages' => $result['totalPages'],
            'level' => $level
        ]);
    }

    /**
     * جزئیات خطا
     */
    public function errorDetails(): void
    {
        if (!$this->errorService) {
            redirect('/admin/logs');
            return;
        }

        $id = (int)$this->request->get('id');
        $analysis = $this->errorService->analyzeError($id);

        if (isset($analysis['error'])) {
            view('admin/logs/error-details', [
                'title' => 'جزئیات خطا',
                'error' => $analysis['error'],
                'suggestions' => $analysis['suggestions'] ?? [],
                'similarErrors' => $analysis['similar_errors'] ?? []
            ]);
        } else {
            $this->session->setFlash('error', 'خطا یافت نشد');
            redirect('/admin/logs/errors');
        }
    }

    /**
     * حل خطا
     */
    public function resolveError(): void
    {
        if (!$this->errorService) {
            redirect('/admin/logs');
            return;
        }

        $id = (int)$this->request->post('error_id');
        $note = $this->request->post('note', '');
        $userId = $this->session->get('user_id');

        if ($this->errorService->resolveError($id, $userId, $note)) {
            $this->session->setFlash('success', 'خطا به عنوان حل شده علامت‌گذاری شد');
        } else {
            $this->session->setFlash('error', 'عملیات ناموفق بود');
        }

        redirect('/admin/logs/errors');
    }

    /**
     * تنظیمات نوتیفیکیشن
     */
    public function notificationSettings(): void
    {
        if (!$this->notificationService) {
            redirect('/admin/logs');
            return;
        }

        $channels = $this->db->query(
            "SELECT * FROM notification_channels ORDER BY created_at DESC"
        )->fetchAll(\PDO::FETCH_OBJ);

        $rules = $this->db->query(
            "SELECT * FROM alert_rules ORDER BY created_at DESC"
        )->fetchAll(\PDO::FETCH_OBJ);

        view('admin/logs/notification-settings', [
            'title' => 'تنظیمات نوتیفیکیشن',
            'channels' => $channels,
            'rules' => $rules
        ]);
    }

    /**
     * ذخیره کانال نوتیفیکیشن
     */
    public function saveChannel(): void
    {
        $type = $this->request->post('channel_type');
        $name = $this->request->post('channel_name');
        $config = [];

        if ($type === 'telegram') {
            $config = [
                'bot_token' => $this->request->post('bot_token'),
                'chat_id' => $this->request->post('chat_id')
            ];
        } elseif ($type === 'email') {
            $config = ['email' => $this->request->post('email')];
        }

        $alertLevels = $this->request->post('alert_levels', []);

        $this->db->query(
            "INSERT INTO notification_channels 
            (channel_type, channel_name, config, alert_levels)
            VALUES (?, ?, ?, ?)",
            [
                $type,
                $name,
                json_encode($config),
                json_encode($alertLevels)
            ]
        );

        $this->session->setFlash('success', 'کانال ذخیره شد');
        redirect('/admin/logs/notification-settings');
    }

    /**
     * تست کانال
     */
    public function testChannel(): void
    {
        $id = (int)$this->request->post('channel_id');
        $result = $this->notificationService->testChannel($id);

        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }

    /**
     * API آمار برای نمودارها
     */
    public function apiStats(): void
    {
        $type = $this->request->get('type', 'errors');
        $period = $this->request->get('period', 'week');

        $dateCondition = match($period) {
            'today' => "DATE(created_at) = CURDATE()",
            'week' => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            'month' => "created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            default => "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        };

        if ($type === 'errors') {
            $data = $this->db->query(
                "SELECT 
                    DATE(created_at) as date,
                    level,
                    COUNT(*) as count
                 FROM error_logs 
                 WHERE {$dateCondition}
                 GROUP BY DATE(created_at), level
                 ORDER BY date"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } else {
            $data = [];
        }

        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    // Helper methods
    private function getCriticalErrorCount(): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM error_logs 
             WHERE level IN ('CRITICAL', 'FATAL') 
             AND DATE(created_at) = CURDATE()"
        )->fetch(\PDO::FETCH_OBJ);

        return $result->count ?? 0;
    }

    private function calculateChange(float $current, float $previous): array
    {
        if ($previous == 0) {
            return ['percent' => 0, 'direction' => 'neutral'];
        }

        $change = (($current - $previous) / $previous) * 100;
        
        return [
            'percent' => round(abs($change), 1),
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'neutral')
        ];
    }
}
