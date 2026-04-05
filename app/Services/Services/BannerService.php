<?php

namespace App\Services;

use App\Models\Banner;
use App\Models\BannerPlacement;

class BannerService
{
    private \App\Models\BannerPlacement $bannerPlacementModel;
    private Banner $bannerModel;
    private BannerPlacement $placementModel;
    private \App\Services\UploadService $uploadService;

    public function __construct(
        \App\Models\Banner $bannerModel,
        \App\Models\BannerPlacement $placementModel,
        \App\Models\BannerPlacement $bannerPlacementModel,
        \App\Services\UploadService $uploadService)
    {
        $this->bannerModel = $bannerModel;
        $this->placementModel = $placementModel;
        $this->bannerPlacementModel = $bannerPlacementModel;
        $this->uploadService = $uploadService;
    }

    public function getActiveBanners(string $placement): array
    {
        $placementObj = $this->placementModel->findBySlug($placement);
        if (!$placementObj || !$placementObj->is_active) {
            return [];
        }

        $banners = $this->bannerModel->getActiveByPlacement($placement);

        if (\count($banners) > $placementObj->max_banners) {
            $banners = \array_slice($banners, 0, $placementObj->max_banners);
        }

        foreach ($banners as $banner) {
            $this->bannerModel->incrementImpression($banner->id);
        }

        return [
            'banners' => $banners,
            'placement' => $placementObj,
        ];
    }

    public function createBanner(array $data, int $createdBy): array
    {
        $errors = $this->validateBanner($data);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $data['created_by'] = $createdBy;

        if (isset($data['image_file']) && $data['image_file']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = $this->uploadBannerImage($data['image_file']);
            if (!$uploadResult['success']) {
                return ['success' => false, 'errors' => ['image' => $uploadResult['error']]];
            }
            $data['image_path'] = $uploadResult['path'];
        }

        unset($data['image_file']);

        $id = $this->bannerModel->create($data);
        if (!$id) {
            return ['success' => false, 'errors' => ['general' => 'خطا در ایجاد بنر']];
        }

        logger('banner_created', "بنر جدید با شناسه {$id} ایجاد شد", 'info');
        return ['success' => true, 'banner_id' => $id];
    }

    public function updateBanner(int $id, array $data): array
    {
        $banner = $this->bannerModel->find($id);
        if (!$banner) {
            return ['success' => false, 'errors' => ['general' => 'بنر یافت نشد']];
        }

        $errors = $this->validateBanner($data, true);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        if (isset($data['image_file']) && $data['image_file']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = $this->uploadBannerImage($data['image_file']);
            if (!$uploadResult['success']) {
                return ['success' => false, 'errors' => ['image' => $uploadResult['error']]];
            }

            if ($banner->image_path) {
                $oldPath = \rtrim($_SERVER['DOCUMENT_ROOT'] ?? '.', '/') . '/' . \ltrim($banner->image_path, '/');
                if (\file_exists($oldPath)) {
                    @\unlink($oldPath);
                }
            }

            $data['image_path'] = $uploadResult['path'];
        }

        unset($data['image_file']);

        $result = $this->bannerModel->update($id, $data);
        if (!$result) {
            return ['success' => false, 'errors' => ['general' => 'خطا در بروزرسانی بنر']];
        }

        logger('banner_updated', "بنر {$id} بروزرسانی شد", 'info');
        return ['success' => true];
    }

    public function deleteBanner(int $id): array
    {
        $banner = $this->bannerModel->find($id);
        if (!$banner) {
            return ['success' => false, 'message' => 'بنر یافت نشد'];
        }

        $this->bannerModel->softDelete($id);

        if ($banner->image_path) {
            $filePath = \rtrim($_SERVER['DOCUMENT_ROOT'] ?? '.', '/') . '/' . \ltrim($banner->image_path, '/');
            if (\file_exists($filePath)) {
                @\unlink($filePath);
            }
        }

        logger('banner_deleted', "بنر {$id} حذف شد", 'warning');
        return ['success' => true, 'message' => 'بنر با موفقیت حذف شد'];
    }

