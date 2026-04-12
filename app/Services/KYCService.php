<?php

namespace App\Services;

use App\Services\NotificationService;
use App\Models\KYCVerification;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\UploadService;
use Core\Database;
use Core\Logger;

class KYCService
{
    private Database         $db;
    private KYCVerification  $kycModel;
    private User             $userModel;
    private UploadService    $uploadService;
    private AuditTrail       $audit;
    private ?NotificationService $notificationService;
    private Logger           $logger;

    public function __construct(
        KYCVerification      $kycModel,
        User                 $userModel,
        Database             $db,
        UploadService        $uploadService,
        AuditTrail           $audit,
        Logger               $logger,
        ?NotificationService $notificationService = null
    ) {
        $this->kycModel            = $kycModel;
        $this->userModel           = $userModel;
        $this->db                  = $db;
        $this->uploadService       = $uploadService;
        $this->audit               = $audit;
        $this->notificationService = $notificationService;
        $this->logger              = $logger->withChannel('kyc');
    }

    /**
     * بررسی اینکه کاربر می‌تواند KYC ثبت کند یا نه
     */
    public function canSubmitKYC(int $userId): array
    {
        $existingKYC = $this->kycModel->findByUserId($userId);

        if (!$existingKYC) return ['can' => true];

        if ($existingKYC->status === 'verified') {
            return ['can' => false, 'reason' => 'احراز هویت شما قبلاً تأیید شده است'];
        }

        if (in_array($existingKYC->status, ['pending', 'under_review'])) {
            return ['can' => false, 'reason' => 'درخواست قبلی شما در حال بررسی است'];
        }

        if ($existingKYC->status === 'rejected') {
            $daysSinceRejection = (time() - strtotime($existingKYC->reviewed_at)) / 86400;
            if ($daysSinceRejection < 7) {
                return ['can' => false, 'reason' => 'شما باید ' . ceil(7 - $daysSinceRejection) . ' روز دیگر صبر کنید'];
            }
        }

        return ['can' => true];
    }

