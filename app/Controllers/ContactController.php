<?php
namespace App\Controllers;
use Core\Database;
use Core\Request;
use Core\Response;

use App\Controllers\BaseController;

/**
 * Contact Form Controller
 */
class ContactController extends BaseController
{
    private Database $db;
    public function __construct(Database $db){
        parent::__construct();
        $this->db = $db;
    }

    /**
     * ارسال پیام تماس
     */
    public function send(Request $request, Response $response)
    {
        // Validation
        $errors = [];
        
        if (empty($this->request->input('name'))) {
            $errors['name'] = 'نام الزامی است.';
        }
        
        if (empty($this->request->input('email')) || !filter_var($this->request->input('email'), FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'ایمیل معتبر الزامی است.';
        }
        
        if (empty($this->request->input('subject'))) {
            $errors['subject'] = 'موضوع الزامی است.';
        }
        
        if (empty($this->request->input('message'))) {
            $errors['message'] = 'پیام الزامی است.';
        }
        
        if (!empty($errors)) {
            return $this->response->error('لطفاً تمام فیلدها را پر کنید.', $errors);
        }
        
        // ذخیره در دیتابیس یا ارسال ایمیل
        try {
            // ذخیره در جدول contact_messages (اگر وجود داشته باشد)
            $this->db->query(
                "INSERT INTO contact_messages (name, email, subject, message, ip_address, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $this->request->input('name'),
                    $this->request->input('email'),
                    $this->request->input('subject'),
                    $this->request->input('message'),
                    get_client_ip(),
                    now()
                ]
            );
            
            // ثبت لاگ
            logger('info', 'Contact form submitted', [
                'email' => $this->request->input('email'),
                'subject' => $this->request->input('subject')
            ]);
            
            return $this->response->success('پیام شما با موفقیت ارسال شد. به زودی پاسخ خواهیم داد.');
            
        } catch (\Exception $e) {
            logger('error', 'Contact form submission failed', [
                'error' => $e->getMessage()
            ]);
            
            return $this->response->error('خطا در ارسال پیام. لطفاً دوباره تلاش کنید.');
        }
    }
}