<?php

namespace App\Controllers\Admin;

use App\Models\SystemSetting;
use Core\Validator;
use App\Controllers\Admin\BaseAdminController;

class SystemSettingController extends BaseAdminController
{
    private \App\Services\SettingService $settingService;
    private SystemSetting $settingModel;
    
    public function __construct(
        \App\Models\SystemSetting $settingModel,
        \App\Services\SettingService $settingService)
    {
        parent::__construct();
        $this->settingModel = $settingModel;
        $this->settingService = $settingService;
    }
    
    /**
     * نمایش تنظیمات
     */
    public function index()
    {
        $category = $_GET['category'] ?? 'general';
        
        $settings = $this->settingModel->getByCategory($category);
        
        $categories = [
            'general' => 'عمومی',
            'banking' => 'بانکی',
            'task'    => 'تسک‌ها',
            'wallet'  => 'کیف پول',
            'security'=> 'امنیت',
            'contact' => 'تماس',
            'images'  => 'تصاویر و لوگو',
        ];
        
        return view('admin/settings/index', [
            'settings' => $settings,
            'categories' => $categories,
            'currentCategory' => $category
        ]);
    }
    
    /**
     * بروزرسانی تنظیم
     */
   public function update(): void
{
    
    $raw  = \file_get_contents('php://input');
    $data = \json_decode($raw, true);

    if (!\is_array($data)) {
        $this->response->json(['success' => false, 'message' => 'بدنه JSON نامعتبر است.'], 422);
        return;
    }

    $id    = (int)($data['id'] ?? 0);
    $key   = \trim((string)($data['key'] ?? ''));
    $value = (string)($data['value'] ?? '');

    if ($id <= 0 || $key === '') {
        $this->response->json(['success' => false, 'message' => 'درخواست نامعتبر است.'], 422);
        return;
    }

    $service = $this->settingService;

    $ok = $service->updateById($id, $key, $value);

    if (!$ok) {
        $this->response->json([
            'success' => false,
            'message' => 'تنظیمات یافت نشد یا کلید معتبر نیست.'
        ], 400);
        return;
    }

    // ✅ اینجا بهترین جای clearCache است
    $service->clearCache();

    // اگر helper settings() کش داخل-request دارد
    if (\function_exists('settings')) {
        settings(true);
    }

    $this->response->json([
        'success' => true,
        'message' => 'تنظیمات ذخیره شد.'
    ]);
}


/**
 * آپلود تصویر برای تنظیمات
 */
public function uploadImage(): void
{
    // بررسی آپلود فایل
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $this->response->json([
            'success' => false,
            'message' => 'فایلی آپلود نشده است'
        ], 400);
        return;
    }
    
    $settingId = (int)($_POST['setting_id'] ?? 0);
    if ($settingId <= 0) {
        $this->response->json([
            'success' => false,
            'message' => 'شناسه تنظیم نامعتبر است'
        ], 400);
        return;
    }
    
    // دریافت اطلاعات تنظیم
    $setting = $this->settingModel->find($settingId);
    if (!$setting || ($setting->category !== 'images' && $setting->type !== 'image')) {
        $this->response->json([
            'success' => false,
            'message' => 'تنظیم یافت نشد یا نوع آن تصویر نیست'
        ], 404);
        return;
    }
    
    $file = $_FILES['image'];
    
    // اعتبارسنجی نوع فایل
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/x-icon'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        $this->response->json([
            'success' => false,
            'message' => 'فرمت فایل مجاز نیست. فرمت‌های مجاز: JPG, PNG, GIF, SVG, ICO'
        ], 400);
        return;
    }
    
    // بررسی حجم (2MB)
    if ($file['size'] > 2 * 1024 * 1024) {
        $this->response->json([
            'success' => false,
            'message' => 'حجم فایل نباید بیشتر از 2 مگابایت باشد'
        ], 400);
        return;
    }
    
    try {
        // حذف تصویر قبلی
        if (!empty($setting->value)) {
            $oldPath = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($setting->value, '/');
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }
        
        // آپلود فایل جدید با استفاده از تابع موجود
        $imagePath = upload_file($file, 'site-images');
        
        // بهینه‌سازی تصویر
        $this->optimizeImage($imagePath);
        
        // بروزرسانی در دیتابیس
        $updated = $this->settingModel->updateValueById($settingId, $imagePath);
        
        if (!$updated) {
            throw new \Exception('خطا در ذخیره اطلاعات');
        }
        
        // پاک کردن cache
        $this->settingService->clearCache();
        
        $this->response->json([
            'success' => true,
            'message' => 'تصویر با موفقیت آپلود شد',
            'url' => url($imagePath),
            'path' => $imagePath
        ]);
        
    } catch (\Exception $e) {
        $this->response->json([
            'success' => false,
            'message' => 'خطا در آپلود تصویر: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * حذف تصویر
 */
public function removeImage(): void
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    
    $settingId = (int)($data['setting_id'] ?? 0);
    if ($settingId <= 0) {
        $this->response->json([
            'success' => false,
            'message' => 'شناسه تنظیم نامعتبر است'
        ], 400);
        return;
    }
    
    // دریافت اطلاعات تنظیم
    $setting = $this->settingModel->find($settingId);
    if (!$setting) {
        $this->response->json([
            'success' => false,
            'message' => 'تنظیم یافت نشد'
        ], 404);
        return;
    }
    
    try {
        // حذف فایل فیزیکی
        if (!empty($setting->value)) {
            $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($setting->value, '/');
            if (file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
        
        // پاک کردن مقدار در دیتابیس
        $updated = $this->settingModel->updateValueById($settingId, '');
        
        if (!$updated) {
            throw new \Exception('خطا در بروزرسانی');
        }
        
        // پاک کردن cache
        $this->settingService->clearCache();
        
        $this->response->json([
            'success' => true,
            'message' => 'تصویر با موفقیت حذف شد'
        ]);
        
    } catch (\Exception $e) {
        $this->response->json([
            'success' => false,
            'message' => 'خطا در حذف تصویر: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * بهینه‌سازی تصویر
 */
private function optimizeImage(string $relativePath): void
{
    $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($relativePath, '/');
    
    if (!file_exists($fullPath)) {
        return;
    }
    
    $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    
    try {
        if (in_array($extension, ['jpg', 'jpeg'])) {
            $image = imagecreatefromjpeg($fullPath);
            if ($image) {
                imagejpeg($image, $fullPath, 85);
                imagedestroy($image);
            }
        } elseif ($extension === 'png') {
            $image = imagecreatefrompng($fullPath);
            if ($image) {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                imagepng($image, $fullPath, 8);
                imagedestroy($image);
            }
        }
    } catch (\Exception $e) {
        // در صورت خطا، تصویر را همانطور نگه دار
    }
}
}