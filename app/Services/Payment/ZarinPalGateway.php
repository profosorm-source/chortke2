<?php

namespace App\Services\Payment;

use App\Models\PaymentGateway;

class ZarinPalGateway implements PaymentGatewayInterface
{
    private \App\Models\PaymentGateway $paymentGatewayModel;
    private ?object $config;

    public function __construct(
        \App\Models\PaymentGateway $paymentGatewayModel
    )
    {
        $model = $this->paymentGatewayModel;
        $this->config = $model->getActiveGateway('zarinpal');
        $this->paymentGatewayModel = $paymentGatewayModel;
    }

    public function request(int $amount, string $description, string $callback): array
    {
        if (!$this->config) {
            return [
                'success' => false,
                'message' => 'درگاه زرین‌پال غیرفعال است'
            ];
        }

        $data = [
            'merchant_id' => $this->config->merchant_id,
            'amount' => $amount,
            'description' => $description,
            'callback_url' => $callback,
        ];

        $url = $this->config->is_test_mode 
            ? 'https://sandbox.zarinpal.com/pg/rest/WebGate/PaymentRequest.json'
            : 'https://api.zarinpal.com/pg/v4/payment/request.json';

        try {
            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
            \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);

            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);

            if ($httpCode !== 200) {
                throw new \Exception('خطا در اتصال به درگاه');
            }

            $result = \json_decode($response, true);

            if (isset($result['data']['code']) && $result['data']['code'] == 100) {
                $authority = $result['data']['authority'];
                $paymentUrl = $this->config->is_test_mode
                    ? "https://sandbox.zarinpal.com/pg/StartPay/{$authority}"
                    : "https://www.zarinpal.com/pg/StartPay/{$authority}";

                return [
                    'success' => true,
                    'authority' => $authority,
                    'url' => $paymentUrl,
                    'message' => 'موفق'
                ];
            }

            return [
                'success' => false,
                'message' => $result['errors']['message'] ?? 'خطای نامشخص'
            ];

        } catch (\Exception $e) {
            logger()->error('ZarinPal request failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در برقراری ارتباط با درگاه'
            ];
        }
    }

    public function verify(string $authority, int $amount): array
    {
        if (!$this->config) {
            return [
                'success' => false,
                'message' => 'درگاه زرین‌پال غیرفعال است'
            ];
        }

        $data = [
            'merchant_id' => $this->config->merchant_id,
            'authority' => $authority,
            'amount' => $amount,
        ];

        $url = $this->config->is_test_mode
            ? 'https://sandbox.zarinpal.com/pg/rest/WebGate/PaymentVerification.json'
            : 'https://api.zarinpal.com/pg/v4/payment/verify.json';

        try {
            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
            \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json'
            ]);

            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);

            if ($httpCode !== 200) {
                throw new \Exception('خطا در اتصال به درگاه');
            }

            $result = \json_decode($response, true);

            if (isset($result['data']['code']) && $result['data']['code'] == 100) {
                return [
                    'success' => true,
                    'ref_id' => $result['data']['ref_id'],
                    'message' => 'پرداخت با موفقیت انجام شد'
                ];
            }

            return [
                'success' => false,
                'message' => $result['errors']['message'] ?? 'تراکنش ناموفق'
            ];

        } catch (\Exception $e) {
            logger()->error('ZarinPal verify failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        }
    }

    public function getName(): string
    {
        return 'zarinpal';
    }
}