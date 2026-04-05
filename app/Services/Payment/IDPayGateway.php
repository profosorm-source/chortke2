<?php

namespace App\Services\Payment;

use App\Models\PaymentGateway;

class IDPayGateway implements PaymentGatewayInterface
{
    private \App\Models\PaymentGateway $paymentGatewayModel;
    private ?object $config;

    public function __construct(
        \App\Models\PaymentGateway $paymentGatewayModel
    )
    {
        $model = $this->paymentGatewayModel;
        $this->config = $model->getActiveGateway('idpay');
        $this->paymentGatewayModel = $paymentGatewayModel;
    }

    public function request(int $amount, string $description, string $callback): array
    {
        if (!$this->config) {
            return [
                'success' => false,
                'message' => 'درگاه آیدی‌پی غیرفعال است'
            ];
        }

        $data = [
            'order_id' => \uniqid('idpay_'),
            'amount' => $amount / 10, // آیدی‌پی تومان می‌خواهد
            'desc' => $description,
            'callback' => $callback,
        ];

        $url = 'https://api.idpay.ir/v1.1/payment';

        try {
            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
            \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-API-KEY: ' . $this->config->api_key,
                'X-SANDBOX: ' . ($this->config->is_test_mode ? '1' : '0')
            ]);

            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);

            if ($httpCode !== 201) {
                throw new \Exception('خطا در اتصال به درگاه');
            }

            $result = \json_decode($response, true);

            if (isset($result['id']) && isset($result['link'])) {
                return [
                    'success' => true,
                    'authority' => $result['id'],
                    'url' => $result['link'],
                    'message' => 'موفق'
                ];
            }

            return [
                'success' => false,
                'message' => $result['error_message'] ?? 'خطای نامشخص'
            ];

        } catch (\Exception $e) {
            logger()->error('IDPay request failed', ['error' => $e->getMessage()]);
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
                'message' => 'درگاه آیدی‌پی غیرفعال است'
            ];
        }

        $data = [
            'id' => $authority,
            'order_id' => \uniqid('idpay_'),
        ];

        $url = 'https://api.idpay.ir/v1.1/payment/verify';

        try {
            $ch = \curl_init($url);
            \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            \curl_setopt($ch, CURLOPT_POST, true);
            \curl_setopt($ch, CURLOPT_POSTFIELDS, \json_encode($data));
            \curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'X-API-KEY: ' . $this->config->api_key,
                'X-SANDBOX: ' . ($this->config->is_test_mode ? '1' : '0')
            ]);

            $response = \curl_exec($ch);
            $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);

            if ($httpCode !== 200) {
                throw new \Exception('خطا در اتصال به درگاه');
            }

            $result = \json_decode($response, true);

            if (isset($result['status']) && $result['status'] == 100) {
                return [
                    'success' => true,
                    'ref_id' => $result['track_id'] ?? $authority,
                    'message' => 'پرداخت با موفقیت انجام شد'
                ];
            }

            return [
                'success' => false,
                'message' => $result['error_message'] ?? 'تراکنش ناموفق'
            ];

        } catch (\Exception $e) {
            logger()->error('IDPay verify failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'خطا در تأیید پرداخت'
            ];
        }
    }

    public function getName(): string
    {
        return 'idpay';
    }
}