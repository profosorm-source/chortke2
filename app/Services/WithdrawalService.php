<?php

namespace App\Services;

use Core\Database;
use Core\Logger;
use App\Models\Withdrawal;
use App\Models\WithdrawalLimit;
use App\Models\User;
use App\Models\BankCard;
use App\Models\Setting;
use App\Services\WithdrawalLimitService;

class WithdrawalService
{
    private WithdrawalLimitService   $withdrawalLimitService;
    private \App\Models\User         $userModel;
    private \App\Models\Transaction  $transactionModel;
    private \App\Models\BankCard     $bankCardModel;
    private Database                 $db;
    private Withdrawal               $model;
    private WithdrawalLimit          $limitModel;
    private Setting                  $settings;
    private WalletService            $wallet;
    private NotificationService      $notifier;
    private AuditTrail               $audit;
    private Logger                  $logger;

    public function __construct(
        Database               $db,
        WalletService          $walletService,
        NotificationService    $notificationService,
        \App\Models\Withdrawal      $model,
        \App\Models\WithdrawalLimit $limitModel,
        \App\Models\Setting         $settings,
        \App\Models\BankCard        $bankCardModel,
        \App\Models\Transaction     $transactionModel,
        \App\Models\User            $userModel,
        WithdrawalLimitService $withdrawalLimitService,
        AuditTrail             $audit,
        Logger                 $logger
    ) {
        $this->db                     = $db;
        $this->model                  = $model;
        $this->limitModel             = $limitModel;
        $this->settings               = $settings;
        $this->wallet                 = $walletService;
        $this->notifier               = $notificationService;
        $this->bankCardModel          = $bankCardModel;
        $this->transactionModel       = $transactionModel;
        $this->userModel              = $userModel;
        $this->withdrawalLimitService = $withdrawalLimitService;
        $this->audit                  = $audit;
        $this->logger                 = $logger;
    }

