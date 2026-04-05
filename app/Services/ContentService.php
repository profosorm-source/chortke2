<?php
// app/Services/ContentService.php

namespace App\Services;

use App\Models\ContentSubmission;
use App\Models\ContentRevenue;
use App\Models\ContentAgreement;
use App\Services\WalletService;
use App\Services\NotificationService;
use Core\Session;

class ContentService
{
    private WalletService $walletService;
    private NotificationService $notificationService;

    private ContentSubmission $submissionModel;
    private ContentRevenue $revenueModel;
    private ContentAgreement $agreementModel;

    // متن تعهدنامه
    private const AGREEMENT_TEXT = <<<EOT
تعهدنامه همکاری محتوایی با مجموعه چرتکه

اینجانب با آگاهی کامل از شرایط زیر، محتوای خود را برای انتشار در کانال‌های مجموعه ارسال می‌نمایم:

۱. تمامی محتوای ارسالی متعلق به مجموعه چرتکه خواهد بود و حق انتشار، ویرایش و حذف آن با مجموعه است.
۲. حتی در صورت خروج، بن شدن یا عدم فعالیت در سایت، حق شکایت از مجموعه بابت محتوای منتشرشده را ندارم.
۳. حق حذف، گزارش یا شکایت از محتوای منتشرشده در یوتیوب، آپارات یا سایر شبکه‌ها را ندارم.
۴. درآمد حاصل از محتوا بر اساس نسبت تعیین‌شده بین من و مجموعه تقسیم خواهد شد.
۵. دو ماه اول پس از تأیید، هیچ سودی تعلق نمی‌گیرد.
۶. محتوای ارسالی باید اصل و متعلق به خودم باشد. در صورت کپی بودن، مسئولیت قانونی با اینجانب است.
۷. در صورت تخلف، مجموعه حق تعلیق یا مسدودسازی حساب و توقف پرداخت‌ها را دارد.

با تأیید این تعهدنامه، تمام شرایط فوق را می‌پذیرم.
EOT;

    public function __construct(
        WalletService $walletService,
        NotificationService $notificationService,
        \App\Models\ContentSubmission $submissionModel,
        \App\Models\ContentRevenue $revenueModel,
        \App\Models\ContentAgreement $agreementModel) {
        $this->submissionModel = $submissionModel;
        $this->revenueModel = $revenueModel;
        $this->agreementModel = $agreementModel;
        $this->walletService       = $walletService;
        $this->notificationService = $notificationService;
    }

    /**
     * ارسال محتوای جدید
     */
    public function submitContent(int $userId, array $data): array
    {
        // بررسی آیا محتوای در انتظار دارد
        if ($this->submissionModel->hasPendingSubmission($userId)) {
            return [
                'success' => false,
                'message' => 'شما یک محتوای در انتظار بررسی دارید. لطفاً تا تعیین وضعیت آن صبر کنید.'
            ];
        }

        // بررسی پلتفرم
        if (!\in_array($data['platform'], ContentSubmission::ALLOWED_PLATFORMS)) {
            return [
                'success' => false,
                'message' => 'پلتفرم انتخابی نامعتبر است.'
            ];
        }

        // بررسی URL
        $videoUrl = \trim($data['video_url']);
        if (!$this->validateVideoUrl($videoUrl, $data['platform'])) {
            return [
                'success' => false,
                'message' => 'لینک ویدیو نامعتبر است. لطفاً لینک صحیح از ' . $data['platform'] . ' وارد کنید.'
            ];
        }

        // بررسی URL تکراری
        if ($this->submissionModel->isUrlExists($videoUrl)) {
            return [
                'success' => false,
                'message' => 'این لینک ویدیو قبلاً ثبت شده است.'
            ];
        }

        // بررسی تعهدنامه
        if (empty($data['agreement_accepted'])) {
            return [
                'success' => false,
                'message' => 'لطفاً تعهدنامه همکاری را بخوانید و تأیید کنید.'
            ];
        }

        $session = Session::getInstance();

        // ایجاد محتوا
        $submissionId = $this->submissionModel->create([
            'user_id' => $userId,
            'platform' => $data['platform'],
            'video_url' => $videoUrl,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? null,
            'agreement_accepted' => 1,
            'agreement_accepted_at' => \date('Y-m-d H:i:s'),
            'agreement_ip' => get_client_ip(),
            'agreement_fingerprint' => generate_device_fingerprint(),
        ]);

        if (!$submissionId) {
            return [
                'success' => false,
                'message' => 'خطا در ثبت محتوا. لطفاً دوباره تلاش کنید.'
            ];
        }

        // ثبت تعهدنامه
        $this->agreementModel->create([
            'user_id' => $userId,
            'submission_id' => $submissionId,
            'agreement_text' => self::AGREEMENT_TEXT,
            'ip_address' => get_client_ip(),
            'user_agent' => get_user_agent(),
            'device_fingerprint' => generate_device_fingerprint(),
        ]);

        // لاگ فعالیت
        logger('content_submission', "User {$userId} submitted content #{$submissionId}", 'info');

        return [
            'success' => true,
            'message' => 'محتوای شما با موفقیت ثبت شد و در صف بررسی قرار گرفت.',
            'submission_id' => $submissionId
        ];
    }

