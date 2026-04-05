<?php

return [
    // ZarinPal
    'zarinpal' => [
        'merchant_id' => env('ZARINPAL_MERCHANT_ID', 'XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX'),
        'sandbox' => env('ZARINPAL_SANDBOX', true),
    ],

    // NextPay
    'nextpay' => [
        'api_key' => env('NEXTPAY_API_KEY', 'XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX'),
    ],

    // IDPay
    'idpay' => [
        'api_key' => env('IDPAY_API_KEY', 'XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX'),
        'sandbox' => env('IDPAY_SANDBOX', true),
    ],

    // DgPay (اضافه خواهد شد)
    'dgpay' => [
        'api_key' => env('DGPAY_API_KEY', ''),
    ],
];