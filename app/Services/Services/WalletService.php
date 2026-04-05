<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\Transaction;
use Core\Database;

class WalletService
{
    private \Core\IdempotencyKey $idempotencyKey;
    private const SUPPORTED_CURRENCIES = ['irt', 'usdt'];
    private const SUPPORTED_NETWORKS   = ['TRC20', 'BEP20'];
    private const MIN_AMOUNT           = 0.01;

    private Wallet      $walletModel;
    private Transaction $transactionModel;
    private Database    $db;
    private AuditTrail  $audit;

    public function __construct(
        Database             $db,
        \App\Models\Wallet      $walletModel,
        \App\Models\Transaction $transactionModel,
        \Core\IdempotencyKey    $idempotencyKey,
        AuditTrail           $audit
    ) {
        $this->db               = $db;
        $this->walletModel      = $walletModel;
        $this->transactionModel = $transactionModel;
        $this->idempotencyKey   = $idempotencyKey;
        $this->audit            = $audit;
    }

    // ─────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────

    public function getOrCreateWallet(int $userId): ?object
    {
        $wallet = $this->walletModel->findByUserId($userId);
        if (!$wallet) {
            $wallet = $this->walletModel->createForUser($userId);
        }
        return $wallet;
    }

    /**
     * افزایش موجودی (واریز) — با Idempotency Protection
     */
    public function deposit(int $userId, float $amount, string $currency = 'irt', array $metadata = []): array
    {
        $currency = strtolower($currency);
        $this->validateCurrency($currency);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ باید بیشتر از صفر باشد');
        }

        $minAmount = ($currency === 'usdt') ? 1 : 1000;
        if ($amount < $minAmount) {
            throw new \InvalidArgumentException("حداقل مبلغ واریز {$minAmount} " . ($currency === 'usdt' ? 'USDT' : 'تومان') . " است");
        }

        $requestId         = $metadata['request_id']         ?? get_request_id();
        $ipAddress         = $metadata['ip_address']         ?? get_client_ip();
        $deviceFingerprint = $metadata['device_fingerprint'] ?? generate_device_fingerprint();
        $logId             = "DEP_{$requestId}";

        $idempotencyKey = $metadata['idempotency_key'] ?? hash('sha256', implode('|', [
            $userId, 'deposit', $amount, $currency,
            $metadata['gateway_transaction_id'] ?? '',
            $metadata['ref_id']                 ?? '',
        ]));

        $idempotencyService = $this->idempotencyKey;
        $check = $idempotencyService->check($idempotencyKey, $userId, 'wallet_deposit', [
            'amount' => $amount, 'currency' => $currency, 'ip' => $ipAddress,
        ]);

        if ($check['is_duplicate']) {
            error_log("[{$logId}] ⚠️ Duplicate deposit detected | Key: {$idempotencyKey}");
            return $check['result'];
        }

