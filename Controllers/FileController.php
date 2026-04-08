<?php

namespace App\Controllers;

use App\Services\UploadService;
use App\Controllers\BaseController;
use Core\Database;

/**
 * FileController — نمایش امن فایل‌های خصوصی
 *
 * مسیر: app/Controllers/FileController.php
 * روت:  GET /file/view/{folder}/{filename}
 *
 * ─── جدول دسترسی ─────────────────────────────────────────────────────────
 *
 *  پوشه               | عمومی | کاربر              | ادمین
 *  ──────────────────────────────────────────────────────────
 *  avatars/banners     |  ✓   | —                  | ✓
 *  captcha             |  ✓   | —                  | ✓
 *  kyc                 |  ✗   | فقط صاحب KYC       | ✓
 *  receipts            |  ✗   | فقط صاحب deposit   | ✓
 *  task-proofs         |  ✗   | executor + advertiser | ✓
 *  task-samples        |  ✗   | creator + submitter | ✓
 *  ad-tasks            |  ✗   | advertiser + executor | ✓
 *  dispute-evidence    |  ✗   | executor + advertiser | ✓
 *  story-proofs        |  ✗   | customer + influencer | ✓
 *  story-media         |  ✗   | customer + influencer | ✓
 *  influencer-profiles |  ✗   | خود کاربر           | ✓
 *  ticket-attachments  |  ✗   | صاحب تیکت           | ✓
 *
 * ─── امنیت ───────────────────────────────────────────────────────────────
 *  • Path traversal: folder و filename با regex سختگیر sanitize می‌شوند
 *  • realpath() پس از ساخت مسیر — فایل نمی‌تواند از storage root فرار کند
 *  • MIME فایل با mime_content_type() از دیسک خوانده می‌شود (نه URL)
 *  • فقط image/* serve می‌شود — هیچ فایل دیگری نمایش داده نمی‌شود
 *  • X-Content-Type-Options: nosniff
 *  • Content-Disposition: inline (نه attachment)
 *  • لاگ دسترسی به فایل‌های حساس (kyc, receipts, dispute-evidence)
 *  • لاگ تلاش‌های رد‌شده (403) برای کاربران لاگین‌کرده
 * ──────────────────────────────────────────────────────────────────────────
 */
class FileController extends BaseController
{
    private Database $db;
    private UploadService $uploadService;

    /** پوشه‌هایی که بدون احراز هویت قابل دسترسی هستند */
    private const PUBLIC_FOLDERS = ['avatars', 'banners', 'captcha'];

    /** پوشه‌های حساس که دسترسی باید لاگ شود */
    private const SENSITIVE_FOLDERS = ['kyc', 'receipts', 'dispute-evidence'];

    /** MIME های مجاز برای serve کردن */
    private const ALLOWED_SERVE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /** پسوندهای مجاز در نام فایل (همان الگوی UploadService) */
    private const FILENAME_PATTERN = '/^(captcha_[a-f0-9]{16}|[a-f0-9]{24})\.(jpg|png|webp|gif)$/i';

