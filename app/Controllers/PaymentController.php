<?php

namespace App\Controllers;

use App\Models\PaymentGateway;
use App\Services\WalletService;
use App\Services\Payment\ZarinPalGateway;
use App\Services\Payment\NextPayGateway;
use App\Services\Payment\IDPayGateway;
use App\Services\Payment\DgPayGateway;
use App\Controllers\BaseController;
use Core\Validator;

class PaymentController extends BaseController
{
    private WalletService $walletService;

    public function __construct(
        \App\Services\WalletService $walletService)
    {
        parent::__construct();
        $this->walletService = $walletService;
    }

    /**
     * درخواست پرداخت آنلاین
     */
    public function request(): void
    {
        if (!auth()) {
            $this->session->setFlash('error', 'ابتدا وارد شوید');
            redirect('/auth/login');
            return;
        }

        $userId = $this->userId();

        // دریافت داده‌ها
        $data = [
            'gateway' => $this->request->input('gateway'),
            'amount' => $this->request->input('amount'),
        ];

        // اعتبارسنجی
        $validator = new Validator($data, [
            'gateway' => 'required|in:zarinpal,nextpay,idpay,dgpay',
            'amount' => 'required|numeric|min:10000',
        ], [
            'gateway.required' => 'انتخاب درگاه الزامی است',
            'amount.required' => 'مبلغ الزامی است',
            'amount.min' => 'حداقل مبلغ پرداخت 10,000 تومان است',
        ]);

        if ($validator->fails()) {
            $this->session->setFlash('error', $validator->errors()[0]);
            redirect('/wallet/deposit');
            return;
        }

        try {
            $amount = (int)$data['amount'];
            $amountRial = $amount * 10; // تبدیل تومان به ریال

            // انتخاب درگاه
            $gateway = match($data['gateway']) {
                'zarinpal' => new ZarinPalGateway(),
                'nextpay' => new NextPayGateway(),
                'idpay' => new IDPayGateway(),
                'dgpay' => new DgPayGateway(),
                default => throw new \RuntimeException('درگاه نامعتبر'),
            };

            $callback = url('/payment/callback');
            $description = "شارژ کیف پول - کاربر {$userId}";

            // درخواست به درگاه
            $result = $gateway->request($amountRial, $description, $callback);

            if (!$result['success']) {
                throw new \RuntimeException($result['message']);
            }

            // ذخیره اطلاعات در Session
            $this->session->set('payment_data', [
                'user_id' => $userId,
                'gateway' => $data['gateway'],
                'amount' => $amount,
                'amount_rial' => $amountRial,
                'authority' => $result['authority'],
                'created_at' => \time(),
            ]);

            // ثبت لاگ
            log_activity(
                $userId,
                'payment_requested',
                "درخواست پرداخت {$amount} تومان از طریق {$data['gateway']}",
                ['authority' => $result['authority']]
            );

            // هدایت به درگاه
            redirect($result['url']);

        } catch (\Exception $e) {
            logger()->error('Payment request failed', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);

            $this->session->setFlash('error', 'خطا در اتصال به درگاه پرداخت');
            redirect('/wallet/deposit');
        }
    }

    /**
     * بازگشت از درگاه پرداخت
     */
    public function callback(): void
{
    // دریافت داده‌های ذخیره‌شده
    $paymentData = $this->session->get('payment_data');

    if (!$paymentData) {
        $this->session->setFlash('error', 'اطلاعات پرداخت یافت نشد');
        redirect('/wallet');
        return;
    }

    // بررسی انقضا (30 دقیقه)
    if ((\time() - $paymentData['created_at']) > 1800) {
        $this->session->remove('payment_data');
        $this->session->setFlash('error', 'زمان پرداخت منقضی شده است');
        redirect('/wallet');
        return;
    }

    $userId     = (int) $paymentData['user_id'];
    $gateway    = (string) $paymentData['gateway'];
    $amount     = (float) $paymentData['amount'];       // ✅ float برای WalletService
    $amountRial = (int) $paymentData['amount_rial'];
    $authority  = (string) $paymentData['authority'];

    try {
        // انتخاب درگاه
        $gatewayInstance = match ($gateway) {
            'zarinpal' => new ZarinPalGateway(),
            'nextpay'  => new NextPayGateway(),
            'idpay'    => new IDPayGateway(),
            'dgpay'    => new DgPayGateway(),
            default    => throw new \RuntimeException('درگاه نامعتبر'),
        };

        // تأیید پرداخت
        $result = $gatewayInstance->verify($authority, $amountRial);

        if (empty($result['success'])) {
            throw new \RuntimeException($result['message'] ?? 'تأیید پرداخت ناموفق بود');
        }

        // ✅ افزایش موجودی (WalletService جدید: خروجی array)
        $depositResult = $this->walletService->deposit($userId, $amount, 'irt', [
            'type'                   => 'gateway_deposit',
            'gateway'                => $gateway,
            'gateway_transaction_id' => $result['ref_id'] ?? null,
            'description'            => 'شارژ کیف پول از طریق ' . $gateway,
            'authority'              => $authority,
            'ref_id'                 => $result['ref_id'] ?? null,
            'ref_type'               => 'payment_gateway',
        ]);

        if (empty($depositResult['success'])) {
            throw new \RuntimeException($depositResult['message'] ?? 'خطا در افزایش موجودی');
        }

        // پاک کردن داده‌های موقت (بعد از شارژ موفق)
        $this->session->remove('payment_data');

        // ثبت لاگ
        log_activity(
            $userId,
            'payment_verified',
            "پرداخت {$amount} تومان تأیید شد",
            [
                'ref_id'          => $result['ref_id'] ?? null,
                'transaction_id'  => $depositResult['transaction_id'] ?? null,
                'gateway'         => $gateway,
                'authority'       => $authority,
            ]
        );

        $this->session->setFlash('success', "پرداخت با موفقیت انجام شد. مبلغ {$amount} تومان به کیف پول شما اضافه شد");
        redirect('/wallet');

    } catch (\Exception $e) {
        logger()->error('Payment verification failed', [
            'user_id'    => $userId,
            'authority'  => $authority,
            'gateway'    => $gateway,
            'amount'     => $amount,
            'amount_rial'=> $amountRial,
            'error'      => $e->getMessage()
        ]);

        $this->session->setFlash('error', 'پرداخت ناموفق بود: ' . $e->getMessage());
        redirect('/wallet');
    }
}
}