        try {
            $this->db->beginTransaction();

            $wallet = $this->walletModel->findByUserIdForUpdate($userId);
            if (!$wallet) {
                throw new \RuntimeException('خطا در دریافت wallet');
            }

            $balanceField  = $this->balanceField($currency);
            $balanceBefore = (float)$wallet->$balanceField;
            $balanceAfter  = (float)bcadd((string)$balanceBefore, (string)$amount, 2);

            if (!$this->walletModel->setBalance($userId, $balanceAfter, $currency)) {
                throw new \RuntimeException('خطا در بروزرسانی موجودی');
            }

            $transaction = $this->transactionModel->create([
                'user_id'                => $userId,
                'type'                   => $metadata['type'] ?? 'deposit',
                'currency'               => $currency,
                'amount'                 => $amount,
                'balance_before'         => $balanceBefore,
                'balance_after'          => $balanceAfter,
                'status'                 => 'completed',
                'description'            => $metadata['description'] ?? 'واریز وجه',
                'gateway'                => $metadata['gateway']                ?? null,
                'gateway_transaction_id' => $metadata['gateway_transaction_id'] ?? null,
                'ref_id'                 => $metadata['ref_id']                 ?? null,
                'ref_type'               => $metadata['ref_type']               ?? null,
                'request_id'             => $requestId,
                'ip_address'             => $ipAddress,
                'device_fingerprint'     => $deviceFingerprint,
                'idempotency_key'        => $idempotencyKey,
                'metadata'               => json_encode(array_merge($metadata, [
                    'request_id' => $requestId, 'ip'     => $ipAddress,
                    'device'     => $deviceFingerprint,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'timestamp'  => date('Y-m-d H:i:s'),
                ]), JSON_UNESCAPED_UNICODE),
            ]);

            if (!$transaction) {
                throw new \RuntimeException('خطا در ثبت تراکنش');
            }

            $result = [
                'success'        => true,
                'transaction_id' => $transaction->transaction_id,
                'message'        => 'واریز با موفقیت انجام شد',
                'new_balance'    => $balanceAfter,
                'amount'         => $amount,
                'currency'       => $currency,
            ];

            $idempotencyService->complete($idempotencyKey, $result, $userId);
            $this->db->commit();

            $this->audit->record(AuditTrail::WALLET_CREDITED, $userId, [
                'amount'         => $amount,
                'currency'       => $currency,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'type'           => $metadata['type'] ?? 'deposit',
                'transaction_id' => $transaction->transaction_id,
            ]);

            log_activity($userId, 'wallet_deposit',
                "واریز {$amount} " . ($currency === 'usdt' ? 'USDT' : 'تومان'),
                ['transaction_id' => $transaction->transaction_id]
            );

            error_log("[{$logId}] ✅ Deposit success: User#{$userId}, {$amount} {$currency}");
            return $result;

        } catch (\InvalidArgumentException $e) {
            $idempotencyService->fail($idempotencyKey, ['error' => $e->getMessage(), 'type' => 'validation_error'], $userId);
            throw $e;
        } catch (\Exception $e) {
            $this->db->rollBack();
            $idempotencyService->fail($idempotencyKey, ['error' => $e->getMessage(), 'type' => 'runtime_error'], $userId);
            logger()->error('Deposit failed', ['user_id' => $userId, 'amount' => $amount, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * برداشت وجه — با قفل موجودی و Idempotency
     */
    public function withdraw(int $userId, float $amount, string $currency = 'irt', array $metadata = []): array
    {
        $currency = strtolower($currency);
        $this->validateCurrency($currency);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ باید بیشتر از صفر باشد');
        }

        $minAmount = ($currency === 'usdt') ? 5 : 10000;
        if ($amount < $minAmount) {
            throw new \InvalidArgumentException("حداقل مبلغ برداشت {$minAmount} " . ($currency === 'usdt' ? 'USDT' : 'تومان') . " است");
        }

        $requestId         = $metadata['request_id']         ?? get_request_id();
        $ipAddress         = $metadata['ip_address']         ?? get_client_ip();
        $deviceFingerprint = $metadata['device_fingerprint'] ?? generate_device_fingerprint();
        $logId             = "WTH_{$requestId}";

        $idempotencyKey = $metadata['idempotency_key'] ?? hash('sha256', implode('|', [
            $userId, 'withdraw', $amount, $currency,
            $metadata['card_id']        ?? '',
            $metadata['wallet_address'] ?? '',
        ]));

        $idempotencyService = $this->idempotencyKey;
        $check = $idempotencyService->check($idempotencyKey, $userId, 'wallet_withdraw', [
            'amount' => $amount, 'currency' => $currency, 'ip' => $ipAddress,
        ]);

        if ($check['is_duplicate']) {
            error_log("[{$logId}] ⚠️ Duplicate withdraw detected | Key: {$idempotencyKey}");
            return $check['result'];
        }

        try {
            $this->db->beginTransaction();

            $wallet = $this->walletModel->findByUserIdForUpdate($userId);
            if (!$wallet) {
                throw new \RuntimeException('خطا در دریافت wallet');
            }

            $balanceField   = $this->balanceField($currency);
            $currentBalance = (float)$wallet->$balanceField;

            if (bccomp((string)$currentBalance, (string)$amount, 2) < 0) {
                throw new \RuntimeException("موجودی کافی نیست (موجودی فعلی: {$currentBalance})");
            }

            $balanceBefore = $currentBalance;
            $balanceAfter  = (float)bcsub((string)$balanceBefore, (string)$amount, 2);

            if (!$this->walletModel->setBalanceAndWithdrawalTime($userId, $balanceAfter, $currency)) {
                throw new \RuntimeException('خطا در بروزرسانی موجودی');
            }

            $transaction = $this->transactionModel->create([
                'user_id'            => $userId,
                'type'               => 'withdraw',
                'currency'           => $currency,
                'amount'             => $amount,
                'balance_before'     => $balanceBefore,
                'balance_after'      => $balanceAfter,
                'status'             => 'pending',
                'description'        => $metadata['description'] ?? 'برداشت وجه',
                'request_id'         => $requestId,
                'ip_address'         => $ipAddress,
                'device_fingerprint' => $deviceFingerprint,
                'idempotency_key'    => $idempotencyKey,
                'metadata'           => json_encode(array_merge($metadata, [
                    'request_id' => $requestId, 'ip'     => $ipAddress,
                    'device'     => $deviceFingerprint,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'timestamp'  => date('Y-m-d H:i:s'),
                ]), JSON_UNESCAPED_UNICODE),
            ]);

            if (!$transaction) {
                throw new \RuntimeException('خطا در ثبت تراکنش');
            }

            $result = [
                'success'        => true,
                'transaction_id' => $transaction->transaction_id,
                'message'        => 'درخواست برداشت ثبت شد و منتظر تایید است',
                'new_balance'    => $balanceAfter,
                'amount'         => $amount,
                'currency'       => $currency,
                'status'         => 'pending',
            ];

            $idempotencyService->complete($idempotencyKey, $result, $userId);
            $this->db->commit();

            $this->audit->record(AuditTrail::WALLET_DEBITED, $userId, [
                'amount'         => $amount,
                'currency'       => $currency,
                'balance_before' => $balanceBefore,
                'balance_after'  => $balanceAfter,
                'type'           => $metadata['type'] ?? 'withdraw',
                'transaction_id' => $transaction->transaction_id,
            ]);

            log_activity($userId, 'wallet_withdraw',
                "برداشت {$amount} " . ($currency === 'usdt' ? 'USDT' : 'تومان'),
                ['transaction_id' => $transaction->transaction_id]
            );

            error_log("[{$logId}] ✅ Withdraw success: User#{$userId}, {$amount} {$currency}");
            return $result;

        } catch (\InvalidArgumentException $e) {
            $idempotencyService->fail($idempotencyKey, ['error' => $e->getMessage(), 'type' => 'validation_error'], $userId);
            throw $e;
        } catch (\Exception $e) {
            $this->db->rollBack();
            $idempotencyService->fail($idempotencyKey, ['error' => $e->getMessage(), 'type' => 'runtime_error'], $userId);
            logger()->error('Withdraw failed', ['user_id' => $userId, 'amount' => $amount, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function hasBalance(int $userId, float $amount, string $currency = 'irt'): bool
    {
        $currency = strtolower($currency);
        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            return false;
        }
        $balance = $this->walletModel->getBalance($userId, $currency);
        return bccomp((string)$balance, (string)$amount, 2) >= 0;
    }

    public function completeWithdrawal(int $userId, float $amount, string $currency, ?string $transactionId): bool
    {
        try {
            if ($transactionId) {
                $this->transactionModel->updateStatusByTransactionId($transactionId, $userId, 'completed');
            }
            return true;
        } catch (\Exception $e) {
            error_log("completeWithdrawal Error: " . $e->getMessage());
            return false;
        }
    }

    public function cancelWithdrawal(int $userId, float $amount, string $currency, ?string $transactionId): bool
    {
        try {
            $result = $this->deposit($userId, $amount, $currency, [
                'type'               => 'withdrawal_refund',
                'description'        => 'بازگشت وجه برداشت لغو شده',
                'ref_transaction_id' => $transactionId,
            ]);

            if (!$result['success']) {
                error_log("cancelWithdrawal Deposit failed: " . $result['message']);
                return false;
            }

            if ($transactionId) {
                $this->transactionModel->updateStatusByTransactionId($transactionId, $userId, 'cancelled');
            }

            return true;

        } catch (\Exception $e) {
            error_log("cancelWithdrawal Error: " . $e->getMessage());
            return false;
        }
    }

    public function canWithdraw(int $userId, float $amount, string $currency = 'irt'): array
    {
        $result = ['can_withdraw' => false, 'message' => ''];

        $balance = $this->walletModel->getBalance($userId, $currency);
        if (bccomp((string)$balance, (string)$amount, 2) < 0) {
            $result['message'] = 'موجودی کافی نیست';
            return $result;
        }

        if (!$this->walletModel->canWithdrawToday($userId)) {
            $result['message'] = 'شما امروز یکبار برداشت کرده‌اید';
            return $result;
        }

        $minWithdrawal = ($currency === 'usdt') ? 5.0 : 10000.0;
        if (bccomp((string)$amount, (string)$minWithdrawal, 2) < 0) {
            $result['message'] = 'حداقل مبلغ برداشت ' . number_format($minWithdrawal) . ' ' . ($currency === 'usdt' ? 'USDT' : 'تومان') . ' است';
            return $result;
        }

        $result['can_withdraw'] = true;
        return $result;
    }

    public function getWalletSummary(int $userId): object
    {
        $wallet = $this->getOrCreateWallet($userId);
        $stats  = $this->transactionModel->getUserStats($userId);

        return (object)[
            'balance_irt'        => (float)$wallet->balance_irt,
            'balance_usdt'       => (float)$wallet->balance_usdt,
            'locked_irt'         => (float)$wallet->locked_irt,
            'locked_usdt'        => (float)$wallet->locked_usdt,
            'total_irt'          => (float)$wallet->balance_irt  + (float)$wallet->locked_irt,
            'total_usdt'         => (float)$wallet->balance_usdt + (float)$wallet->locked_usdt,
            'last_withdrawal_at' => $wallet->last_withdrawal_at,
            'can_withdraw_today' => $this->walletModel->canWithdrawToday($userId),
            'stats'              => $stats,
        ];
    }

    public function transfer(int $fromUserId, int $toUserId, float $amount, string $currency = 'irt', string $description = ''): ?object
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ باید بیشتر از صفر باشد');
        }

        if ($fromUserId === $toUserId) {
            throw new \InvalidArgumentException('نمی‌توانید به خودتان انتقال دهید');
        }

        try {
            $this->db->beginTransaction();

            $firstId  = min($fromUserId, $toUserId);
            $secondId = max($fromUserId, $toUserId);

            $firstWallet  = $this->walletModel->findByUserIdForUpdate($firstId);
            $secondWallet = $this->walletModel->findByUserIdForUpdate($secondId);

            if (!$firstWallet || !$secondWallet) {
                throw new \RuntimeException('کیف پول یافت نشد');
            }

            $fromWallet = ($firstId === $fromUserId) ? $firstWallet : $secondWallet;
            $toWallet   = ($firstId === $toUserId)   ? $firstWallet : $secondWallet;

            $balanceField    = $this->balanceField($currency);
            $fromBalance     = (float)$fromWallet->$balanceField;
            $toBalanceBefore = (float)$toWallet->$balanceField;

            if (bccomp((string)$fromBalance, (string)$amount, 2) < 0) {
                throw new \RuntimeException('موجودی کافی نیست');
            }

            $this->walletModel->updateBalance($fromUserId, -$amount, $currency);
            $this->walletModel->updateBalance($toUserId, $amount, $currency);

            $this->transactionModel->create([
                'user_id'        => $fromUserId,
                'type'           => 'transfer',
                'currency'       => $currency,
                'amount'         => -$amount,
                'balance_before' => $fromBalance,
                'balance_after'  => (float)bcsub((string)$fromBalance, (string)$amount, 2),
                'status'         => 'completed',
                'description'    => $description ?: "انتقال به کاربر {$toUserId}",
                'metadata'       => json_encode(['to_user_id' => $toUserId]),
            ]);

            $transaction = $this->transactionModel->create([
                'user_id'        => $toUserId,
                'type'           => 'transfer',
                'currency'       => $currency,
                'amount'         => $amount,
                'balance_before' => $toBalanceBefore,
                'balance_after'  => (float)bcadd((string)$toBalanceBefore, (string)$amount, 2),
                'status'         => 'completed',
                'description'    => $description ?: "دریافت از کاربر {$fromUserId}",
                'metadata'       => json_encode(['from_user_id' => $fromUserId]),
            ]);

            $this->db->commit();

            log_activity($fromUserId, 'wallet_transfer',
                "انتقال {$amount} " . ($currency === 'usdt' ? 'USDT' : 'تومان') . " به کاربر {$toUserId}",
                ['to_user_id' => $toUserId]
            );

            return $transaction;

        } catch (\Exception $e) {
            $this->db->rollBack();
            logger()->error('Transfer failed', [
                'from_user_id' => $fromUserId,
                'to_user_id'   => $toUserId,
                'amount'       => $amount,
                'error'        => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getBalance(int $userId, string $currency = 'irt'): float
    {
        $currency = strtolower($currency);
        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            return 0.0;
        }
        return $this->walletModel->getBalance($userId, $currency);
    }

    public function updateLedgerStatusByIdempotency(string $idempotencyKey, string $newStatus): bool
    {
        try {
            $affected = $this->transactionModel->updateStatusByIdempotencyKey($idempotencyKey, $newStatus);
            return $affected > 0;
        } catch (\Exception $e) {
            logger()->error('updateLedgerStatusByIdempotency failed', [
                'idempotency_key' => $idempotencyKey,
                'new_status'      => $newStatus,
                'error'           => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────

    private function validateCurrency(string $currency): void
    {
        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            throw new \InvalidArgumentException("ارز '{$currency}' پشتیبانی نمی‌شود. فقط 'irt' و 'usdt' معتبر است.");
        }
    }

    private function balanceField(string $currency): string
    {
        return $currency === 'usdt' ? 'balance_usdt' : 'balance_irt';
    }
}