    /**
     * بررسی اعتبار URL ویدیو
     */
    private function validateVideoUrl(string $url, string $platform): bool
    {
        if ($platform === ContentSubmission::PLATFORM_APARAT) {
            return (bool)\preg_match('/^https?:\/\/(www\.)?aparat\.com\/v\//i', $url);
        }

        if ($platform === ContentSubmission::PLATFORM_YOUTUBE) {
            return (bool)\preg_match(
                '/^https?:\/\/(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/)/i',
                $url
            );
        }

        return false;
    }

    /**
     * تأیید محتوا (ادمین)
     */
    public function approveSubmission(int $submissionId, int $adminId): array
    {
        $submission = $this->submissionModel->find($submissionId);
        if (!$submission) {
            return ['success' => false, 'message' => 'محتوا یافت نشد.'];
        }

        if ($submission->status !== ContentSubmission::STATUS_PENDING &&
            $submission->status !== ContentSubmission::STATUS_UNDER_REVIEW) {
            return ['success' => false, 'message' => 'وضعیت محتوا اجازه تأیید را نمی‌دهد.'];
        }

        $this->submissionModel->update($submissionId, [
            'status' => ContentSubmission::STATUS_APPROVED,
            'approved_at' => \date('Y-m-d H:i:s'),
        ]);

        // ارسال نوتیفیکیشن به کاربر
        $this->sendNotification(
            $submission->user_id,
            'محتوای شما تأیید شد',
            "محتوای «{$submission->title}» تأیید شد. پس از انتشار در کانال‌های مجموعه، درآمد شما محاسبه خواهد شد.",
            'content_approved'
        );

        logger('content_approval', "Admin {$adminId} approved content #{$submissionId}", 'info');

        return ['success' => true, 'message' => 'محتوا با موفقیت تأیید شد.'];
    }

