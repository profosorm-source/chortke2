<?php

namespace App\Services\Payment;

class PaymentGatewayFactory
{
    /**
     * ایجاد instance درگاه بر اساس نام
     */
    public static function create(string $gateway): PaymentGatewayInterface
    {
        return match ($gateway) {
            'zarinpal' => new ZarinPalGateway(),
            'nextpay' => new NextPayGateway(),
            'idpay' => new IDPayGateway(),
            'dgpay' => new DgPayGateway(),
            default => throw new \Exception('درگاه پرداخت نامعتبر است')
        };
    }

    /**
     * لیست درگاه‌های فعال
     */
    public static function getAvailableGateways(): array
    {
        return [
            'zarinpal' => [
                'name' => 'زرین‌پال',
                'icon' => 'zarinpal.png',
                'description' => 'پرداخت امن با زرین‌پال'
            ],
            'nextpay' => [
                'name' => 'نکست‌پی',
                'icon' => 'nextpay.png',
                'description' => 'پرداخت سریع با نکست‌پی'
            ],
            'idpay' => [
                'name' => 'آیدی‌پی',
                'icon' => 'idpay.png',
                'description' => 'پرداخت آنلاین آیدی‌پی'
            ],
            'dgpay' => [
                'name' => 'دی‌جی‌پی',
                'icon' => 'dgpay.png',
                'description' => 'درگاه پرداخت دی‌جی‌پی'
            ],
        ];
    }
}