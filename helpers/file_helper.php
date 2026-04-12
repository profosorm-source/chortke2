<?php

if (!function_exists('upload_file')) {
    function upload_file($file, $directory = 'general')
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception('خطا در آپلود فایل');
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
        
        if (!in_array($file['type'], $allowedTypes)) {
            throw new \Exception('نوع فایل مجاز نیست');
        }

        $maxSize = 5 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            throw new \Exception('حجم فایل بیش از حد مجاز است');
        }

        $uploadDir = __DIR__ . '/../public/uploads/' . $directory;
        
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $extension;
        $filepath = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new \Exception('خطا در ذخیره فایل');
        }

        return 'uploads/' . $directory . '/' . $filename;
    }
}
