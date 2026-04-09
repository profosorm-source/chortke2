<?php

namespace App\Services;

use App\Models\ContentSubmission;
use App\Models\ContentRevenue;
use App\Models\ContentAgreement;
use App\Services\WalletService;
use App\Services\NotificationService;
use Core\Session;
use Core\Database;

class ContentService
{
    private WalletService $walletService;
    private NotificationService $notificationService;
    private ContentSubmission $submissionModel;
    private ContentRevenue $revenueModel;
    private ContentAgreement $agreementModel;
    private Database $db;

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
        ContentSubmission $submissionModel,
        ContentRevenue $revenueModel,
        ContentAgreement $agreementModel,
        Database $db
    ) {
        $this->submissionModel = $submissionModel;
        $this->revenueModel = $revenueModel;
        $this->agreementModel = $agreementModel;
        $this->walletService = $walletService;
        $this->notificationService = $notificationService;
        $this->db = $db;
    }

    /**
     * ارسال محتوای جدید
     */
    public function submitContent(int $userId, $data): array
    {
        // تبدیل object به array
        if (is_object($data)) {
            $data = (array)$data;
        }

        // بررسی آیا محتوای در انتظار دارد
        if ($this->submissionModel->hasPendingSubmission($userId)) {
            return [
                'success' => false,
                'message' => 'شما یک محتوای در انتظار بررسی دارید. لطفاً تا تعیین وضعیت آن صبر کنید.'
            ];
        }

        // بررسی پلتفرم
        if (!in_array($data['platform'], ContentSubmission::ALLOWED_PLATFORMS)) {
            return [
                'success' => false,
                'message' => 'پلتفرم انتخابی نامعتبر است.'
            ];
        }

        // بررسی URL
        $videoUrl = trim($data['video_url']);
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
        $submissionData = [
            'user_id'                => $userId,
            'platform'               => $data['platform'],
            'video_url'              => $videoUrl,
            'title'                  => $data['title'],
            'description'            => $data['description'] ?? null,
            'category'               => $data['category'] ?? null,
            'agreement_accepted'     => 1,
            'agreement_accepted_at'  => date('Y-m-d H:i:s'),
            'agreement_ip'           => $_SERVER['REMOTE_ADDR'] ?? null,
            'agreement_fingerprint'  => $session->get('fingerprint'),
        ];

        $submissionId = $this->submissionModel->create($submissionData);

        if (!$submissionId) {
            return ['success' => false, 'message' => 'خطا در ثبت محتوا.'];
        }

        // ثبت تعهدنامه
        $this->agreementModel->create([
            'user_id'        => $userId,
            'submission_id'  => $submissionId,
            'agreement_text' => self::AGREEMENT_TEXT,
            'accepted_at'    => date('Y-m-d H:i:s'),
            'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent'     => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);

        logger('content_submitted', "User {$userId} submitted content #{$submissionId}", 'info');

        return [
            'success' => true,
            'message' => 'محتوا با موفقیت ثبت شد و در انتظار بررسی است.',
            'submission_id' => $submissionId
        ];
    }

    /**
     * ✅ FIX: اضافه کردن Transaction به payRevenue
     * پرداخت درآمد به کیف پول کاربر
     */
    public function payRevenue(int $revenueId, int $adminId): array
    {
        try {
            $revenue = $this->revenueModel->findWithDetails($revenueId);
            if (!$revenue) {
                return ['success' => false, 'message' => 'رکورد درآمد یافت نشد.'];
            }

            if ($revenue->status !== ContentRevenue::STATUS_APPROVED) {
                return ['success' => false, 'message' => 'فقط درآمدهای تأیید شده قابل پرداخت هستند.'];
            }

            // ✅ START TRANSACTION
            $this->db->beginTransaction();

            try {
                // 1. واریز به کیف پول
                $currency = $revenue->currency === 'usdt' ? 'usdt' : 'irt';

                $depositResult = $this->walletService->deposit(
                    $revenue->user_id,
                    $revenue->net_user_amount,
                    $currency,
                    [
                        'type'          => 'content_revenue',
                        'revenue_id'    => $revenueId,
                        'submission_id' => $revenue->submission_id,
                        'period'        => $revenue->period,
                        'description'   => "درآمد محتوا - دوره {$revenue->period} - {$revenue->video_title}",
                    ]
                );

                if (!$depositResult['success']) {
                    throw new \Exception('خطا در واریز به کیف پول: ' . ($depositResult['message'] ?? ''));
                }

                // 2. بروزرسانی وضعیت
                $this->revenueModel->update($revenueId, [
                    'status' => ContentRevenue::STATUS_PAID,
                    'paid_at' => date('Y-m-d H:i:s'),
                    'transaction_id' => $depositResult['transaction_id'] ?? null,
                ]);

                // ✅ COMMIT TRANSACTION
                $this->db->commit();

                // 3. نوتیفیکیشن (بعد از commit)
                $amount = number_format($revenue->net_user_amount);
                $currencyLabel = $currency === 'usdt' ? 'تتر' : 'تومان';
                $this->sendNotification(
                    $revenue->user_id,
                    'درآمد محتوا واریز شد',
                    "مبلغ {$amount} {$currencyLabel} بابت درآمد دوره {$revenue->period} به کیف پول شما واریز شد.",
                    'content_payment'
                );

                logger('content_payment', "Admin {$adminId} paid revenue #{$revenueId} to user {$revenue->user_id}", 'info');

                return [
                    'success' => true,
                    'message' => 'درآمد با موفقیت به کیف پول کاربر واریز شد.',
                    'transaction_id' => $depositResult['transaction_id'] ?? null
                ];

            } catch (\Exception $e) {
                // ✅ ROLLBACK در صورت خطا
                $this->db->rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            logger()->error('content.payment.failed', [
                'revenue_id' => $revenueId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در پرداخت: ' . $e->getMessage()
            ];
        }
    }

    /**
     * اعتبارسنجی URL ویدیو
     */
    private function validateVideoUrl(string $url, string $platform): bool
    {
        $url = trim($url);

        if ($platform === ContentSubmission::PLATFORM_APARAT) {
            // Aparat: aparat.com/v/...
            return (bool)preg_match('#^https?://(www\.)?aparat\.com/v/[a-zA-Z0-9_-]+#i', $url);
        }

        if ($platform === ContentSubmission::PLATFORM_YOUTUBE) {
            // YouTube: youtube.com/watch?v=... یا youtu.be/...
            return (bool)preg_match('#^https?://(www\.)?(youtube\.com/watch\?v=|youtu\.be/)[a-zA-Z0-9_-]+#i', $url);
        }

        return false;
    }

    /**
     * ارسال نوتیفیکیشن
     */
    private function sendNotification(int $userId, string $title, string $message, string $type): void
    {
        try {
            $this->notificationService->send($userId, $title, $message, $type);
        } catch (\Exception $e) {
            logger()->warning('notification.failed', [
                'user_id' => $userId,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * دریافت متن تعهدنامه
     */
    public function getAgreementText(): string
    {
        return self::AGREEMENT_TEXT;
    }

    /**
     * دریافت تنظیمات
     */
    public function getSettings(): array
    {
        return [
            'site_share_percent' => (float)setting('content_site_share_percent', 40),
            'tax_percent' => (float)setting('content_tax_percent', 9),
            'min_months_for_revenue' => ContentSubmission::MIN_MONTHS_FOR_REVENUE,
        ];
    }

    // بقیه متدها مثل approveSubmission, rejectSubmission, ...
    // به همین شکل با Try-Catch و Transaction
}
