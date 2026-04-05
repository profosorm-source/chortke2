<?php

namespace App\Controllers\Admin;

use App\Services\SEOKeywordService;
use Core\Validator;

class SEOKeywordController extends BaseAdminController
{
    private \App\Services\SEOKeywordService $sEOKeywordService;
    private SEOKeywordService $service;

    public function __construct(
        
        \App\Services\SEOKeywordService $sEOKeywordService
    )
    {
parent::__construct();
        $this->service = $this->sEOKeywordService;
        $this->sEOKeywordService = $sEOKeywordService;
    }

    public function index()
    {
        $page   = (int)($_GET['page'] ?? 1);
        $limit  = 30;
        $offset = ($page - 1) * $limit;
        $filters = [];
        if (isset($_GET['is_active']) && $_GET['is_active'] !== '') $filters['is_active'] = $_GET['is_active'];
        if (!empty($_GET['search'])) $filters['search'] = $_GET['search'];

        $total      = $this->service->countAll($filters);
        $keywords   = $this->service->getAll($filters, $limit, $offset);
        $stats      = $this->service->getStats();
        $totalPages = (int)ceil($total / $limit);

        return view('admin.seo-keywords.index', [
            'keywords'   => $keywords, 'filters' => $filters, 'stats' => $stats,
            'page' => $page, 'totalPages' => $totalPages, 'total' => $total,
        ]);
    }

    public function showCreate()
    {
        $currency = setting('currency_mode') ?? 'irt';
        return view('admin.seo-keywords.create', ['currency' => $currency]);
    }

    public function store()
    {
        $data = $this->request->body();

        $validator = new Validator($data, [
            'keyword'    => 'required|string|min:2|max:255',
            'target_url' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', $validator->errors()[0] ?? 'خطا');
            return redirect(url('/admin/seo-keywords/create'));
        }

        $data['created_by'] = user_id();
        $data['currency']   = setting('currency_mode') ?? 'irt';

        $keyword = $this->service->create($data);
        if ($keyword) {
            log_activity('seo_keyword_create', 'ایجاد کلمه: ' . $data['keyword'], $keyword->id, 'seo_keyword');
            $this->session->setFlash('success', 'کلمه کلیدی با موفقیت ایجاد شد.');
            return redirect(url('/admin/seo-keywords'));
        }

        $this->session->setFlash('error', 'خطا در ایجاد.');
        return redirect(url('/admin/seo-keywords/create'));
    }

    public function showEdit()
    {
        $id      = (int)$this->request->param('id');
        $keyword = $this->service->find($id);
        if (!$keyword) {
            $this->session->setFlash('error', 'یافت نشد.');
            return redirect(url('/admin/seo-keywords'));
        }
        return view('admin.seo-keywords.edit', ['keyword' => $keyword]);
    }

    public function update()
    {
        $id   = (int)$this->request->param('id');
        $data = $this->request->body();

        $validator = new Validator($data, [
            'keyword'    => 'required|string|min:2|max:255',
            'target_url' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', $validator->errors()[0] ?? 'خطا');
            return redirect(url('/admin/seo-keywords/' . $id . '/edit'));
        }

        $this->service->update($id, $data);
        log_activity('seo_keyword_update', 'ویرایش کلمه #' . $id, $id, 'seo_keyword');
        $this->session->setFlash('success', 'بروزرسانی شد.');
        return redirect(url('/admin/seo-keywords'));
    }

    public function toggleActive()
    {
        $id     = (int)$this->request->param('id');
        $result = $this->service->toggleActive($id);
        if (!$result['success']) {
            return $this->response->json(['success' => false, 'message' => $result['message']]);
        }
        $label = $result['was_active'] ? 'غیرفعال' : 'فعال';
        log_activity('seo_keyword_toggle', "{$label} کلمه #{$id}", $id, 'seo_keyword');
        return $this->response->json(['success' => true, 'message' => 'وضعیت تغییر کرد.']);
    }

    public function delete()
    {
        $id = (int)$this->request->param('id');
        $this->service->delete($id);
        log_activity('seo_keyword_delete', 'حذف کلمه #' . $id, $id, 'seo_keyword');
        return $this->response->json(['success' => true, 'message' => 'حذف شد.']);
    }
}
