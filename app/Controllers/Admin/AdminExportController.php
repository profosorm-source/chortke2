<?php

namespace App\Controllers\Admin;

use App\Services\ExportService;
use App\Services\AuditLogger;
use App\Controllers\Admin\BaseAdminController;

/**
 * AdminExportController - مرکز خروجی‌گیری ادمین
 */
class AdminExportController extends BaseAdminController
{
    private ExportService $exportService;
    private AuditLogger $auditLogger;

    public function __construct(
        ExportService $exportService,
        AuditLogger $auditLogger
    ) {
        parent::__construct();
        $this->exportService = $exportService;
        $this->auditLogger = $auditLogger;
    }

    /** صفحه اصلی Export */
    public function index(): void
    {
        view('admin.export.index', ['title' => 'خروجی‌گیری داده']);
    }

    /** خروجی کاربران */
    public function users(): void
    {
        $filters = $this->filters();
        
        $this->auditLogger->record(
            AuditLogger::ADMIN_EXPORT,
            null,
            ['type' => 'users', 'filters' => $filters],
            user_id()
        );
        
        $this->exportService->exportUsers($filters);
    }

    /** خروجی تراکنش‌ها */
    public function transactions(): void
    {
        $filters = $this->filters();
        
        $this->auditLogger->record(
            AuditLogger::ADMIN_EXPORT,
            null,
            ['type' => 'transactions', 'filters' => $filters],
            user_id()
        );
        
        $this->exportService->exportTransactionsStream($filters);
    }

    /** خروجی برداشت‌ها */
    public function withdrawals(): void
    {
        $filters = $this->filters();
        
        $this->auditLogger->record(
            AuditLogger::ADMIN_EXPORT,
            null,
            ['type' => 'withdrawals', 'filters' => $filters],
            user_id()
        );
        
        $this->exportService->exportWithdrawalsStream($filters);
    }

    /** خروجی Audit Trail */
    public function auditTrail(): void
    {
        $filters = $this->filters();
        
        $this->auditLogger->record(
            AuditLogger::ADMIN_EXPORT,
            null,
            ['type' => 'audit_trail', 'filters' => $filters],
            user_id()
        );
        
        $this->exportService->exportAuditTrail($filters);
    }

    private function filters(): array
    {
        return array_filter([
            'from' => $this->request->get('from'),
            'to' => $this->request->get('to'),
            'status' => $this->request->get('status'),
            'user_id' => $this->request->get('user_id'),
            'type' => $this->request->get('type'),
        ]);
    }
}
