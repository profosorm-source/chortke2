<?php

namespace App\Services;

use App\Models\BankCard;
use App\Models\PaymentLog;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\ZarinPalGateway;
use App\Services\Payment\NextPayGateway;
use App\Services\Payment\IDPayGateway;
use App\Services\Payment\DgPayGateway;
use Core\Logger;

class PaymentService
{
    private \App\Models\BankCard $bankCardModel;
    private PaymentLog $log;
    private WalletService $wallet;
    private NotificationService $notifier;
    private Logger $logger;

    public function __construct(
        WalletService $walletService,
        NotificationService $notificationService,
        \App\Models\PaymentLog $log,
        \App\Models\BankCard $bankCardModel,
        Logger $logger
    ) {
        $this->log = $log;
        $this->wallet = $walletService;
        $this->notifier = $notificationService;
        $this->bankCardModel = $bankCardModel;
        $this->logger = $logger->withChannel('payment');
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
        $this->logger->info('payment.create.started', [
            'user_id' => $userId,
            'gateway' => $gatewayName,
            'amount' => $amount,
            'bank_card_id' => $bankCardId
        ]);
        
        if (!CurrencyService::isIRT()) {
            $this->logger->warning('payment.create.failed', [
                'user_id' => $userId,
                'reason' => 'currency_not_irt'
            ]);
            return ['success' => false, 'message' => 'پرداخت آنلاین فقط در حالت تومان فعال است'];
        }

        if ($amount < 1000) {
            $this->logger->warning('payment.create.failed', [
                'user_id' => $userId,
                'reason' => 'amount_too_low',
                'amount' => $amount
            ]);
            return ['success' => false, 'message' => 'حداقل مبلغ ۱۰۰۰ تومان است'];
        }

        // enforce کارت تایید شده
        $card = ($this->bankCardModel)
            ->where('id', $bankCardId)
            ->where('user_id', $userId)
            ->where('status', 'verified')
            ->where('deleted_at', null)
            ->first();

        if (!$card) {
            $this->logger->error('payment.create.failed', [
                'user_id' => $userId,
                'reason' => 'invalid_bank_card',
                'bank_card_id' => $bankCardId
            ]);
            return ['success' => false, 'message' => 'کارت انتخابی معتبر یا تأیید شده نیست'];
        }

        $gw = $this->gateway($gatewayName);
        if (!$gw) {
            $this->logger->error('payment.create.failed', [
                'user_id' => $userId,
                'reason' => 'invalid_gateway',
                'gateway' => $gatewayName
            ]);
            return ['success' => false, 'message' => 'درگاه نامعتبر است'];
        }

        $callback = url('/payment/callback/' . $gatewayName);
        $desc = 'شارژ کیف پول چرتکه';

        try {
            $res = $gw->createPayment($amount, $desc, $callback);
        } catch (\Exception $e) {
            $this->logger->critical('payment.gateway.exception', [
                'user_id' => $userId,
                'gateway' => $gatewayName,
                'amount' => $amount,
                'exception' => get_class($e),
                'message' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => 'خطا در ارتباط با درگاه پرداخت'];
        }

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
            $this->logger->error('payment.create.gateway_failed', [
                'user_id' => $userId,
                'gateway' => $gatewayName,
                'amount' => $amount,
                'log_id' => $logId,
                'gateway_message' => $res['message'] ?? 'unknown'
            ]);
            return ['success' => false, 'message' => $res['message'] ?? 'خطا در ایجاد پرداخت'];
        }

        $this->logger->info('payment.create.success', [
            'user_id' => $userId,
            'gateway' => $gatewayName,
            'amount' => $amount,
            'log_id' => $logId,
            'authority' => $res['authority']
        ]);

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
    $this->logger->info('payment.callback.received', [
        'gateway' => $gatewayName,
        'callback_data' => $callbackData
    ]);
    
    $gw = $this->gateway($gatewayName);
    if (!$gw) {
        $this->logger->error('payment.callback.invalid_gateway', [
            'gateway' => $gatewayName
        ]);
        return ['success' => false, 'message' => 'درگاه نامعتبر است'];
    }

    $authority = $callbackData['Authority']
        ?? $callbackData['trans_id']
        ?? $callbackData['id']
        ?? $callbackData['token']
        ?? null;

    if (!$authority) {
        $this->logger->error('payment.callback.no_authority', [
            'gateway' => $gatewayName,
            'callback_data' => $callbackData
        ]);
        return ['success' => false, 'message' => 'کد رهگیری یافت نشد'];
    }

    $pay = $this->log->where('authority', $authority)->first();
    if (!$pay) {
        $this->logger->error('payment.callback.not_found', [
            'gateway' => $gatewayName,
            'authority' => $authority
        ]);
        return ['success' => false, 'message' => 'پرداخت یافت نشد'];
    }

    if ($pay->status === 'completed') {
        $this->logger->warning('payment.callback.already_completed', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'user_id' => $pay->user_id,
            'ref_id' => $pay->ref_id
        ]);
        return ['success' => true, 'message' => 'این پرداخت قبلاً تکمیل شده است', 'ref_id' => $pay->ref_id];
    }

