<?php
namespace Core;

/**
 * Response Handler
 * 
 * مدیریت پاسخ‌های HTTP
 */
class Response
{
    private $statusCode = 200;
    private $headers = [];
    private $content = '';

    /**
     * تنظیم Status Code
     */
    public function setStatusCode(int $code): void
    {
        http_response_code($code);
    }
    /**
     * تنظیم Header
     */
     public function setHeader(string $name, string $value): void
    {
        header("{$name}: {$value}");
    }

    /**
     * تنظیم Content
     */
    public function setContent($content)
    {
        $this->content = $content;
        return $this;
    }

    /**
     * پاسخ JSON
     */
   public function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * ارسال پاسخ HTML
     */
    public function html(string $content, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: text/html; charset=utf-8');
        echo $content;
        exit;
    }
    /**
     * پاسخ موفق
     */
    public function success($message, $data = [], $statusCode = 200)
    {
        return $this->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $statusCode);
    }

    /**
     * پاسخ خطا
     */
    public function error($message, $errors = [], $statusCode = 400)
    {
        return $this->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors
        ], $statusCode);
    }

    /**
     * Redirect
     */
     public function redirect(string $url, int $statusCode = 302): void
    {
        session_write_close();
        http_response_code($statusCode);
        header("Location: {$url}");
        exit;
    }
	 /**
     * دانلود فایل
     */
    public function download(string $filePath, string $fileName): void
    {
        if (!file_exists($filePath)) {
            http_response_code(404);
            echo 'File not found';
            exit;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
    /**
     * برگشت به صفحه قبل
     */
    public function back()
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? url();
        return $this->redirect($referer);
    }

    /**
     * ارسال پاسخ
     */
    public function send()
    {
        // تنظیم Status Code
        http_response_code($this->statusCode);
        
        // ارسال Headers
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        
        // ارسال Content
        echo $this->content;
        
        exit;
    }

    /**
     * نمایش View
     */
    public function view($viewName, $data = [])
    {
        ob_start();
        view($viewName, $data);
        $this->content = ob_get_clean();
        
        echo $this->content;
        exit;
    }
	/**
     * تنظیم HTTP Status Code
     */
    public function status(int $code): self
    {
        http_response_code($code);
        return $this;
    }

    /**
     * ارسال Header
     */
    public function header(string $name, string $value): self
    {
        header("{$name}: {$value}");
        return $this;
    }
}