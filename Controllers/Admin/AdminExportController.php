<?php

namespace App\Controllers\Admin;

use App\Services\ExportService;
use App\Services\AuditTrail;
use App\Controllers\Admin\BaseAdminController;

/**
 * AdminExportController - مرکز خروجی‌گیری ادمین
 *
 * همه Export ها از یک جا مدیریت می‌شوند
 */
class AdminExportController extends BaseAdminController
{
    private \App\Services\ExportService $exportService;
    private ExportService $exporter;

    public function __construct(
        
        \App\Services\ExportService $exportService
    )
    {
parent::__construct();
        $this->exporter = $this->exportService;
        $this->exportService = $exportService;
    }

    /** صفحه اصلی Export */
    public function index(): void
    {
        view('admin.export.index', ['title' => 'خروجی‌گیری داده']);
    }

    /** خروجی کاربران */
    public function users(): void
    {
        $f = $this->filters();
        AuditTrail::record('admin.export', null, ['type' => 'users', 'filters' => $f]);
        $this->exporter->exportUsers($f);
    }

    /** خروجی تراکنش‌ها */
    public function transactions(): void
    {
        $f = $this->filters();
        AuditTrail::record('admin.export', null, ['type' => 'transactions', 'filters' => $f]);
        $this->exporter->exportTransactionsStream($f);
    }

    /** خروجی برداشت‌ها */
    public function withdrawals(): void
    {
        $f = $this->filters();
        AuditTrail::record('admin.export', null, ['type' => 'withdrawals', 'filters' => $f]);
        $this->exporter->exportWithdrawalsStream($f);
    }

    /** خروجی Audit Trail */
    public function auditTrail(): void
    {
        $f = $this->filters();
        AuditTrail::record('admin.export', null, ['type' => 'audit_trail', 'filters' => $f]);
        $this->exporter->exportAuditTrail($f);
    }

    private function filters(): array
    {
        return array_filter([
            'from'        => $this->request->get('from'),
            'to'          => $this->request->get('to'),
            'status'      => $this->request->get('status'),
            'type'        => $this->request->get('type'),
            'currency'    => $this->request->get('currency'),
            'kyc_status'  => $req->get('kyc_status'),
            'tier_level'  => $req->get('tier_level'),
            'event'       => $req->get('event'),
            'user_id'     => $req->get('user_id'),
        ]);
    }
}