    // اگر کاربر لغو کرد
    $status = $callbackData['Status'] ?? $callbackData['status'] ?? null;
    if ($status === 'NOK' || $status === 'cancel' || $status === 0) {
        $this->log->update((int)$pay->id, [
            'status' => 'cancelled',
            'response_data' => \json_encode($callbackData, JSON_UNESCAPED_UNICODE),
        ]);
        
        $this->logger->info('payment.callback.cancelled', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'user_id' => $pay->user_id,
            'amount' => $pay->amount
        ]);
        
        return ['success' => false, 'message' => 'پرداخت لغو شد'];
    }

    // Verify
    try {
        $verify = $gw->verifyPayment($authority, (float)$pay->amount);
    } catch (\Exception $e) {
        $this->logger->critical('payment.verify.exception', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'user_id' => $pay->user_id,
            'amount' => $pay->amount,
            'exception' => get_class($e),
            'message' => $e->getMessage()
        ]);
        
        $this->log->update((int)$pay->id, [
            'status' => 'failed',
            'response_data' => \json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
        ]);
        
        return ['success' => false, 'message' => 'خطا در تأیید پرداخت'];
    }

    $this->log->update((int)$pay->id, [
        'status' => $verify['success'] ? 'verified' : 'failed',
        'ref_id' => $verify['ref_id'] ?? null,
        'paid_at' => $verify['success'] ? date('Y-m-d H:i:s') : null,
        'response_data' => \json_encode($verify, JSON_UNESCAPED_UNICODE),
    ]);

    if (!$verify['success']) {
        $this->logger->error('payment.verify.failed', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'user_id' => $pay->user_id,
            'amount' => $pay->amount,
            'verify_message' => $verify['message'] ?? 'unknown'
        ]);
        return ['success' => false, 'message' => $verify['message'] ?? 'تأیید پرداخت ناموفق'];
    }

    // ✅ اصلاح شد: شارژ کیف پول با ترتیب صحیح
    // deposit(userId, amount, currency, metadata)
    try {
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
    } catch (\Exception $e) {
        $this->logger->critical('payment.wallet_deposit.exception', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'user_id' => $pay->user_id,
            'amount' => $pay->amount,
            'ref_id' => $verify['ref_id'] ?? null,
            'exception' => get_class($e),
            'message' => $e->getMessage()
        ]);
        
        return [
            'success' => false,
            'message' => 'پرداخت تأیید شد اما خطا در شارژ کیف پول رخ داد، با پشتیبانی تماس بگیرید'
        ];
    }

    // ✅ چک صحیح
    if (!$ok['success']) {
        $this->logger->error('payment.wallet_deposit.failed', [
            'gateway' => $gatewayName,
            'authority' => $authority,
            'user_id' => $pay->user_id,
            'amount' => $pay->amount,
            'ref_id' => $verify['ref_id'] ?? null,
            'wallet_message' => $ok['message'] ?? 'unknown'
        ]);
        
        return [
            'success' => false,
            'message' => 'پرداخت تأیید شد اما شارژ کیف پول ناموفق بود، با پشتیبانی تماس بگیرید'
        ];
    }

    $this->log->update((int)$pay->id, ['status' => 'completed']);

    $this->logger->info('payment.completed', [
        'gateway' => $gatewayName,
        'authority' => $authority,
        'user_id' => $pay->user_id,
        'amount' => $pay->amount,
        'ref_id' => $verify['ref_id'] ?? null
    ]);

    // نوتیفیکیشن
    $this->notifier->depositSuccess((int)$pay->user_id, (float)$pay->amount, 'IRT');

    return [
        'success' => true,
        'message' => 'پرداخت موفق و کیف پول شارژ شد',
        'ref_id'  => $verify['ref_id'] ?? null
    ];
}
}