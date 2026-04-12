<?php

namespace App\Controllers\Admin;

use App\Services\ActivityLogger;
use App\Services\ExportService;
use App\Controllers\Admin\BaseAdminController;

/**
 * ActivityLogController - مدیریت لاگ فعالیت‌ها
 */
class ActivityLogController extends BaseAdminController
{
    private ActivityLogger $activityLogger;
    private ExportService $exportService;

    public function __construct(ActivityLogger $activityLogger, ExportService $exportService)
    {
        parent::__construct();
        $this->activityLogger = $activityLogger;
        $this->exportService = $exportService;
    }

    /**
     * لیست فعالیت‌ها
     */
    public function index()
    {
        try {
            $page = max(1, (int)($this->request->get('page') ?? 1));
            $userId = $this->request->get('user_id') ? (int)$this->request->get('user_id') : null;
            $action = $this->request->get('action');
            $search = $this->request->get('search');

            $result = $this->activityLogger->getPaginated(
                page: $page,
                perPage: 50,
                userId: $userId,
                action: $action ?: null,
                searchTerm: $search ?: null
            );

            $topActions = $this->activityLogger->getTopActions(10);

            return view('admin.activity-logs.index', [
                'user' => auth()->user(),
                'title' => 'لاگ فعالیت‌ها',
                'logs' => $result['logs'],
                'total' => $result['total'],
                'page' => $result['page'],
                'totalPages' => $result['totalPages'],
                'topActions' => $topActions,
                'filters' => [
                    'user_id' => $userId,
                    'action' => $action,
                    'search' => $search,
                ],
            ]);

        } catch (\Exception $e) {
            logger()->error('activity_log.index.failed', [
                'error' => $e->getMessage()
            ]);
            return view('errors.500');
        }
    }

    /**
     * مشاهده جزئیات
     */
    public function show()
    {
        try {
            $id = (int)$this->request->param('id');
            
            $stmt = db()->query(
                "SELECT al.*, u.full_name, u.email
                 FROM activity_logs al
                 LEFT JOIN users u ON al.user_id = u.id
                 WHERE al.id = ? AND al.deleted_at IS NULL",
                [$id]
            );

            if (!$stmt instanceof \PDOStatement) {
                return view('errors.404');
            }

            $log = $stmt->fetch(\PDO::FETCH_OBJ);
            
            if (!$log) {
                return view('errors.404');
            }

            return view('admin.activity-logs.show', [
                'user' => auth()->user(),
                'title' => 'جزئیات فعالیت',
                'log' => $log,
            ]);

        } catch (\Exception $e) {
            logger()->error('activity_log.show.failed', [
                'error' => $e->getMessage(),
                'id' => $id ?? null
            ]);
            return view('errors.500');
        }
    }

    /**
     * لاگ‌های کاربر خاص
     */
    public function userLogs()
    {
        try {
            $userId = (int)$this->request->param('user_id');
            $limit = (int)($this->request->get('limit') ?? 100);

            $logs = $this->activityLogger->getRecent($limit, $userId);

            return view('admin.activity-logs.user-logs', [
                'user' => auth()->user(),
                'title' => 'فعالیت‌های کاربر',
                'logs' => $logs,
                'userId' => $userId,
            ]);

        } catch (\Exception $e) {
            logger()->error('activity_log.user_logs.failed', [
                'error' => $e->getMessage(),
                'user_id' => $userId ?? null
            ]);
            return view('errors.500');
        }
    }

    /**
     * آمار
     */
    public function stats()
    {
        try {
            $userId = $this->request->get('user_id') ? (int)$this->request->get('user_id') : null;

            $stats = $this->activityLogger->getStats($userId);
            $topActions = $this->activityLogger->getTopActions(20, $userId);

            return view('admin.activity-logs.stats', [
                'user' => auth()->user(),
                'title' => 'آمار فعالیت‌ها',
                'stats' => $stats,
                'topActions' => $topActions,
                'userId' => $userId,
            ]);

        } catch (\Exception $e) {
            logger()->error('activity_log.stats.failed', [
                'error' => $e->getMessage()
            ]);
            return view('errors.500');
        }
    }

    /**
     * حذف لاگ‌های قدیمی
     */
    public function cleanup()
    {
        try {
            $days = (int)($this->request->post('days') ?? 90);
            $type = $this->request->post('type') ?? 'soft'; // soft or hard

            if ($type === 'hard') {
                $deleted = $this->activityLogger->deleteOlderThan($days);
                $message = "{$deleted} رکورد حذف شد";
            } else {
                $deleted = $this->activityLogger->softDeleteOlderThan($days);
                $message = "{$deleted} رکورد به سطل زباله منتقل شد";
            }

            logger()->info('activity_log.cleanup', [
                'days' => $days,
                'type' => $type,
                'deleted' => $deleted,
                'admin_id' => user_id()
            ]);

            $_SESSION['success'] = $message;
            return redirect('/admin/activity-logs');

        } catch (\Exception $e) {
            logger()->error('activity_log.cleanup.failed', [
                'error' => $e->getMessage()
            ]);
            
            $_SESSION['error'] = 'خطا در حذف لاگ‌ها';
            return redirect('/admin/activity-logs');
        }
    }

    /**
     * Export
     */
    public function export()
    {
        try {
            $filters = array_filter([
                'action' => $this->request->get('action'),
                'user_id' => $this->request->get('user_id'),
                'from' => $this->request->get('from'),
                'to' => $this->request->get('to'),
            ]);

            $this->exportService->exportActivityLogs($filters);

        } catch (\Exception $e) {
            logger()->error('activity_log.export.failed', [
                'error' => $e->getMessage()
            ]);
            
            $_SESSION['error'] = 'خطا در ایجاد خروجی';
            return redirect('/admin/activity-logs');
        }
    }
}