    public function __construct(
        Database $db,
        \App\Services\UploadService $uploadService)
    {
        parent::__construct();
        $this->db = $db;
        $this->uploadService = $uploadService;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  ENTRY POINT
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * GET /file/view/{folder}/{filename}
     */
    public function serve(): void
    {
        // 1. خواندن پارامترها
        $folder   = (string)($this->request->param('folder')   ?? '');
        $filename = (string)($this->request->param('filename') ?? '');

        // 2. Sanitize — path traversal protection
        $folder   = $this->sanitizeFolder($folder);
        $filename = $this->sanitizeFilename($filename);

        if ($folder === null) {
            $this->deny('پوشه نامعتبر است');
            return;
        }
        if ($filename === null) {
            $this->deny('نام فایل نامعتبر است');
            return;
        }

        // 3. بررسی دسترسی
        $access = $this->checkAccess($folder, $filename);
        if (!$access['allowed']) {
            $this->deny($access['reason'], $folder, $filename);
            return;
        }

        // 4. مسیر فیزیکی + realpath check
        $realPath = $this->uploadService->getPath($folder . '/' . $filename);
        if ($realPath === null || !file_exists($realPath) || !is_file($realPath)) {
            http_response_code(404);
            echo 'فایل یافت نشد';
            exit;
        }

        // 5. MIME واقعی فایل (از دیسک، نه URL)
        $realMime = @mime_content_type($realPath);
        if (!$realMime || !in_array($realMime, self::ALLOWED_SERVE_MIMES, true)) {
            $this->deny('نوع فایل مجاز به نمایش نیست');
            return;
        }

        // 6. لاگ دسترسی به فایل‌های حساس
        if (in_array($folder, self::SENSITIVE_FOLDERS, true)) {
            $this->logAccess($folder, $filename, 'view');
        }

        // 7. ارسال فایل
        $this->serveFile($realPath, $realMime, $folder, $filename);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  ACCESS CONTROL
    // ═══════════════════════════════════════════════════════════════════════

    private function checkAccess(string $folder, string $filename): array
    {
        // ── ادمین: دسترسی کامل ──────────────────────────────────────────────
        if ($this->isAdmin()) {
            return $this->allow();
        }

        // ── عمومی ────────────────────────────────────────────────────────────
        if (in_array($folder, self::PUBLIC_FOLDERS, true)) {
            return $this->allow();
        }

        // ── از اینجا login الزامی است ────────────────────────────────────────
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            return $this->deny_result('برای مشاهده این فایل باید وارد سیستم شوید');
        }

        // ── per-folder ───────────────────────────────────────────────────────
        return match ($folder) {
            'kyc'                 => $this->accessKyc($filename, $userId),
            'receipts'            => $this->accessReceipt($filename, $userId),
            'task-proofs'         => $this->accessTaskProof($filename, $userId),
            'task-samples'        => $this->accessTaskSample($filename, $userId),
            'ad-tasks'            => $this->accessAdTaskSample($filename, $userId),
            'dispute-evidence'    => $this->accessDisputeEvidence($filename, $userId),
            'story-proofs'        => $this->accessStoryProof($filename, $userId),
            'story-media'         => $this->accessStoryMedia($filename, $userId),
            'influencer-profiles' => $this->accessInfluencerProfile($filename, $userId),
            'ticket-attachments'  => $this->accessTicketAttachment($filename, $userId),
            default               => $this->deny_result('پوشه ناشناخته است'),
        };
    }

    // ── per-folder checkers ─────────────────────────────────────────────────

    /**
     * KYC: فقط کاربری که آن مدرک را آپلود کرده
     */
    private function accessKyc(string $filename, int $userId): array
    {
        $row = $this->db->query(
            "SELECT user_id FROM kyc_verifications
             WHERE verification_image = ?
             ORDER BY id DESC LIMIT 1",
            [$filename]
        )->fetch();

        if (!$row) {
            return $this->deny_result('فایل یافت نشد');
        }
        return (int)$row->user_id === $userId
            ? $this->allow()
            : $this->deny_result('این مدرک متعلق به شما نیست');
    }

    /**
     * رسید واریز دستی: فقط صاحب درخواست واریز
     */
    private function accessReceipt(string $filename, int $userId): array
    {
        $row = $this->db->query(
            "SELECT user_id FROM manual_deposits
             WHERE receipt_image = ?
             ORDER BY id DESC LIMIT 1",
            [$filename]
        )->fetch();

        if (!$row) {
            return $this->deny_result('فایل یافت نشد');
        }
        return (int)$row->user_id === $userId
            ? $this->allow()
            : $this->deny_result('این رسید متعلق به شما نیست');
    }

    /**
     * مدرک تسک: executor (انجام‌دهنده) + advertiser (تبلیغ‌دهنده)
     * هر دو طرف باید بتوانند مدرک را ببینند
     */
    private function accessTaskProof(string $filename, int $userId): array
    {
        $row = $this->db->query(
            "SELECT te.executor_id, a.advertiser_id
             FROM task_executions te
             JOIN advertisements a ON a.id = te.advertisement_id
             WHERE te.proof_image = ?
             ORDER BY te.id DESC LIMIT 1",
            [$filename]
        )->fetch();

        if (!$row) {
            return $this->deny_result('فایل یافت نشد');
        }
        return ((int)$row->executor_id === $userId || (int)$row->advertiser_id === $userId)
            ? $this->allow()
            : $this->deny_result('دسترسی غیرمجاز');
    }

    /**
     * تصویر نمونه تسک سفارشی: سازنده تسک + کاربری که submission داده
     */
    private function accessTaskSample(string $filename, int $userId): array
    {
        $row = $this->db->query(
            "SELECT ct.creator_id
             FROM custom_tasks ct
             WHERE ct.sample_image = ?
             LIMIT 1",
            [$filename]
        )->fetch();

        if (!$row) {
            return $this->deny_result('فایل یافت نشد');
        }

        // سازنده تسک
        if ((int)$row->creator_id === $userId) {
            return $this->allow();
        }

        // کاربری که submission فعال دارد
        $sub = $this->db->query(
            "SELECT cts.id
             FROM custom_task_submissions cts
             JOIN custom_tasks ct ON ct.id = cts.task_id
             WHERE ct.sample_image = ? AND cts.user_id = ?
             LIMIT 1",
            [$filename, $userId]
        )->fetch();

        return $sub
            ? $this->allow()
            : $this->deny_result('دسترسی غیرمجاز');
    }

    /**
     * تصویر نمونه تبلیغ (ad-tasks): تبلیغ‌دهنده + executor هایی که آن تبلیغ را شروع کرده‌اند
     */
    private function accessAdTaskSample(string $filename, int $userId): array
    {
        $row = $this->db->query(
            "SELECT advertiser_id FROM advertisements
             WHERE sample_image = ?
             LIMIT 1",
            [$filename]
        )->fetch();

        if (!$row) {
            return $this->deny_result('فایل یافت نشد');
        }

        if ((int)$row->advertiser_id === $userId) {
            return $this->allow();
        }

        // executor هایی که تسک این تبلیغ را گرفته‌اند
        $exec = $this->db->query(
            "SELECT te.id
             FROM task_executions te
             JOIN advertisements a ON a.id = te.advertisement_id
             WHERE a.sample_image = ? AND te.executor_id = ?
             LIMIT 1",
            [$filename, $userId]
        )->fetch();

        return $exec
            ? $this->allow()
            : $this->deny_result('دسترسی غیرمجاز');
    }

    /**
     * مدرک اعتراض: executor + advertiser
     */
    private function accessDisputeEvidence(string $filename, int $userId): array
    {
        $row = $this->db->query(
            "SELECT te.executor_id, a.advertiser_id
             FROM task_executions te
             JOIN advertisements a ON a.id = te.advertisement_id
             WHERE te.dispute_evidence = ?
             ORDER BY te.id DESC LIMIT 1",
            [$filename]
        )->fetch();

        if (!$row) {
            return $this->deny_result('فایل یافت نشد');
        }
        return ((int)$row->executor_id === $userId || (int)$row->advertiser_id === $userId)
            ? $this->allow()
            : $this->deny_result('دسترسی غیرمجاز');
    }

    /**
     * اسکرین‌شات اثبات انتشار استوری: customer + influencer
     */
    private function accessStoryProof(string $filename, int $userId): array
    {
        $row = $this->db->query(
            "SELECT customer_id, influencer_user_id
             FROM story_orders
             WHERE proof_screenshot = ?
             ORDER BY id DESC LIMIT 1",
            [$filename]
        )->fetch();

        if (!$row) {
            return $this->deny_result('فایل یافت نشد');
        }
        return ((int)$row->customer_id === $userId || (int)$row->influencer_user_id === $userId)
            ? $this->allow()
            : $this->deny_result('دسترسی غیرمجاز');
    }

    /**
     * مدیای ارسالی برای سفارش استوری: customer + influencer
     */
    private function accessStoryMedia(string $filename, int $userId): array
    {
        $row = $this->db->query(
            "SELECT customer_id, influencer_user_id
             FROM story_orders
             WHERE media_path LIKE ?
             ORDER BY id DESC LIMIT 1",
            ['%' . $filename]
        )->fetch();

        if (!$row) {
            return $this->deny_result('فایل یافت نشد');
        }
        return ((int)$row->customer_id === $userId || (int)$row->influencer_user_id === $userId)
            ? $this->allow()
            : $this->deny_result('دسترسی غیرمجاز');
    }

    /**
     * تصویر پروفایل اینفلوئنسر: فقط خود کاربر
     */
    private function accessInfluencerProfile(string $filename, int $userId): array
    {
        $row = $this->db->query(
            "SELECT user_id FROM influencer_profiles
             WHERE profile_image = ?
             LIMIT 1",
            [$filename]
        )->fetch();

        if (!$row) {
            return $this->deny_result('فایل یافت نشد');
        }
        return (int)$row->user_id === $userId
            ? $this->allow()
            : $this->deny_result('این فایل متعلق به شما نیست');
    }

    /**
     * پیوست تیکت: کاربری که صاحب تیکت است
     * (پیام‌های ادمین هم به تیکت همان کاربر تعلق دارند)
     */
    private function accessTicketAttachment(string $filename, int $userId): array
    {
        $row = $this->db->query(
            "SELECT t.user_id
             FROM ticket_messages tm
             JOIN tickets t ON t.id = tm.ticket_id
             WHERE tm.attachments LIKE ?
             ORDER BY tm.id DESC LIMIT 1",
            ['%' . $filename . '%']
        )->fetch();

        if (!$row) {
            return $this->deny_result('فایل یافت نشد');
        }
        return (int)$row->user_id === $userId
            ? $this->allow()
            : $this->deny_result('دسترسی غیرمجاز');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  SERVE + SANITIZE + HELPERS
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * ارسال فایل با هدرهای امنیتی
     */
    private function serveFile(
        string $realPath,
        string $mime,
        string $folder,
        string $filename
    ): void {
        // پاک کردن output buffer
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $filesize = @filesize($realPath);

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . ($filesize !== false ? (string)$filesize : '0'));
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');

        if ($folder === 'captcha') {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        } else {
            header('Cache-Control: private, max-age=3600');
        }

        readfile($realPath);
        exit;
    }

    /**
     * پاکسازی نام پوشه از URL
     * مجاز: [a-z0-9_-] بدون .. و /
     */
    private function sanitizeFolder(string $folder): ?string
    {
        $folder = trim($folder, "/\\ \t\n\r\0\x0B");
        if ($folder === '' || str_contains($folder, '..')) {
            return null;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $folder)) {
            return null;
        }
        return strtolower($folder);
    }

