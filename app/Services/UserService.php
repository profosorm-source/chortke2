<?php
namespace App\Services;

use App\Models\User;
use App\Models\ActivityLog;

/**
 * User Service
 */
class UserService
{
    private $userModel;
    private $activityLog;

    public function __construct(
        \App\Models\User $userModel,
        \App\Models\ActivityLog $activityLog
    )
    {
        $this->userModel = $userModel;
        $this->activityLog = $activityLog;
    }

    /**
     * بروزرسانی پروفایل
     */
    public function updateProfile($userId, array $data)
    {
        // فیلدهای مجاز برای بروزرسانی
        $allowedFields = ['full_name', 'birth_date', 'gender'];
        $updateData = array_intersect_key($data, array_flip($allowedFields));
        
        if (empty($updateData)) {
            return [
                'success' => false,
                'message' => 'هیچ داده‌ای برای بروزرسانی ارسال نشده است.'
            ];
        }
        
        $result = $this->userModel->update($userId, $updateData);
        
        if ($result) {
            $this->activityLog->log('profile_updated', 'بروزرسانی پروفایل', $userId, $updateData);
            
            return [
                'success' => true,
                'message' => 'پروفایل با موفقیت بروزرسانی شد.'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'خطا در بروزرسانی پروفایل.'
        ];
    }

    /**
     * تغییر رمز عبور
     */
    public function changePassword($userId, $currentPassword, $newPassword)
    {
        // بررسی رمز فعلی
        if (!$this->userModel->verifyPassword($userId, $currentPassword)) {
            return [
                'success' => false,
                'message' => 'رمز عبور فعلی اشتباه است.'
            ];
        }
        
        // تغییر رمز
        $this->userModel->changePassword($userId, $newPassword);
        
        // ثبت لاگ
        $this->activityLog->log('password_changed', 'تغییر رمز عبور', $userId);
        
        return [
            'success' => true,
            'message' => 'رمز عبور با موفقیت تغییر کرد.'
        ];
    }

    /**
     * آپلود آواتار
     */
    public function uploadAvatar($userId, $file)
    {
        try {
            // بررسی نوع فایل
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            if (!in_array($file['type'], $allowedTypes)) {
                return [
                    'success' => false,
                    'message' => 'فرمت فایل مجاز نیست. فقط JPG, PNG, GIF مجاز است.'
                ];
            }
            
            // بررسی حجم (2MB)
            if ($file['size'] > 2097152) {
                return [
                    'success' => false,
                    'message' => 'حجم فایل نباید بیشتر از 2 مگابایت باشد.'
                ];
            }
            
            // حذف آواتار قبلی
            $user = $this->userModel->find($userId);
            if ($user && $user['avatar']) {
                delete_file($user['avatar']);
            }
            
            // آپلود فایل
            $path = upload_file($file, 'avatars');
            
            // بروزرسانی دیتابیس
            $this->userModel->update($userId, ['avatar' => $path]);
            
            // ثبت لاگ
            $this->activityLog->log('avatar_uploaded', 'آپلود تصویر پروفایل', $userId);
            
            return [
                'success' => true,
                'message' => 'تصویر پروفایل با موفقیت آپلود شد.',
                'path' => $path
            ];
            
        } catch (\Exception $e) {
            logger('error', 'Avatar upload failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'خطا در آپلود تصویر.'
            ];
        }
    }

    /**
     * دریافت آمار داشبورد
     */
    public function getDashboardStats($userId)
    {
        return $this->userModel->getUserStats($userId);
    }

    public function findById(int $userId): ?object
    {
        return $this->userModel->findById($userId);
    }
}
