<?php

namespace App\Controllers\Admin;

use App\Models\Banner;
use App\Models\BannerPlacement;
use App\Services\BannerService;
use App\Controllers\Admin\BaseAdminController;

class BannerController extends BaseAdminController
{
    private Banner $bannerModel;
    private BannerPlacement $placementModel;
    private BannerService $bannerService;

    public function __construct(
        \App\Models\Banner $bannerModel,
        \App\Models\BannerPlacement $placementModel,
        \App\Services\BannerService $bannerService)
    {
        parent::__construct();
        $this->bannerModel = $bannerModel;
        $this->placementModel = $placementModel;
        $this->bannerService = $bannerService;
    }

    public function index()
    {
        $page = (int)($this->request->get('page') ?: 1);
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $filters = \array_filter([
            'placement' => $this->request->get('placement'),
            'is_active'  => $this->request->get('is_active') !== null && $this->request->get('is_active') !== '' ? $this->request->get('is_active') : null,
            'search'     => $this->request->get('search'),
        ], fn($v) => $v !== null && $v !== '');

        $banners    = $this->bannerModel->all($filters, $perPage, $offset);
        $total      = $this->bannerModel->count($filters);
        $totalPages = (int)\ceil($total / $perPage);
        $placements = $this->placementModel->allWithBannerCount();
        $stats      = $this->bannerModel->getStats();

        return view('admin.banners.index', compact('banners', 'placements', 'stats', 'filters', 'page', 'totalPages', 'total'));
    }

    public function showCreate()
    {
        $placements = $this->placementModel->all();
        return view('admin.banners.create', compact('placements'));
    }

    public function store()
    {
        $data = [
            'title'       => $this->request->post('title'),
            'link'        => $this->request->post('link'),
            'placement'   => $this->request->post('placement'),
            'type'        => $this->request->post('type') ?: 'image',
            'custom_code' => $this->request->post('custom_code'),
            'sort_order'  => (int)($this->request->post('sort_order') ?: 0),
            'is_active'   => $this->request->post('is_active') !== null ? (int)$this->request->post('is_active') : 1,
            'start_date'  => $this->request->post('start_date') ?: null,
            'end_date'    => $this->request->post('end_date') ?: null,
            'target'      => $this->request->post('target') ?: '_blank',
            'alt_text'    => $this->request->post('alt_text'),
        ];

        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $data['image_file'] = $_FILES['image'];
        }

        $result = $this->bannerService->createBanner($data, user_id());

        if (!$result['success']) {
            $this->session->setFlash('error', 'خطا در ایجاد بنر');
            $this->session->setFlash('errors', $result['errors']);
            $this->session->setFlash('old', $data);
            return redirect(url('/admin/banners/create'));
        }

        $this->session->setFlash('success', 'بنر با موفقیت ایجاد شد');
        return redirect(url('/admin/banners'));
    }

    public function showEdit()
    {
        $id = (int)$this->request->param('id');
        $banner = $this->bannerModel->find($id);

        if (!$banner) {
            $this->session->setFlash('error', 'بنر یافت نشد');
            return redirect(url('/admin/banners'));
        }

        $placements = $this->placementModel->all();
        return view('admin.banners.edit', compact('banner', 'placements'));
    }

    public function update()
    {
        $id = (int)$this->request->param('id');

        $data = [
            'title'       => $this->request->post('title'),
            'link'        => $this->request->post('link'),
            'placement'   => $this->request->post('placement'),
            'type'        => $this->request->post('type') ?: 'image',
            'custom_code' => $this->request->post('custom_code'),
            'sort_order'  => (int)($this->request->post('sort_order') ?: 0),
            'is_active'   => $this->request->post('is_active') !== null ? (int)$this->request->post('is_active') : 1,
            'start_date'  => $this->request->post('start_date') ?: null,
            'end_date'    => $this->request->post('end_date') ?: null,
            'target'      => $this->request->post('target') ?: '_blank',
            'alt_text'    => $this->request->post('alt_text'),
        ];

        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $data['image_file'] = $_FILES['image'];
        }

        $result = $this->bannerService->updateBanner($id, $data);

        if (!$result['success']) {
            $this->session->setFlash('error', 'خطا در بروزرسانی بنر');
            $this->session->setFlash('errors', $result['errors']);
            return redirect(url("/admin/banners/{$id}/edit"));
        }

        $this->session->setFlash('success', 'بنر با موفقیت بروزرسانی شد');
        return redirect(url('/admin/banners'));
    }

    public function delete(): void
    {
        $id = (int)$this->request->param('id');
        $result = $this->bannerService->deleteBanner($id);
        $this->response->json($result);
    }

    public function toggle(): void
    {
        $id = (int)$this->request->param('id');
        $result = $this->bannerService->toggleBanner($id);
        $this->response->json($result);
    }

    public function trackClick(): void
    {
        $id = (int)$this->request->param('id');
        $result = $this->bannerService->trackClick($id);
        $this->response->json($result);
    }

    public function placements()
    {
        $placements = $this->placementModel->allWithBannerCount();
        return view('admin.banners.placements', compact('placements'));
    }

    public function updatePlacement(): void
    {
        $id = (int)$this->request->param('id');
        $data = \json_decode(\file_get_contents('php://input'), true) ?? [];
        $result = $this->bannerService->updatePlacement($id, $data);
        $this->response->json($result);
    }

    public function togglePlacement(): void
    {
        $id = (int)$this->request->param('id');
        $result = $this->bannerService->togglePlacement($id);
        $this->response->json($result);
    }

    public function stats(): void
    {
        $id = (int)$this->request->param('id');
        $banner = $this->bannerModel->find($id);

        if (!$banner) {
            $this->response->json(['success' => false, 'message' => 'بنر یافت نشد']);
            return;
        }

        $clickStats = $this->bannerModel->getClickStats($id, 30);

        $this->response->json([
            'success' => true,
            'banner' => [
                'id' => $banner->id, 'title' => $banner->title,
                'clicks' => $banner->clicks, 'impressions' => $banner->impressions, 'ctr' => $banner->ctr,
            ],
            'daily_clicks' => $clickStats,
        ]);
    }
}
