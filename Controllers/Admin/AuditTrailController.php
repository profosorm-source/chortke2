<?php

namespace App\Controllers\Admin;

use App\Services\AuditTrail;
use App\Services\ExportService;
use App\Controllers\Admin\BaseAdminController;

class AuditTrailController extends BaseAdminController
{
    private AuditTrail    $audit;
    private ExportService $exportService;

    public function __construct(
        AuditTrail    $audit,
        ExportService $exportService
    ) {
        parent::__construct();
        $this->audit         = $audit;
        $this->exportService = $exportService;
    }

    public function index(): void
    {
        $page = max(1, (int)($this->request->get('page') ?? 1));

        $result     = $this->audit->getAll(
            page:    $page,
            perPage: 50,
            event:   $this->request->get('event')   ?: null,
            userId:  $this->request->get('user_id') ? (int)$this->request->get('user_id') : null,
            search:  $this->request->get('search')  ?: null,
            from:    $this->request->get('from')    ?: null,
            to:      $this->request->get('to')      ?: null,
        );
        $eventTypes = $this->audit->getEventTypes();

        view('admin.audit-trail.index', [
            'title'      => 'Audit Trail',
            'result'     => $result,
            'eventTypes' => $eventTypes,
        ]);
    }

    public function export(): void
    {
        $filters = array_filter([
            'event'   => $this->request->get('event'),
            'from'    => $this->request->get('from'),
            'to'      => $this->request->get('to'),
            'user_id' => $this->request->get('user_id'),
        ]);
        $this->exportService->exportAuditTrail($filters);
    }
}