    public function toggleBanner(int $id): array
    {
        $banner = $this->bannerModel->find($id);
        if (!$banner) {
            return ['success' => false, 'message' => 'بنر یافت نشد'];
        }

        $newStatus = $banner->is_active ? 0 : 1;
        $this->bannerModel->update($id, ['is_active' => $newStatus]);
        $statusText = $newStatus ? 'فعال' : 'غیرفعال';

        logger('banner_toggle', "بنر {$id} {$statusText} شد", 'info');
        return ['success' => true, 'is_active' => $newStatus, 'message' => "بنر با موفقیت {$statusText} شد"];
    }

    public function trackClick(int $bannerId): array
    {
        $banner = $this->bannerModel->find($bannerId);
        if (!$banner || !$banner->is_active) {
            return ['success' => false, 'redirect' => '/'];
        }

        $userId = auth() ? user_id() : null;
        $ip = get_client_ip();
        $userAgent = get_user_agent();
        $referer = $_SERVER['HTTP_REFERER'] ?? null;
        $fingerprint = $_COOKIE['device_fp'] ?? null;

        $this->bannerModel->registerClick($bannerId, $userId, $ip, $userAgent, $referer, $fingerprint);

        return ['success' => true, 'redirect' => $banner->link ?: '/'];
    }

    public function deactivateExpired(): int
    {
        $count = $this->bannerModel->deactivateExpired();
        if ($count > 0) {
            logger('banners_expired', "{$count} بنر منقضی غیرفعال شد", 'info');
        }
        return $count;
    }

    public function updatePlacement(int $id, array $data): array
    {
        $placement = $this->placementModel->find($id);
        if (!$placement) {
            return ['success' => false, 'message' => 'جایگاه یافت نشد'];
        }

        $result = $this->placementModel->update($id, $data);
        if (!$result) {
            return ['success' => false, 'message' => 'خطا در بروزرسانی'];
        }

        logger('placement_updated', "جایگاه {$placement->slug} بروزرسانی شد", 'info');
        return ['success' => true, 'message' => 'جایگاه با موفقیت بروزرسانی شد'];
    }

    public function togglePlacement(int $id): array
    {
        $placement = $this->placementModel->find($id);
        if (!$placement) {
            return ['success' => false, 'message' => 'جایگاه یافت نشد'];
        }

        $newStatus = $placement->is_active ? 0 : 1;
        $this->placementModel->update($id, ['is_active' => $newStatus]);
        $statusText = $newStatus ? 'فعال' : 'غیرفعال';

        return ['success' => true, 'is_active' => $newStatus, 'message' => "جایگاه {$statusText} شد"];
    }

    protected function uploadBannerImage(array $file): array
    {
        $result = $this->uploadService->upload(
            $file,
            'banners',
            ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            2 * 1024 * 1024 // 2MB
        );

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['message']];
        }

        return ['success' => true, 'path' => $result['path'], 'filename' => $result['filename']];
    }

    protected function validateBanner(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        if (!$isUpdate) {
            if (empty($data['title'])) {
                $errors['title'] = 'عنوان بنر الزامی است';
            }
            if (empty($data['placement'])) {
                $errors['placement'] = 'جایگاه بنر الزامی است';
            }
        }

        if (!empty($data['title']) && \mb_strlen($data['title']) > 255) {
            $errors['title'] = 'عنوان حداکثر 255 کاراکتر';
        }

        if (!empty($data['link']) && !\filter_var($data['link'], FILTER_VALIDATE_URL)) {
            $errors['link'] = 'لینک معتبر نیست';
        }

        $validPlacements = ['header', 'footer', 'sidebar', 'homepage', 'dashboard_user', 'dashboard_admin'];
        if (!empty($data['placement']) && !\in_array($data['placement'], $validPlacements)) {
            $errors['placement'] = 'جایگاه نامعتبر است';
        }

        if (!empty($data['start_date']) && !empty($data['end_date'])) {
            if (\strtotime($data['end_date']) <= \strtotime($data['start_date'])) {
                $errors['end_date'] = 'تاریخ پایان باید بعد از تاریخ شروع باشد';
            }
        }

        return $errors;
    }
}
