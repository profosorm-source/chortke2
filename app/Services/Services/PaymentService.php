<?php

namespace App\Services;

use App\Models\BankCard;
use App\Models\PaymentLog;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\ZarinPalGateway;
use App\Services\Payment\NextPayGateway;
use App\Services\Payment\IDPayGateway;
use App\Services\Payment\DgPayGateway;

class PaymentService
{
    private \App\Models\BankCard $bankCardModel;
    private PaymentLog $log;
    private WalletService $wallet;
    private NotificationService $notifier;

    public function __construct(
        WalletService $walletService,
        NotificationService $notificationService,
        \App\Models\PaymentLog $log,
        \App\Models\BankCard $bankCardModel) {
        $this->log = $log;
        $this->wallet = $walletService;
        $this->notifier = $notificationService;
        $this->bankCardModel = $bankCardModel;
    }

    private function gateway(string $name): ?PaymentGatewayInterface
    {
        return match ($name) {
            'zarinpal' => new ZarinPalGateway(),
            'nextpay'  => new NextPayGateway(),
            'idpay'    => new IDPayGateway(),
            'dgpay'    => new DgPayGateway(),
            default    => null,
        };
    }

    public function create(int $userId, string $gatewayName, float $amount, int $bankCardId): array
    {
        if (!CurrencyService::isIRT()) {
            return ['success' => false, 'message' => 'پرداخت آنلاین فقط در حالت تومان فعال است'];
        }

        if ($amount < 1000) return ['success' => false, 'message' => 'حداقل مبلغ ۱۰۰۰ تومان است'];

        // enforce کارت تایید شده
        $card = ($this->bankCardModel)
            ->where('id', $bankCardId)
            ->where('user_id', $userId)
            ->where('status', 'verified')
            ->where('deleted_at', null)
            ->first();

        if (!$card) {
            return ['success' => false, 'message' => 'کارت انتخابی معتبر یا تأیید شده نیست'];
        }

        $gw = $this->gateway($gatewayName);
        if (!$gw) return ['success' => false, 'message' => 'درگاه نامعتبر است'];

        $callback = url('/payment/callback/' . $gatewayName);
        $desc = 'شارژ کیف پول چرتکه';

        $res = $gw->createPayment($amount, $desc, $callback);

        $logId = $this->log->create([
            'user_id' => $userId,
            'bank_card_id' => $bankCardId,
            'card_last4' => substr((string)$card->card_number, -4),
            'gateway' => $gatewayName,
            'amount' => $amount,
            'authority' => $res['authority'] ?? null,
            'status' => $res['success'] ? 'pending' : 'failed',
            'request_data' => \json_encode(['amount'=>$amount,'callback'=>$callback], JSON_UNESCAPED_UNICODE),
            'response_data' => \json_encode($res, JSON_UNESCAPED_UNICODE),
            'ip_address' => get_client_ip(),
            'user_agent' => get_user_agent(),
        ]);

        if (!$res['success']) {
            return ['success' => false, 'message' => $res['message'] ?? 'خطا در ایجاد پرداخت'];
        }

        return [
            'success' => true,
            'payment_url' => $res['payment_url'],
            'authority' => $res['authority'],
            'log_id' => (int)$logId
        ];
    }
/**
 * Callback پرداخت آنلاین
 * 
 * فایل: app/Services/PaymentService.php
 * خط: ~85
 */
public function callback(string $gatewayName, array $callbackData): array
{
    $gw = $this->gateway($gatewayName);
    if (!$gw) return ['success' => false, 'message' => 'درگاه نامعتبر است'];

    $authority = $callbackData['Authority']
        ?? $callbackData['trans_id']
        ?? $callbackData['id']
        ?? $callbackData['token']
        ?? null;

    if (!$authority) return ['success' => false, 'message' => 'کد رهگیری یافت نشد'];

    $pay = $this->log->where('authority', $authority)->first();
    if (!$pay) return ['success' => false, 'message' => 'پرداخت یافت نشد'];

    if ($pay->status === 'completed') {
        return ['success' => true, 'message' => 'این پرداخت قبلاً تکمیل شده است', 'ref_id' => $pay->ref_id];
    }

    // اگر کاربر لغو کرد
    $status = $callbackData['Status'] ?? $callbackData['status'] ?? null;
    if ($status === 'NOK' || $status === 'cancel' || $status === 0) {
        $this->log->update((int)$pay->id, [
            'status' => 'cancelled',
            'response_data' => \json_encode($callbackData, JSON_UNESCAPED_UNICODE),
        ]);
        return ['success' => false, 'message' => 'پرداخت لغو شد'];
    }

    // Verify
    $verify = $gw->verifyPayment($authority, (float)$pay->amount);

    $this->log->update((int)$pay->id, [
        'status' => $verify['success'] ? 'verified' : 'failed',
        'ref_id' => $verify['ref_id'] ?? null,
        'paid_at' => $verify['success'] ? date('Y-m-d H:i:s') : null,
        'response_data' => \json_encode($verify, JSON_UNESCAPED_UNICODE),
    ]);

    if (!$verify['success']) {
        return ['success' => false, 'message' => $verify['message'] ?? 'تأیید پرداخت ناموفق'];
    }

    // ✅ اصلاح شد: شارژ کیف پول با ترتیب صحیح
    // deposit(userId, amount, currency, metadata)
    $ok = $this->wallet->deposit(
        (int) $pay->user_id,        // 1. userId
        (float) $pay->amount,        // 2. amount
        'irt',                       // 3. currency (lowercase)
        [                            // 4. metadata
            'type'                  => 'gateway_deposit',
            'gateway'               => $gatewayName,
            'authority'             => $authority,
            'ref_id'                => $verify['ref_id'] ?? null,
            'description'           => 'واریز آنلاین (درگاه)'
        ]
    );

    // ✅ چک صحیح
    if (!$ok['success']) {
        return [
            'success' => false,
            'message' => 'پرداخت تأیید شد اما شارژ کیف پول ناموفق بود، با پشتیبانی تماس بگیرید'
        ];
    }

    $this->log->update((int)$pay->id, ['status' => 'completed']);

    // نوتیفیکیشن
    $this->notifier->depositSuccess((int)$pay->user_id, (float)$pay->amount, 'IRT');

    return [
        'success' => true,
        'message' => 'پرداخت موفق و کیف پول شارژ شد',
        'ref_id'  => $verify['ref_id'] ?? null
    ];
}
}