    /**
     * آپلود و ذخیره یک تصویر KYC
     */
    private function uploadImage(array $file, int $userId): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'خطا در آپلود تصویر'];
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'])) {
            return ['success' => false, 'message' => 'فرمت تصویر نامعتبر است'];
        }

        $filename = 'kyc_' . $userId . '_' . time() . '.' . $ext;
        $path     = __DIR__ . '/../../storage/uploads/kyc/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $path)) {
            return ['success' => false, 'message' => 'ذخیره تصویر ناموفق بود'];
        }

        return ['success' => true, 'file' => $filename];
    }

    /**
     * فشرده‌سازی تصویر
     */
    private function compressImage(string $path, string $mimeType): void
    {
        $quality = 80;
        try {
            switch ($mimeType) {
                case 'image/jpeg':
                case 'image/jpg':
                    $image = @imagecreatefromjpeg($path);
                    if ($image) { imagejpeg($image, $path, $quality); imagedestroy($image); }
                    break;
                case 'image/png':
                    $image = @imagecreatefrompng($path);
                    if ($image) { imagepng($image, $path, 8); imagedestroy($image); }
                    break;
            }
        } catch (\Throwable) {}
    }

    /**
     * تشخیص Photoshop ساده
     */
    public function detectPhotoshop(string $imagePath): array
    {
        $suspicious = false;
        $reasons    = [];

        $exif = @exif_read_data($imagePath);
        if ($exif) {
            if (isset($exif['Software'])) {
                $software = strtolower($exif['Software']);
                if (strpos($software, 'photoshop') !== false || strpos($software, 'gimp') !== false) {
                    $suspicious = true;
                    $reasons[]  = 'تصویر با نرم‌افزار ویرایش ساخته شده';
                }
            }

            if (isset($exif['DateTime']) && isset($exif['DateTimeOriginal'])) {
                $diff = abs(strtotime($exif['DateTime']) - strtotime($exif['DateTimeOriginal']));
                if ($diff > 60) {
                    $suspicious = true;
                    $reasons[]  = 'اختلاف زمانی مشکوک بین ساخت و ویرایش';
                }
            }
        }

        if ($suspicious) {
            $this->logger->warning('kyc.image.suspicious', [
                'image_path' => basename($imagePath),
                'reasons' => $reasons,
                'software' => $exif['Software'] ?? null
            ]);
        }

        return ['suspicious' => $suspicious, 'reasons' => $reasons];
    }

    /**
     * ثبت KYC با یک فایل
     */
    public function submitKYC(int $userId, array $data, array $file): array
    {
        $this->logger->info('kyc.submit.started', [
            'user_id' => $userId,
            'has_national_code' => !empty($data['national_code']),
            'has_birth_date' => !empty($data['birth_date'])
        ]);
        
        $canSubmit = $this->canSubmitKYC($userId);
        if (!$canSubmit['can']) {
            $this->logger->warning('kyc.submit.blocked', [
                'user_id' => $userId,
                'reason' => $canSubmit['reason']
            ]);
            return ['success' => false, 'message' => $canSubmit['reason']];
        }

        if (empty($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $this->logger->error('kyc.submit.failed', [
                'user_id' => $userId,
                'reason' => 'no_file_uploaded',
                'file_error' => $file['error'] ?? 'unknown'
            ]);
            return ['success' => false, 'message' => 'لطفاً تصویر احراز هویت را آپلود کنید'];
        }

        try {
            $uploadResult = $this->uploadService->upload(
                $file,
                'kyc',
                ['image/jpeg', 'image/png'],
                5 * 1024 * 1024
            );
        } catch (\Exception $e) {
            $this->logger->error('kyc.upload.exception', [
                'user_id' => $userId,
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطا در آپلود تصویر'];
        }

        if (!$uploadResult['success']) {
            $this->logger->error('kyc.upload.failed', [
                'user_id' => $userId,
                'upload_message' => $uploadResult['message'] ?? 'unknown'
            ]);
            return $uploadResult;
        }

        $uploadPath     = $this->uploadService->getPath('kyc/' . $uploadResult['filename']);
        $photoshopCheck = $this->detectPhotoshop($uploadPath);

        try {
            $kycId = $this->kycModel->create([
                'user_id'            => $userId,
                'verification_image' => $uploadResult['filename'],
                'national_code'      => $data['national_code'] ?? null,
                'birth_date'         => $data['birth_date']    ?? null,
                'status'             => $photoshopCheck['suspicious'] ? 'under_review' : 'pending',
                'ip_address'         => get_client_ip(),
                'user_agent'         => get_user_agent(),
                'device_fingerprint' => generate_device_fingerprint(),
            ]);
        } catch (\Exception $e) {
            $this->uploadService->delete('kyc/' . $uploadResult['filename']);
            $this->logger->critical('kyc.create.exception', [
                'user_id' => $userId,
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطای سیستمی در ثبت درخواست'];
        }

        if (!$kycId) {
            $this->uploadService->delete('kyc/' . $uploadResult['filename']);
            $this->logger->error('kyc.create.failed', [
                'user_id' => $userId
            ]);
            return ['success' => false, 'message' => 'خطا در ثبت درخواست احراز هویت'];
        }

        $this->userModel->update($userId, ['kyc_status' => 'pending']);

        $this->audit->record(AuditTrail::USER_KYC_SUBMITTED, $userId, [
            'kyc_id'     => $kycId,
            'suspicious' => $photoshopCheck['suspicious'],
        ]);

        $this->logger->info('kyc.submit.success', [
            'user_id' => $userId,
            'kyc_id' => $kycId,
            'status' => $photoshopCheck['suspicious'] ? 'under_review' : 'pending',
            'suspicious' => $photoshopCheck['suspicious'],
            'suspicious_reasons' => $photoshopCheck['reasons'] ?? []
        ]);

        return [
            'success'    => true,
            'message'    => 'درخواست احراز هویت با موفقیت ثبت شد',
            'kyc_id'     => $kycId,
            'suspicious' => $photoshopCheck['suspicious'],
        ];
    }

    /**
     * تأیید KYC توسط ادمین
     */
    public function verifyKYC(int $kycId, int $adminId): array
    {
        $this->logger->info('kyc.verify.started', [
            'kyc_id' => $kycId,
            'admin_id' => $adminId
        ]);
        
        $kyc = $this->kycModel->find($kycId);
        if (!$kyc) {
            $this->logger->error('kyc.verify.not_found', [
                'kyc_id' => $kycId,
                'admin_id' => $adminId
            ]);
            return ['success' => false, 'message' => 'درخواست یافت نشد'];
        }

        if (!in_array($kyc->status, ['pending', 'under_review'], true)) {
            $this->logger->warning('kyc.verify.invalid_status', [
                'kyc_id' => $kycId,
                'admin_id' => $adminId,
                'current_status' => $kyc->status
            ]);
            return ['success' => false, 'message' => 'این درخواست قابل تأیید نیست'];
        }

        $this->db->beginTransaction();
        try {
            $ok = $this->kycModel->updateStatus($kycId, 'verified', null);
            if (!$ok) {
                $this->db->rollBack();
                $this->logger->error('kyc.verify.update_failed', [
                    'kyc_id' => $kycId,
                    'admin_id' => $adminId,
                    'user_id' => $kyc->user_id
                ]);
                return ['success' => false, 'message' => 'خطا در بروزرسانی وضعیت KYC'];
            }

            $ok2 = $this->userModel->update((int)$kyc->user_id, [
                'kyc_status'      => 'verified',
                'kyc_verified_at' => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

            if (!$ok2) {
                $this->db->rollBack();
                $this->logger->error('kyc.verify.user_update_failed', [
                    'kyc_id' => $kycId,
                    'admin_id' => $adminId,
                    'user_id' => $kyc->user_id
                ]);
                return ['success' => false, 'message' => 'خطا در بروزرسانی کاربر'];
            }

            $this->db->commit();

            $this->audit->record(AuditTrail::USER_KYC_APPROVED, (int)$kyc->user_id, [
                'kyc_id'   => $kycId,
                'admin_id' => $adminId,
            ], $adminId);

            $this->logger->info('kyc.verify.success', [
                'kyc_id' => $kycId,
                'admin_id' => $adminId,
                'user_id' => $kyc->user_id
            ]);

            if ($this->notificationService) {
                try {
                    $this->notificationService->kycVerified((int)$kyc->user_id);
                } catch (\Throwable $e) {
                    log_error_advanced(
                        'خطا در ارسال نوتیفیکیشن تأیید KYC',
                        'ERROR',
                        $e,
                        ['kyc_id' => $kycId, 'user_id' => $kyc->user_id]
                    );
                }
            }

            return ['success' => true, 'message' => 'احراز هویت با موفقیت تأیید شد'];
        } catch (\Exception $e) {
            $this->db->rollBack();
            log_error_advanced(
                'خطای سیستمی در تأیید KYC',
                'CRITICAL',
                $e,
                ['kyc_id' => $kycId, 'admin_id' => $adminId, 'user_id' => $kyc->user_id ?? null]
            );
            return ['success' => false, 'message' => 'خطای سیستمی در تأیید KYC'];
        }
    }

    /**
     * رد KYC توسط ادمین
     */
    public function rejectKYC(int $kycId, string $reason, int $adminId = 0): array
    {
        $this->logger->info('kyc.reject.started', [
            'kyc_id' => $kycId,
            'admin_id' => $adminId,
            'reason' => $reason
        ]);
        
        $kyc = $this->kycModel->find($kycId);
        if (!$kyc) {
            $this->logger->error('kyc.reject.not_found', [
                'kyc_id' => $kycId,
                'admin_id' => $adminId
            ]);
            return ['success' => false, 'message' => 'درخواست یافت نشد'];
        }

        if (!in_array($kyc->status, ['pending', 'under_review'], true)) {
            $this->logger->warning('kyc.reject.invalid_status', [
                'kyc_id' => $kycId,
                'admin_id' => $adminId,
                'current_status' => $kyc->status
            ]);
            return ['success' => false, 'message' => 'این درخواست قابل رد نیست'];
        }

        $this->db->beginTransaction();
        try {
            $ok = $this->kycModel->updateStatus($kycId, 'rejected', $reason);
            if (!$ok) {
                $this->db->rollBack();
                $this->logger->error('kyc.reject.update_failed', [
                    'kyc_id' => $kycId,
                    'admin_id' => $adminId,
                    'user_id' => $kyc->user_id
                ]);
                return ['success' => false, 'message' => 'خطا در بروزرسانی وضعیت KYC'];
            }

            $ok2 = $this->userModel->update((int)$kyc->user_id, [
                'kyc_status' => 'rejected',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            if (!$ok2) {
                $this->db->rollBack();
                $this->logger->error('kyc.reject.user_update_failed', [
                    'kyc_id' => $kycId,
                    'admin_id' => $adminId,
                    'user_id' => $kyc->user_id
                ]);
                return ['success' => false, 'message' => 'خطا در بروزرسانی کاربر'];
            }

            $this->db->commit();

            $this->audit->record(AuditTrail::USER_KYC_REJECTED, (int)$kyc->user_id, [
                'kyc_id'   => $kycId,
                'admin_id' => $adminId,
                'reason'   => $reason,
            ], $adminId);

            $this->logger->warning('kyc.reject.success', [
                'kyc_id' => $kycId,
                'admin_id' => $adminId,
                'user_id' => $kyc->user_id,
                'reason' => $reason
            ]);

            if ($this->notificationService) {
                try {
                    $this->notificationService->kycRejected((int)$kyc->user_id, $reason);
                } catch (\Throwable $e) {
                    log_error_advanced(
                        'خطا در ارسال نوتیفیکیشن رد KYC',
                        'ERROR',
                        $e,
                        ['kyc_id' => $kycId, 'user_id' => $kyc->user_id]
                    );
                }
            }

            return ['success' => true, 'message' => 'درخواست رد شد'];
        } catch (\Exception $e) {
            $this->db->rollBack();
            log_error_advanced(
                'خطای سیستمی در رد KYC',
                'CRITICAL',
                $e,
                ['kyc_id' => $kycId, 'admin_id' => $adminId, 'user_id' => $kyc->user_id ?? null]
            );
            return ['success' => false, 'message' => 'خطای سیستمی در رد KYC'];
        }
    }
}
