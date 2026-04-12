<?php

namespace App\Controllers\User;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Controllers\User\BaseUserController;

class NotificationController extends BaseUserController
{
    private \App\Models\NotificationPreference $notificationPreferenceModel;
    private \App\Models\Notification $notificationModel;
    public function __construct(
        \App\Models\Notification $notificationModel,
        \App\Models\NotificationPreference $notificationPreferenceModel)
    {
        parent::__construct();
        $this->notificationModel = $notificationModel;
        $this->notificationPreferenceModel = $notificationPreferenceModel;
    }

    /**
     * لیست نوتیفیکیشن‌ها
     */
    public function index()
    {
        $userId = user_id();
        
        $notifications = ($this->notificationModel)->getUserNotifications($userId, false, 50);
        $unreadCount = ($this->notificationModel)->getUnreadCount($userId);
        
        return view('user/notifications/index', [
            'notifications' => $notifications,
            'unread_count' => $unreadCount
        ]);
    }
    
    /**
     * دریافت نوتیفیکیشن‌ها (Ajax)
     */
    public function get()
    {
                        
        $userId = user_id();
        $onlyUnread = $this->request->input('unread') === 'true';
        $limit = (int)($this->request->input('limit') ?? 20);
        
        $notifications = ($this->notificationModel)->getUserNotifications($userId, $onlyUnread, $limit);
        $unreadCount = ($this->notificationModel)->getUnreadCount($userId);
        
        return $this->response->json([
            'success' => true,
            'notifications' => $notifications,
            'unread_count' => $unreadCount
        ]);
    }
    
    /**
     * علامت‌گذاری به عنوان خوانده شده
     */
    public function markAsRead()
    {
                        
        $notificationId = (int)$this->request->input('notification_id');
        $userId = user_id();
        
        $result = ($this->notificationModel)->markAsRead($notificationId, $userId);
        
        return $this->response->json([
            'success' => $result,
            'message' => $result ? 'علامت‌گذاری شد' : 'خطا در علامت‌گذاری'
        ]);
    }
    
    /**
     * علامت‌گذاری همه به عنوان خوانده شده
     */
    public function markAllAsRead()
    {
        $userId = user_id();

        // BUG FIX 8: markAllAsRead حالا bool برمی‌گرداند (نه rowCount)
        // برای تعداد از markAllAsReadCount استفاده می‌کنیم
        $ok    = ($this->notificationModel)->markAllAsRead($userId);
        $count = ($this->notificationModel)->markAllAsReadCount($userId);

        return $this->response->json([
            'success' => $ok,
            'count'   => $count,
            'message' => $ok ? "{$count} نوتیفیکیشن خوانده شد" : 'خطا در علامت‌گذاری',
        ]);
    }
    
    /**
     * آرشیو کردن
     */
    public function archive()
    {
                        
        $notificationId = (int)$this->request->input('notification_id');
        $userId = user_id();
        
        $result = ($this->notificationModel)->archive($notificationId, $userId);
        
        return $this->response->json([
            'success' => $result,
            'message' => $result ? 'آرشیو شد' : 'خطا در آرشیو'
        ]);
    }
    
    /**
     * تنظیمات نوتیفیکیشن
     */
    public function preferences()
    {
        $userId = user_id();
        $prefs = ($this->notificationPreferenceModel)->getOrCreate($userId);
        
        return view('user/notifications/preferences', [
            'preferences' => $prefs
        ]);
    }
    
    /**
     * ذخیره تنظیمات
     */
    public function updatePreferences()
    {
                        
        $userId = user_id();
        $data = $this->request->all();
        
        $prefModel = $this->notificationPreferenceModel;
        $prefs = $prefModel->getOrCreate($userId);
        
        // فیلتر کردن فیلدهای مجاز
        $allowedFields = [
            'in_app_enabled', 'in_app_task', 'in_app_payment', 'in_app_withdrawal',
            'in_app_investment', 'in_app_lottery', 'in_app_referral', 'in_app_system',
            'email_enabled', 'email_task', 'email_payment', 'email_withdrawal',
            'email_investment', 'email_lottery', 'email_referral', 'email_system', 'email_marketing'
        ];
        
        $updateData = [];
        foreach ($allowedFields as $field) {
            $updateData[$field] = isset($data[$field]) ? 1 : 0;
        }
        
        $result = $prefModel->update($prefs->id, $updateData);
        
        return $this->response->json([
            'success' => $result,
            'message' => $result ? 'تنظیمات ذخیره شد' : 'خطا در ذخیره تنظیمات'
        ]);
    }
    
    /**
     * دریافت تعداد خوانده نشده (برای Badge)
     */
    public function unreadCount()
    {
                $userId = user_id();
        
        $count = ($this->notificationModel)->getUnreadCount($userId);
        
        return $this->response->json([
            'success' => true,
            'count' => $count
        ]);
    }
}