    /**
     * پاکسازی نام فایل از URL
     * فقط الگوی دقیق: 24hex.ext (مثل a1b2c3d4e5f6a1b2c3d4e5f6.jpg)
     */
    private function sanitizeFilename(string $filename): ?string
    {
        $filename = basename($filename); // strip هر path component
        if (!preg_match(self::FILENAME_PATTERN, $filename)) {
            return null;
        }
        return strtolower($filename);
    }

    /** آیا کاربر جاری ادمین است؟ */
    private function isAdmin(): bool
    {
        return (bool)($this->session->get('is_admin') ?? false);
    }

    /** user_id کاربر لاگین‌کرده */
    private function getCurrentUserId(): ?int
    {
        $id = $this->session->get('user_id');
        return $id ? (int)$id : null;
    }

    /** ساخت پاسخ مثبت */
    private function allow(): array
    {
        return ['allowed' => true, 'reason' => ''];
    }

    /** ساخت پاسخ منفی */
    private function deny_result(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }

    /**
     * ارسال 403 + لاگ اگر کاربر لاگین‌کرده بود
     */
    private function deny(
        string $reason   = 'دسترسی غیرمجاز',
        string $folder   = 'unknown',
        string $filename = 'unknown'
    ): void {
        $userId = $this->getCurrentUserId();

        if ($userId) {
            // تلاش دسترسی رد‌شده را لاگ می‌کنیم
            try {
                $this->db->query(
                    "INSERT INTO file_logs
                     (folder, filename, viewer_id, action, ip_address, created_at)
                     VALUES (?, ?, ?, 'denied', ?, NOW())",
                    [$folder, $filename, $userId, $_SERVER['REMOTE_ADDR'] ?? '']
                );
            } catch (\Throwable) {
                // silent
            }
        }

        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo $reason;
        exit;
    }

    /**
     * لاگ مشاهده موفق فایل‌های حساس
     */
    private function logAccess(string $folder, string $filename, string $action): void
    {
        $userId = $this->getCurrentUserId() ?? ($this->isAdmin() ? 0 : null);
        if ($userId === null) {
            return;
        }

        try {
            $this->db->query(
                "INSERT INTO file_logs
                 (folder, filename, viewer_id, action, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [$folder, $filename, $userId, $action, $_SERVER['REMOTE_ADDR'] ?? '']
            );
        } catch (\Throwable) {
            // silent
        }
    }
}