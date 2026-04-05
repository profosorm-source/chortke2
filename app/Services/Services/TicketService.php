<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketMessage;

class TicketService
{
    private \Core\Database $db;
    private Ticket $ticketModel;
    private TicketMessage $messageModel;
    
    public function __construct(
        \App\Models\Ticket $ticketModel,
        \App\Models\TicketMessage $messageModel,
        \Core\Database $db)
    {
        $this->ticketModel = $ticketModel;
        $this->messageModel = $messageModel;
        $this->db = $db;
    }
    
    /**
     * ایجاد تیکت جدید
     */
    public function create(int $userId, array $data): array
    {
        $this->db->beginTransaction();
        
        try {
            // ایجاد تیکت
            $ticketId = $this->ticketModel->create([
                'user_id' => $userId,
                'category_id' => $data['category_id'],
                'subject' => $data['subject'],
                'priority' => $data['priority'] ?? 'normal'
            ]);
            
            if (!$ticketId) {
                throw new \Exception('خطا در ایجاد تیکت');
            }
            
            // ایجاد پیام اول
            $this->messageModel->create([
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'message' => $data['message'],
                'attachments' => $data['attachments'] ?? [],
                'is_admin' => false
            ]);
            
            // لاگ
            log_activity($userId, 'ticket_created', "تیکت جدید ایجاد شد: {$data['subject']}", [
                'ticket_id' => $ticketId
            ]);
            
            // نوتیفیکیشن به ادمین
            notify_admin("تیکت جدید ثبت شد: {$data['subject']}", "/admin/tickets/show/{$ticketId}");
            
            $this->db->commit();
            
            return [
                'success' => true,
                'ticket_id' => $ticketId,
                'message' => 'تیکت شما با موفقیت ثبت شد.'
            ];
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            
            return [
                'success' => false,
                'message' => 'خطا در ایجاد تیکت: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * ارسال پاسخ
     */
    public function reply(int $ticketId, int $userId, string $message, bool $isAdmin = false, array $attachments = []): array
    {
        $ticket = $this->ticketModel->findById($ticketId);
        
        if (!$ticket) {
            return ['success' => false, 'message' => 'تیکت یافت نشد.'];
        }
        
        // بررسی دسترسی
        if (!$isAdmin && $ticket->user_id != $userId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        
        // بررسی وضعیت
        if ($ticket->status === 'closed' && !$isAdmin) {
            return ['success' => false, 'message' => 'تیکت بسته شده است.'];
        }
        
        $this->db->beginTransaction();
        
        try {
            // ایجاد پیام
            $this->messageModel->create([
                'ticket_id' => $ticketId,
                'user_id' => $userId,
                'message' => $message,
                'attachments' => $attachments,
                'is_admin' => $isAdmin
            ]);
            
            // بروزرسانی تیکت
            $this->ticketModel->updateLastReply($ticketId, $isAdmin ? 'admin' : 'user');
            
            // نوتیفیکیشن
            if ($isAdmin) {
                notify($ticket->user_id, 'info', "پاسخ جدید برای تیکت: {$ticket->subject}", "/tickets/show/{$ticketId}");
            } else {
                notify_admin("پاسخ جدید از کاربر در تیکت #{$ticketId}", "/admin/tickets/show/{$ticketId}");
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'پاسخ شما ارسال شد.'
            ];
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            
            return [
                'success' => false,
                'message' => 'خطا: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * بستن تیکت
     */
    public function close(int $ticketId, int $userId, bool $isAdmin = false): array
    {
        $ticket = $this->ticketModel->findById($ticketId);
        
        if (!$ticket) {
            return ['success' => false, 'message' => 'تیکت یافت نشد.'];
        }
        
        // بررسی دسترسی
        if (!$isAdmin && $ticket->user_id != $userId) {
            return ['success' => false, 'message' => 'دسترسی غیرمجاز.'];
        }
        
        if ($this->ticketModel->updateStatus($ticketId, 'closed')) {
            log_activity($userId, 'ticket_closed', "تیکت #{$ticketId} بسته شد");
            
            return [
                'success' => true,
                'message' => 'تیکت بسته شد.'
            ];
        }
        
        return ['success' => false, 'message' => 'خطا در بستن تیکت.'];
    }
}