    /**
     * رد محتوا (ادمین)
     */
    public function rejectSubmission(int $submissionId, int $adminId, string $reason): array
    {
        $submission = $this->submissionModel->find($submissionId);
        if (!$submission) {
            return ['success' => false, 'message' => 'محتوا یافت نشد.'];
        }

        if ($submission->status !== ContentSubmission::STATUS_PENDING &&
            $submission->status !== ContentSubmission::STATUS_UNDER_REVIEW) {
            return ['success' => false, 'message' => 'وضعیت محتوا اجازه رد را نمی‌دهد.'];
        }

        $this->submissionModel->update($submissionId, [
            'status' => ContentSubmission::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);

        // ارسال نوتیفیکیشن
        $this->sendNotification(
            $submission->user_id,
            'محتوای شما رد شد',
            "محتوای «{$submission->title}» رد شد.\nدلیل: {$reason}",
            'content_rejected'
        );

        logger('content_rejection', "Admin {$adminId} rejected content #{$submissionId}: {$reason}", 'info');

        return ['success' => true, 'message' => 'محتوا رد شد و دلیل به کاربر اطلاع داده شد.'];
    }

    /**
     * ثبت انتشار در کانال مجموعه (ادمین)
     */
    public function markAsPublished(int $submissionId, int $adminId, array $data): array
    {
        $submission = $this->submissionModel->find($submissionId);
        if (!$submission) {
            return ['success' => false, 'message' => 'محتوا یافت نشد.'];
        }

        if ($submission->status !== ContentSubmission::STATUS_APPROVED) {
            return ['success' => false, 'message' => 'فقط محتوای تأیید شده قابل انتشار است.'];
        }

        $this->submissionModel->update($submissionId, [
            'status' => ContentSubmission::STATUS_PUBLISHED,
            'published_url' => $data['published_url'] ?? null,
            'channel_name' => $data['channel_name'] ?? null,
            'published_at' => \date('Y-m-d H:i:s'),
        ]);

        // نوتیفیکیشن
        $this->sendNotification(
            $submission->user_id,
            'محتوای شما منتشر شد!',
            "محتوای «{$submission->title}» در کانال مجموعه منتشر شد. درآمد شما از ماه سوم محاسبه خواهد شد.",
            'content_published'
        );

        logger('content_published', "Admin {$adminId} published content #{$submissionId}", 'info');

        return ['success' => true, 'message' => 'محتوا به عنوان منتشرشده ثبت شد.'];
    }

    /**
     * ثبت درآمد ماهانه (ادمین)
     */
    public function addRevenue(int $submissionId, int $adminId, array $data): array
    {
        $submission = $this->submissionModel->findWithUser($submissionId);
        if (!$submission) {
            return ['success' => false, 'message' => 'محتوا یافت نشد.'];
        }

        if ($submission->status !== ContentSubmission::STATUS_PUBLISHED) {
            return ['success' => false, 'message' => 'فقط برای محتوای منتشرشده می‌توان درآمد ثبت کرد.'];
        }

        // بررسی ماه‌های فعالیت (حداقل 2 ماه)
        $activeMonths = $this->submissionModel->getActiveMonths($submission->user_id);
        if ($activeMonths < ContentSubmission::MIN_MONTHS_FOR_REVENUE) {
            $remaining = ContentSubmission::MIN_MONTHS_FOR_REVENUE - $activeMonths;
            return [
                'success' => false,
                'message' => "کاربر هنوز به حداقل زمان فعالیت نرسیده. {$remaining} ماه دیگر باقی مانده."
            ];
        }

        // بررسی تکراری نبودن دوره
        $period = $data['period']; // مثال: 1404-01
        if ($this->revenueModel->existsForPeriod($submissionId, $period)) {
            return ['success' => false, 'message' => "درآمد برای دوره {$period} قبلاً ثبت شده است."];
        }

        // محاسبه سهم‌ها
        $totalRevenue = (float)$data['total_revenue'];
        $views = (int)($data['views'] ?? 0);

        // درصدها از تنظیمات
        $siteSharePercent = (float)setting('content_site_share_percent', 40);
        $taxPercent = (float)setting('content_tax_percent', 9);

        // محاسبه سطح‌بندی کاربر (کاربران فعال‌تر سهم بیشتری دارند)
        $userSharePercent = $this->calculateUserSharePercent($submission->user_id, $siteSharePercent);

        $siteShareAmount = \round($totalRevenue * ($siteSharePercent / 100), 2);
        $userShareAmount = \round($totalRevenue * ($userSharePercent / 100), 2);
        $taxAmount = \round($userShareAmount * ($taxPercent / 100), 2);
        $netUserAmount = \round($userShareAmount - $taxAmount, 2);

        // تعیین ارز
        $currency = setting('currency_mode', 'irt') === 'usdt' ? 'usdt' : 'irt';

        $revenueId = $this->revenueModel->create([
            'submission_id' => $submissionId,
            'user_id' => $submission->user_id,
            'period' => $period,
            'views' => $views,
            'total_revenue' => $totalRevenue,
            'site_share_percent' => $siteSharePercent,
            'site_share_amount' => $siteShareAmount,
            'user_share_percent' => $userSharePercent,
            'user_share_amount' => $userShareAmount,
            'tax_percent' => $taxPercent,
            'tax_amount' => $taxAmount,
            'net_user_amount' => $netUserAmount,
            'currency' => $currency,
            'status' => ContentRevenue::STATUS_PENDING,
        ]);

        if (!$revenueId) {
            return ['success' => false, 'message' => 'خطا در ثبت درآمد.'];
        }

        // نوتیفیکیشن
        $this->sendNotification(
            $submission->user_id,
            'درآمد جدید ثبت شد',
            "درآمد دوره {$period} برای محتوای «{$submission->title}»: " .
            \number_format($netUserAmount) . ($currency === 'usdt' ? ' تتر' : ' تومان'),
            'content_revenue'
        );

        logger('content_revenue', "Admin {$adminId} added revenue #{$revenueId} for content #{$submissionId}", 'info');

        return [
            'success' => true,
            'message' => 'درآمد با موفقیت ثبت شد.',
            'revenue_id' => $revenueId
        ];
    }

    /**
     * محاسبه درصد سهم کاربر بر اساس سطح فعالیت
     */
    private function calculateUserSharePercent(int $userId, float $siteSharePercent): float
    {
        $activeMonths = $this->submissionModel->getActiveMonths($userId);
        $totalSubmissions = $this->submissionModel->countByUser($userId, ContentSubmission::STATUS_PUBLISHED);

        // کاربران فعال‌تر = درصد بالاتر
        $baseUserPercent = 100 - $siteSharePercent;

        if ($activeMonths >= 12 && $totalSubmissions >= 10) {
            // کاربر حرفه‌ای: +10% بونوس
            return \min($baseUserPercent + 10, 80);
        } elseif ($activeMonths >= 6 && $totalSubmissions >= 5) {
            // کاربر فعال: +5% بونوس
            return \min($baseUserPercent + 5, 75);
        }

        // کاربر عادی
        return $baseUserPercent;
    }

    /**
     * پرداخت درآمد به کیف پول کاربر (ادمین)
     */
    public function payRevenue(int $revenueId, int $adminId): array
    {
        $revenue = $this->revenueModel->findWithDetails($revenueId);
        if (!$revenue) {
            return ['success' => false, 'message' => 'رکورد درآمد یافت نشد.'];
        }

        if ($revenue->status !== ContentRevenue::STATUS_APPROVED) {
            return ['success' => false, 'message' => 'فقط درآمدهای تأیید شده قابل پرداخت هستند.'];
        }

        // پرداخت از طریق WalletService
        $currency = $revenue->currency === 'usdt' ? 'usdt' : 'irt';

        $depositResult = $this->walletService->deposit(
            $revenue->user_id,
            $revenue->net_user_amount,
            $currency,
            'content_revenue',
            [
                'revenue_id' => $revenueId,
                'submission_id' => $revenue->submission_id,
                'period' => $revenue->period,
                'description' => "درآمد محتوا - دوره {$revenue->period} - {$revenue->video_title}"
            ]
        );

        if (!$depositResult['success']) {
            return ['success' => false, 'message' => 'خطا در واریز به کیف پول: ' . ($depositResult['message'] ?? '')];
        }

        // بروزرسانی وضعیت
        $this->revenueModel->update($revenueId, [
            'status' => ContentRevenue::STATUS_PAID,
            'paid_at' => \date('Y-m-d H:i:s'),
            'transaction_id' => $depositResult['transaction_id'] ?? null,
        ]);

        // نوتیفیکیشن
        $amount = \number_format($revenue->net_user_amount);
        $currencyLabel = $currency === 'usdt' ? 'تتر' : 'تومان';
        $this->sendNotification(
            $revenue->user_id,
            'درآمد محتوا واریز شد',
            "مبلغ {$amount} {$currencyLabel} بابت درآمد دوره {$revenue->period} به کیف پول شما واریز شد.",
            'content_payment'
        );

        logger('content_payment', "Admin {$adminId} paid revenue #{$revenueId} = {$revenue->net_user_amount} {$currency}", 'info');

        return ['success' => true, 'message' => "مبلغ {$amount} {$currencyLabel} با موفقیت واریز شد."];
    }

    /**
     * تعلیق محتوا (ادمین)
     */
    public function suspendSubmission(int $submissionId, int $adminId, string $reason): array
    {
        $submission = $this->submissionModel->find($submissionId);
        if (!$submission) {
            return ['success' => false, 'message' => 'محتوا یافت نشد.'];
        }

        $this->submissionModel->update($submissionId, [
            'status' => ContentSubmission::STATUS_SUSPENDED,
            'rejection_reason' => $reason,
        ]);

        $this->sendNotification(
            $submission->user_id,
            'محتوای شما تعلیق شد',
            "محتوای «{$submission->title}» تعلیق شد.\nدلیل: {$reason}",
            'content_suspended'
        );

        logger('content_suspended', "Admin {$adminId} suspended content #{$submissionId}: {$reason}", 'info');

        return ['success' => true, 'message' => 'محتوا تعلیق شد.'];
    }

    /**
     * دریافت متن تعهدنامه
     */
    public function getAgreementText(): string
    {
        return self::AGREEMENT_TEXT;
    }

    /**
     * دریافت تنظیمات محتوا
     */
    public function getSettings(): array
    {
        return [
            'site_share_percent' => (float)setting('content_site_share_percent', 40),
            'tax_percent' => (float)setting('content_tax_percent', 9),
            'min_months' => ContentSubmission::MIN_MONTHS_FOR_REVENUE,
            'allowed_platforms' => ContentSubmission::ALLOWED_PLATFORMS,
        ];
    }

    /**
     * ارسال نوتیفیکیشن
     */
    private function sendNotification(int $userId, string $title, string $message, string $type): void
    {
        try {
            $this->notificationService->send($userId, $type, $title, $message);
        } catch (\Throwable $e) {
            logger('notification_error', "Failed to send notification: " . $e->getMessage(), 'error');
        }
    }
}