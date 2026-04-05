<?php

namespace App\Controllers\Admin;

use App\Models\Notification;
use App\Services\NotificationService;
use App\Controllers\Admin\BaseAdminController;

class NotificationController extends BaseAdminController
{
    private NotificationService $notificationService;
    private Notification $model;

    public function __construct(
        Notification $model,
        NotificationService $notificationService
    ) {
        parent::__construct();
        $this->model               = $model;
        $this->notificationService = $notificationService;
    }

    /**
     * صفحه مدیریت اعلان‌های ادمین
     */
    public function index(): void
    {
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 30;
        $userId  = user_id();

        $notifications = $this->notificationService->latest($userId, 50);

        view('admin/notifications/index', [
            'title'         => 'اعلان‌ها',
            'notifications' => $notifications,
        ]);
    }

    /**
     * صفحه ارسال اعلان دستی به کاربران
     * GET /admin/notifications/send
     */
    public function showSend(): void
    {
        view('admin/notifications/send', [
            'title' => 'ارسال اعلان به کاربران',
        ]);
    }

    /**
     * پردازش ارسال اعلان دستی
     * POST /admin/notifications/send
     */
    public function send(): void
    {
        $target  = trim((string)($_POST['target']  ?? 'all'));
        $type    = trim((string)($_POST['type']    ?? 'info'));
        $title   = trim((string)($_POST['title']   ?? ''));
        $message = trim((string)($_POST['message'] ?? ''));
        $userId  = (int)($_POST['user_id'] ?? 0);

        if ($title === '' || $message === '') {
            $this->session->setFlash('error', 'عنوان و متن اعلان الزامی است.');
            redirect('/admin/notifications/send');
            return;
        }

        $sent = 0;

        if ($target === 'all') {
            $result = $this->notificationService->sendToAll($title, $message, $type);
            $sent   = $result['sent'] ?? 0;
        } elseif ($target === 'user' && $userId > 0) {
            $notifId = $this->notificationService->send($userId, $type, $title, $message);
            $sent    = $notifId ? 1 : 0;
        } else {
            $this->session->setFlash('error', 'هدف ارسال نامعتبر است.');
            redirect('/admin/notifications/send');
            return;
        }

        log_activity('admin_notification_sent', "ارسال اعلان دستی ({$sent} کاربر)", user_id());
        $this->session->setFlash('success', "اعلان با موفقیت به {$sent} کاربر ارسال شد.");
        redirect('/admin/notifications');
    }

    /**
     * آمار اعلان‌ها
     * GET /admin/notifications/stats
     */
    public function stats(): void
    {
        view('admin/notifications/stats', [
            'title' => 'آمار اعلان‌ها',
        ]);
    }

    /**
     * Ajax — دریافت آخرین اعلان‌ها
     */
    public function fetch(): void
    {
        $items  = $this->notificationService->latest(user_id(), 10);
        $unread = $this->notificationService->getUnreadCount(user_id());

        $this->response->json([
            'success'       => true,
            'notifications' => $items,
            'unread_count'  => $unread,
        ]);
    }

    /**
     * Ajax — فقط تعداد خوانده‌نشده‌ها (برای badge navbar)
     * GET /admin/notifications/unread-count
     */
    public function unreadCount(): void
    {
        $count = $this->notificationService->getUnreadCount(user_id());
        $this->response->json([
            'success' => true,
            'count'   => $count,
        ]);
    }

    /**
     * علامت خواندن یک اعلان
     */
    public function markAsRead(int $id): void
    {
        $ok = $this->model->markAsRead($id, user_id());

        $this->response->json([
            'success' => $ok,
            'message' => $ok ? 'خوانده شد' : 'عملیات ناموفق بود',
        ], $ok ? 200 : 400);
    }

    /**
     * علامت خواندن همه اعلان‌ها
     */
    public function markAllAsRead(): void
    {
        $ok = $this->model->markAllAsRead(user_id());

        $this->response->json([
            'success' => $ok,
            'message' => $ok ? 'همه خوانده شدند' : 'عملیات ناموفق بود',
        ], $ok ? 200 : 400);
    }
}