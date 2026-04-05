<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationPreference;
use Core\Database;

class NotificationService
{
    private Database $db;
    private Notification $notificationModel;
    private NotificationPreference $prefModel;

    /**
     * BUG FIX 1 & 2:
     * - $this->prefModel هرگز تعریف نشده بود → Fatal Error در هر send()
     * - bootstrap با new NotificationService() صدا می‌زد (بدون dependency) → DI اشتباه بود
     * حالا هر دو dependency درست تزریق می‌شوند
     */
    public function __construct(
        Notification $notificationModel,
        NotificationPreference $prefModel,
        Database $db
    ) {
        $this->notificationModel = $notificationModel;
        $this->prefModel         = $prefModel;
        $this->db                = $db;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ارسال نوتیفیکیشن
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ارسال یک نوتیفیکیشن به یک کاربر
     *
     * @param int         $userId
     * @param string      $type       یکی از ثابت‌های Notification::TYPE_*
     * @param string      $title      عنوان
     * @param string      $message    متن
     * @param array|null  $data       داده اضافی (JSON)
     * @param string|null $actionUrl  لینک دکمه
     * @param string|null $actionText متن دکمه
     * @param string      $priority   low|normal|high|urgent
     * @param string|null $expiresAt  تاریخ انقضا (Y-m-d H:i:s)
     */
    public function send(
        int     $userId,
        string  $type,
        string  $title,
        string  $message,
        ?array  $data       = null,
        ?string $actionUrl  = null,
        ?string $actionText = null,
        string  $priority   = 'normal',
        ?string $expiresAt  = null
    ): ?int {
        // بررسی تنظیمات in-app کاربر
        try {
            if (!$this->prefModel->isInAppEnabled($userId, $type)) {
                return null;
            }
        } catch (\Throwable $e) {
            // اگر جدول preference وجود نداشت، notification را می‌فرستیم
            logger()->warning('NotificationPreference check failed: ' . $e->getMessage());
        }

        try {
            $notifId = $this->notificationModel->create([
                'user_id'     => $userId,
                'type'        => $type,
                'title'       => $title,
                'message'     => $message,
                'data'        => $data,
                'action_url'  => $actionUrl,
                'action_text' => $actionText,
                'priority'    => $priority,
                'expires_at'  => $expiresAt,
            ]);

            return $notifId ?: null;

        } catch (\Throwable $e) {
            logger()->error('NotificationService::send failed', [
                'user_id' => $userId,
                'type'    => $type,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ارسال گروهی
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * ارسال به همه کاربران فعال
     *
     * BUG FIX 3: قبلاً فقط به admin‌ها می‌فرستاد (WHERE role='admin')
     * حالا به همه کاربران active می‌فرستد
     *
     * @return array{sent:int, skipped:int}
     */
    public function sendToAll(
        string  $title,
        string  $message,
        string  $type       = Notification::TYPE_SYSTEM,
        ?string $actionUrl  = null,
        ?string $actionText = null,
        string  $priority   = 'normal',
        ?array  $data       = null
    ): array {
        $users = $this->db->query(
            "SELECT id FROM users
             WHERE deleted_at IS NULL
               AND status = 'active'"
        )->fetchAll(\PDO::FETCH_OBJ);

        $sent = $skipped = 0;
        foreach ($users as $u) {
            $ok = $this->send(
                (int)$u->id,
                $type,
                $title,
                $message,
                $data,
                $actionUrl,
                $actionText,
                $priority
            );
            $ok ? $sent++ : $skipped++;
        }

        logger()->info("sendToAll: sent={$sent} skipped={$skipped} type={$type}");
        return ['sent' => $sent, 'skipped' => $skipped];
    }

    /**
     * ارسال به گروهی از کاربران (با ID)
     */
    public function sendBulk(
        array   $userIds,
        string  $type,
        string  $title,
        string  $message,
        ?array  $data      = null,
        ?string $actionUrl = null,
        string  $priority  = 'normal'
    ): int {
        $sent = 0;
        foreach ($userIds as $userId) {
            if ($this->send((int)$userId, $type, $title, $message, $data, $actionUrl, null, $priority)) {
                $sent++;
            }
        }
        return $sent;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Query helpers
    // ─────────────────────────────────────────────────────────────────────────

    public function latest(int $userId, int $limit = 10): array
    {
        return $this->notificationModel->getLatestForUser($userId, $limit);
    }

    public function getUnreadCount(int $userId): int
    {
        return $this->notificationModel->countUnread($userId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // نوتیفیکیشن‌های از پیش‌ تعریف‌شده
    // ─────────────────────────────────────────────────────────────────────────

    /** واریز موفق */
    public function depositSuccess(int $userId, float $amount, string $currency): ?int
    {
        return $this->send(
            $userId,
            Notification::TYPE_DEPOSIT,
            'واریز موفق',
            'مبلغ ' . format_amount($amount) . ' ' . strtoupper($currency) . ' با موفقیت به کیف پول شما واریز شد.',
            ['amount' => $amount, 'currency' => $currency],
            url('/wallet'),
            'مشاهده کیف پول',
            'high'
        );
    }

    /** برداشت تأیید شد */
    public function withdrawalApproved(int $userId, float $amount, string $currency): ?int
    {
        return $this->send(
            $userId,
            Notification::TYPE_WITHDRAWAL,
            'برداشت تأیید شد',
            'درخواست برداشت شما به مبلغ ' . format_amount($amount) . ' ' . strtoupper($currency) . ' تأیید و پردازش شد.',
            ['amount' => $amount, 'currency' => $currency],
            url('/wallet/history'),
            'مشاهده تاریخچه',
            'high'
        );
    }

    /** برداشت رد شد */
    public function withdrawalRejected(int $userId, float $amount, string $reason): ?int
    {
        return $this->send(
            $userId,
            Notification::TYPE_WITHDRAWAL,
            'برداشت رد شد',
            "درخواست برداشت شما رد شد. دلیل: {$reason}. مبلغ به کیف پول شما بازگشت.",
            ['amount' => $amount, 'reason' => $reason],
            url('/wallet/history'),
            'مشاهده جزئیات',
            'high'
        );
    }

    /** تسک جدید موجود */
    public function newTaskAvailable(int $userId, string $taskTitle): ?int
    {
        return $this->send(
            $userId,
            Notification::TYPE_TASK,
            'تسک جدید',
            "تسک جدید «{$taskTitle}» برای شما در دسترس است.",
            ['task_title' => $taskTitle],
            url('/tasks'),
            'مشاهده تسک‌ها',
            'normal'
        );
    }

    /** KYC تأیید شد */
    public function kycVerified(int $userId): ?int
    {
        return $this->send(
            $userId,
            Notification::TYPE_KYC,
            'احراز هویت تأیید شد ✅',
            'احراز هویت شما با موفقیت تأیید شد. اکنون می‌توانید از تمام امکانات سایت استفاده کنید.',
            null,
            url('/dashboard'),
            'ورود به داشبورد',
            'high'
        );
    }

    /** KYC رد شد */
    public function kycRejected(int $userId, string $reason): ?int
    {
        return $this->send(
            $userId,
            Notification::TYPE_KYC,
            'احراز هویت رد شد ❌',
            "احراز هویت شما رد شد. دلیل: {$reason}. لطفاً مدارک را مجدداً ارسال کنید.",
            ['reason' => $reason],
            url('/kyc/upload'),
            'ارسال مجدد مدارک',
            'urgent'
        );
    }

    /** برنده قرعه‌کشی */
    public function lotteryWinner(int $userId, float $amount): ?int
    {
        return $this->send(
            $userId,
            Notification::TYPE_LOTTERY,
            '🎉 تبریک! برنده شدید!',
            'شما برنده قرعه‌کشی شدید! مبلغ ' . format_amount($amount) . ' به کیف پول شما واریز شد.',
            ['amount' => $amount],
            url('/wallet'),
            'مشاهده کیف پول',
            'urgent',
            date('Y-m-d H:i:s', strtotime('+7 days'))
        );
    }

    /** کمیسیون معرفی */
    public function referralEarning(int $userId, float $amount, string $referredUserName): ?int
    {
        return $this->send(
            $userId,
            Notification::TYPE_REFERRAL,
            'کمیسیون معرفی 💰',
            "از فعالیت «{$referredUserName}» مبلغ " . format_amount($amount) . ' کمیسیون دریافت کردید.',
            ['amount' => $amount, 'referred_user' => $referredUserName],
            url('/referral'),
            'مشاهده زیرمجموعه‌ها',
            'normal'
        );
    }

    /** هشدار امنیتی */
    public function securityAlert(int $userId, string $message, string $ip): ?int
    {
        return $this->send(
            $userId,
            Notification::TYPE_SECURITY,
            '⚠️ هشدار امنیتی',
            $message,
            ['ip' => $ip, 'time' => date('Y-m-d H:i:s')],
            url('/profile/security'),
            'بررسی حساب',
            'urgent'
        );
    }

    /** اتمام سرمایه‌گذاری */
    public function investmentCompleted(int $userId, float $profit, float $total): ?int
    {
        return $this->send(
            $userId,
            Notification::TYPE_INVESTMENT,
            'سرمایه‌گذاری تکمیل شد',
            'سرمایه‌گذاری شما به پایان رسید. سود: ' . format_amount($profit) . ' — مجموع: ' . format_amount($total),
            ['profit' => $profit, 'total' => $total],
            url('/investments'),
            'مشاهده سرمایه‌گذاری‌ها',
            'high'
        );
    }
}
