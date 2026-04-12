<?php

namespace App\Controllers\Admin;

use App\Services\AuditLogger;
use App\Services\ExportService;
use App\Controllers\Admin\BaseAdminController;

/**
 * AuditTrailController - مدیریت Audit Trail
 */
class AuditTrailController extends BaseAdminController
{
    private AuditLogger $auditLogger;
    private ExportService $exportService;

    public function __construct(AuditLogger $auditLogger, ExportService $exportService)
    {
        parent::__construct();
        $this->auditLogger = $auditLogger;
        $this->exportService = $exportService;
    }

    /**
     * لیست رویدادها
     */
    public function index()
    {
        try {
            $page = max(1, (int)($this->request->get('page') ?? 1));
            $event = $this->request->get('event');
            $userId = $this->request->get('user_id') ? (int)$this->request->get('user_id') : null;
            $search = $this->request->get('search');
            $dateFrom = $this->request->get('date_from');
            $dateTo = $this->request->get('date_to');

            $result = $this->auditLogger->getAll(
                page: $page,
                perPage: 50,
                event: $event ?: null,
                userId: $userId,
                search: $search ?: null,
                dateFrom: $dateFrom ?: null,
                dateTo: $dateTo ?: null
            );

            $eventTypes = $this->auditLogger->getEventTypes();

            return view('admin.audit-trail.index', [
                'user' => auth()->user(),
                'title' => 'Audit Trail',
                'result' => $result,
                'eventTypes' => $eventTypes,
                'filters' => [
                    'event' => $event,
                    'user_id' => $userId,
                    'search' => $search,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                ],
            ]);

        } catch (\Exception $e) {
            logger()->error('audit_trail.index.failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return view('errors.500');
        }
    }

    /**
     * مشاهده جزئیات رویداد
     */
    public function show()
    {
        try {
            $id = (int)$this->request->param('id');
            
            $stmt = db()->query(
                "SELECT at.*, u.full_name as user_name, u.email as user_email,
                        a.full_name as actor_name, a.email as actor_email
                 FROM audit_trail at
                 LEFT JOIN users u ON at.user_id = u.id
                 LEFT JOIN users a ON at.actor_id = a.id
                 WHERE at.id = ?",
                [$id]
            );

            if (!$stmt instanceof \PDOStatement) {
                return view('errors.404');
            }

            $event = $stmt->fetch(\PDO::FETCH_OBJ);
            
            if (!$event) {
                return view('errors.404');
            }

            return view('admin.audit-trail.show', [
                'user' => auth()->user(),
                'title' => 'جزئیات رویداد',
                'event' => $event,
            ]);

        } catch (\Exception $e) {
            logger()->error('audit_trail.show.failed', [
                'error' => $e->getMessage(),
                'id' => $id ?? null
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
            $dateFrom = $this->request->get('date_from') ?: date('Y-m-d', strtotime('-30 days'));
            $dateTo = $this->request->get('date_to') ?: date('Y-m-d');

            $stats = $this->auditLogger->getStats($dateFrom, $dateTo);
            $eventTypes = $this->auditLogger->getEventTypes();

            return view('admin.audit-trail.stats', [
                'user' => auth()->user(),
                'title' => 'آمار Audit Trail',
                'stats' => $stats,
                'eventTypes' => $eventTypes,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ]);

        } catch (\Exception $e) {
            logger()->error('audit_trail.stats.failed', [
                'error' => $e->getMessage()
            ]);
            return view('errors.500');
        }
    }

    /**
     * تاریخچه کاربر
     */
    public function userHistory()
    {
        try {
            $userId = (int)$this->request->param('user_id');
            $limit = (int)($this->request->get('limit') ?? 100);

            $history = $this->auditLogger->getForUser($userId, $limit);

            return view('admin.audit-trail.user-history', [
                'user' => auth()->user(),
                'title' => 'تاریخچه کاربر',
                'history' => $history,
                'userId' => $userId,
            ]);

        } catch (\Exception $e) {
            logger()->error('audit_trail.user_history.failed', [
                'error' => $e->getMessage(),
                'user_id' => $userId ?? null
            ]);
            return view('errors.500');
        }
    }

    /**
     * Export
     */
    public function export()
    {
        try {
            $filters = array_filter([
                'event' => $this->request->get('event'),
                'from' => $this->request->get('from'),
                'to' => $this->request->get('to'),
                'user_id' => $this->request->get('user_id'),
            ]);

            $this->exportService->exportAuditTrail($filters);

            $this->auditLogger->record(
                AuditLogger::ADMIN_EXPORT,
                null,
                ['type' => 'audit_trail', 'filters' => $filters]
            );

        } catch (\Exception $e) {
            logger()->error('audit_trail.export.failed', [
                'error' => $e->getMessage()
            ]);
            
            $_SESSION['error'] = 'خطا در ایجاد خروجی';
            return redirect('/admin/audit-trail');
        }
    }
}
