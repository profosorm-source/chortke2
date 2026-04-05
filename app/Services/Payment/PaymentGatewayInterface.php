<?php

namespace App\Services\Payment;

interface PaymentGatewayInterface
{
    /**
     * درخواست پرداخت
     * 
     * @param int $amount مبلغ (ریال)
     * @param string $description توضیحات
     * @param string $callback آدرس بازگشت
     * @return array ['success' => bool, 'authority' => string, 'url' => string, 'message' => string]
     */
    public function request(int $amount, string $description, string $callback): array;

    /**
     * تأیید پرداخت
     * 
     * @param string $authority کد پیگیری
     * @param int $amount مبلغ (ریال)
     * @return array ['success' => bool, 'ref_id' => string, 'message' => string]
     */
    public function verify(string $authority, int $amount): array;

    /**
     * دریافت نام درگاه
     */
    public function getName(): string;
}