    /**
     * ثبت درخواست برداشت
     */
    public function create(int $userId, array $data): array
    {
        $user = $this->userModel->find($userId);
        if (!$user || $user->kyc_status !== 'verified') {
            return ['success' => false, 'message' => 'برای برداشت باید احراز هویت تأیید شده باشد'];
        }

        $currency = (string)($data['currency'] ?? 'IRT');
        if (!in_array($currency, ['IRT', 'USDT'], true)) {
            return ['success' => false, 'message' => 'ارز نامعتبر'];
        }

        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'مبلغ نامعتبر'];
        }

        $limitCheck = $this->withdrawalLimitService->check($userId, $amount, $currency);
        if (!$limitCheck['allowed']) {
            return ['success' => false, 'message' => $limitCheck['reason']];
        }

        $pending = $this->model->where('user_id', $userId)
                               ->whereIn('status', ['pending', 'processing'])
                               ->first();
        if ($pending) {
            return ['success' => false, 'message' => 'شما یک برداشت در حال بررسی دارید'];
        }

        $min = (float)$this->settings->get(
            $currency === 'IRT' ? 'min_withdrawal_irt' : 'min_withdrawal_usdt',
            $currency === 'IRT' ? 50000 : 10
        );
        $max = (float)$this->settings->get(
            $currency === 'IRT' ? 'max_withdrawal_irt' : 'max_withdrawal_usdt',
            $currency === 'IRT' ? 50000000 : 100000
        );

        if ($amount < $min) {
            return ['success' => false, 'message' => 'کمتر از حداقل برداشت است'];
        }
        if ($amount > $max) {
            return ['success' => false, 'message' => 'بیشتر از حداکثر برداشت است'];
        }

        $feePercent = (float)$this->settings->get(
            $currency === 'IRT' ? 'withdrawal_fee_irt' : 'withdrawal_fee_usdt',
            0
        );
        $fee   = ($amount * $feePercent) / 100;
        $final = $amount - $fee;

        $availableApprox = $this->wallet->getBalance($userId, strtolower($currency));
        if ($availableApprox < $amount) {
            return ['success' => false, 'message' => 'موجودی کافی نیست'];
        }

        $withdrawalData = [
            'bank_card_id'   => null,
            'crypto_wallet'  => null,
            'crypto_network' => null,
        ];

        if ($currency === 'IRT') {
            $bankCardId = (int)($data['bank_card_id'] ?? $data['card_id'] ?? 0);
            $card = $this->bankCardModel
                ->where('id', $bankCardId)
                ->where('user_id', $userId)
                ->where('status', 'verified')
                ->where('deleted_at', null)
                ->first();

            if (!$card) {
                return ['success' => false, 'message' => 'کارت بانکی مقصد نامعتبر یا تأیید نشده است'];
            }
            $withdrawalData['bank_card_id'] = $bankCardId;
        } else {
            $net  = (string)($data['crypto_network'] ?? '');
            $addr = trim((string)($data['crypto_wallet'] ?? ''));

            if (!in_array($net, ['BNB20', 'TRC20', 'ERC20', 'TON', 'SOL'], true)) {
                return ['success' => false, 'message' => 'شبکه نامعتبر'];
            }
            if ($addr === '' || strlen($addr) < 10) {
                return ['success' => false, 'message' => 'آدرس ولت نامعتبر'];
            }

            $withdrawalData['crypto_network'] = $net;
            $withdrawalData['crypto_wallet']  = $addr;
        }

        $idempotencyKey = $this->uuid();

        $w = $this->wallet->withdraw(
            $userId,
            $amount,
            strtolower($currency),
            [
                'type'            => 'withdrawal_request',
                'description'     => 'درخواست برداشت',
                'idempotency_key' => $idempotencyKey,
                'fee'             => $fee,
                'final_amount'    => $final,
            ]
        );

        if (!$w['success']) {
            return ['success' => false, 'message' => $w['message'] ?? 'خطا در ثبت برداشت'];
        }

        $id = $this->model->create(array_merge([
            'user_id'          => $userId,
            'currency'         => $currency,
            'amount'           => $amount,
            'fee'              => $fee,
            'final_amount'     => $final,
            'user_description' => $data['user_description'] ?? null,
            'status'           => 'pending',
            'idempotency_key'  => $idempotencyKey,
            'ip_address'       => get_client_ip(),
            'user_agent'       => get_user_agent(),
        ], $withdrawalData));

        $this->increaseDailyLimit($userId);

        $this->audit->record(AuditTrail::WITHDRAWAL_REQUESTED, $userId, [
            'withdrawal_id' => (int)$id,
            'amount'        => $amount,
            'currency'      => $currency,
            'fee'           => $fee,
            'final_amount'  => $final,
            'method'        => $currency === 'IRT' ? 'bank_card' : 'crypto',
        ]);

        return [
            'success'       => true,
            'withdrawal_id' => (int)$id,
            'message'       => 'درخواست برداشت ثبت شد',
        ];
    }

    /**
     * تأیید برداشت توسط ادمین
     */
    public function adminApprove(int $adminId, int $withdrawalId, array $paymentData): array
    {
        try {
            $this->db->beginTransaction();

            $w = $this->db->query(
                "SELECT * FROM withdrawals WHERE id = :id FOR UPDATE",
                ['id' => $withdrawalId]
            )->fetch(\PDO::FETCH_OBJ);

            if (!$w) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'برداشت یافت نشد'];
            }

            if (!in_array($w->status, ['pending', 'processing'], true)) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'قبلاً بررسی شده'];
            }

            $update = [
                'status'       => 'completed',
                'processed_by' => $adminId,
                'processed_at' => date('Y-m-d H:i:s'),
                'admin_note'   => $paymentData['admin_note'] ?? null,
            ];

            if ($w->currency === 'IRT') {
                $update['bank_tracking_code'] = $paymentData['bank_tracking_code'] ?? null;
            } else {
                $update['transaction_hash'] = $paymentData['transaction_hash'] ?? null;
            }

            $this->model->update($withdrawalId, $update);
            $this->wallet->updateLedgerStatusByIdempotency((string)$w->idempotency_key, 'completed');

            $this->db->commit();

            $this->audit->record(AuditTrail::WITHDRAWAL_APPROVED, (int)$w->user_id, [
                'withdrawal_id' => $withdrawalId,
                'amount'        => (float)$w->amount,
                'currency'      => $w->currency,
                'admin_id'      => $adminId,
            ], $adminId);

            $this->notifier->withdrawalApproved((int)$w->user_id, (float)$w->amount, (string)$w->currency);

            return ['success' => true, 'message' => 'برداشت تکمیل شد'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('withdrawal.approve.failed', ['id' => $withdrawalId, 'err' => $e->getMessage()]);
            return ['success' => false, 'message' => 'خطا در تکمیل برداشت'];
        }
    }

    /**
     * رد درخواست برداشت و بازگشت وجه
     */
    public function adminReject(int $adminId, int $withdrawalId, string $reason): array
    {
        try {
            $this->db->beginTransaction();

            $w = $this->db->query(
                "SELECT * FROM withdrawals WHERE id = :id FOR UPDATE",
                ['id' => $withdrawalId]
            )->fetch(\PDO::FETCH_OBJ);

            if (!$w) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'برداشت یافت نشد'];
            }

            if (!in_array($w->status, ['pending', 'processing'], true)) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'قبلاً بررسی شده'];
            }

            $userId   = (int)$w->user_id;
            $amount   = (float)$w->amount;
            $currency = strtolower((string)$w->currency);

            $balanceField = ($currency === 'usdt') ? 'balance_usdt' : 'balance_irt';

            $wallet = $this->db->query(
                "SELECT * FROM wallets WHERE user_id = :user_id FOR UPDATE",
                ['user_id' => $userId]
            )->fetch(\PDO::FETCH_OBJ);

            if (!$wallet) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'کیف پول کاربر یافت نشد'];
            }

            $balanceBefore = (float)$wallet->$balanceField;
            $balanceAfter  = (float)bcadd((string)$balanceBefore, (string)$amount, 2);

            $this->db->query(
                "UPDATE wallets SET {$balanceField} = :balance, updated_at = NOW() WHERE user_id = :user_id",
                ['balance' => $balanceAfter, 'user_id' => $userId]
            );

            $this->db->query(
                "INSERT INTO transactions
                 (user_id, type, currency, amount, balance_before, balance_after,
                  status, description, metadata, created_at)
                 VALUES (:user_id, 'withdrawal_refund', :currency, :amount,
                         :balance_before, :balance_after, 'completed', :description,
                         :metadata, NOW())",
                [
                    'user_id'        => $userId,
                    'currency'       => $currency,
                    'amount'         => $amount,
                    'balance_before' => $balanceBefore,
                    'balance_after'  => $balanceAfter,
                    'description'    => 'بازگشت وجه برداشت رد شده',
                    'metadata'       => json_encode([
                        'withdrawal_id' => $withdrawalId,
                        'reason'        => $reason,
                        'rejected_by'   => $adminId,
                    ], JSON_UNESCAPED_UNICODE),
                ]
            );

            $this->model->update($withdrawalId, [
                'status'       => 'rejected',
                'admin_note'   => $reason,
                'processed_by' => $adminId,
                'processed_at' => date('Y-m-d H:i:s'),
            ]);

            $this->db->commit();

            $this->audit->record(AuditTrail::WITHDRAWAL_REJECTED, $userId, [
                'withdrawal_id' => $withdrawalId,
                'amount'        => $amount,
                'currency'      => $currency,
                'reason'        => $reason,
                'admin_id'      => $adminId,
            ], $adminId);

            $this->notifier->withdrawalRejected($userId, $amount, $reason);

            return ['success' => true, 'message' => 'برداشت رد شد و وجه برگشت داده شد'];

        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->logger->error('withdrawal.reject.failed', [
                'id'  => $withdrawalId,
                'err' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'خطا در رد برداشت'];
        }
    }

    // ─── private helpers ──────────────────────────────────────────

    private function checkDailyLimit(int $userId, int $limit): bool
    {
        return $this->limitModel->checkDailyLimit($userId, $limit);
    }

    private function increaseDailyLimit(int $userId): void
    {
        $this->limitModel->incrementDailyCount($userId);
    }

    private function uuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function recordTransactionStatusChange(
        string $transactionId,
        string $newStatus,
        string $reason,
        int    $changedBy,
        array  $metadata = []
    ): void {
        $this->transactionModel->recordStatusChange(
            $transactionId, $newStatus, $reason, $changedBy, $metadata
        );
    }
}
