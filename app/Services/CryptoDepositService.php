<?php

namespace App\Services;

use Core\Database;
use App\Models\CryptoDepositIntent;
use App\Models\CryptoDeposit;

class CryptoDepositService
{
    private \App\Models\CryptoDepositIntent $cryptoDepositIntentModel;
    private \App\Models\CryptoDeposit $cryptoDepositModel;
    private Database $db;
    private CryptoDepositIntent $intentModel;
    private CryptoDeposit $depositModel;
    private NotificationService $notifier;
    private WalletService $wallet;

    public function __construct(Database $db, 
        WalletService $walletService,
        NotificationService $notificationService,
        \App\Models\CryptoDepositIntent $intentModel,
        \App\Models\CryptoDeposit $depositModel,
        \App\Models\CryptoDeposit $cryptoDepositModel,
        \App\Models\CryptoDepositIntent $cryptoDepositIntentModel){
        $this->db = $db;
        $this->intentModel = $intentModel;
        $this->depositModel = $depositModel;
        $this->notifier = $notificationService;
        $this->wallet = $walletService;
        $this->cryptoDepositModel = $cryptoDepositModel;
        $this->cryptoDepositIntentModel = $cryptoDepositIntentModel;
    }


     public function createIntent(int $userId, string $network, float $requestedAmount): array
    {
        $expireMinutes = (int) setting('crypto_intent_expire_minutes', 30);

        $open = $this->intentModel->getOpenIntentForUser($userId);
        if ($open) {
            return [
                'success' => true,
                'message' => 'شما یک درخواست فعال دارید',
                'intent' => $open,
            ];
        }

        $toWallet = $this->getSiteWallet($network);
        if (!$toWallet) {
            return ['success' => false, 'message' => 'ولت این شبکه تنظیم نشده است'];
        }

        $expected = $this->generateUniqueAmount($network, $requestedAmount);
        $expiresAt = \date('Y-m-d H:i:s', \time() + ($expireMinutes * 60));

        $id = ($this->cryptoDepositIntentModel)->create([
            'user_id' => $userId,
            'network' => $network,
            'requested_amount' => $requestedAmount,
            'expected_amount' => $expected,
            'to_wallet' => $toWallet,
            'expires_at' => $expiresAt,
            'status' => 'open',
            'ip_address' => get_client_ip(),
            'user_agent' => get_user_agent(),
            'created_at' => \date('Y-m-d H:i:s'),
            'updated_at' => \date('Y-m-d H:i:s'),
        ]);

        return [
            'success' => true,
            'message' => 'Intent ساخته شد',
            'intent_id' => (int) $id,
            'network' => $network,
            'requested_amount' => $requestedAmount,
            'expected_amount' => $expected,
            'to_wallet' => $toWallet,
            'expires_at' => $expiresAt,
        ];
    }

    public function submitTx(int $userId, int $intentId, string $txHash, string $fromWallet): array
    {
        $txHash = \trim($txHash);
        $fromWallet = \trim($fromWallet);

        // intent را مستقیم از DB بگیریم (بدون chainهای نامطمئن)
        $stmt = $this->db->prepare("SELECT * FROM crypto_deposit_intents WHERE id=:id AND user_id=:uid LIMIT 1");
        $stmt->execute(['id' => $intentId, 'uid' => $userId]);
        $intent = $stmt->fetch(\PDO::FETCH_OBJ);

        if (!$intent || (string)$intent->status !== 'open') {
            return ['success' => false, 'message' => 'Intent نامعتبر است'];
        }

        if (\strtotime((string)$intent->expires_at) < \time()) {
            $this->intentModel->expireIfPassed((int)$intent->id);
            return ['success' => false, 'message' => 'مهلت Intent تمام شده است. Intent جدید بسازید'];
        }

        $dup = $this->depositModel->findByHash($txHash);
        if ($dup) {
            return ['success' => false, 'message' => 'این هش قبلاً ثبت شده است'];
        }

        $slaHours = (int) setting('crypto_manual_review_sla_hours', 6);
        $manualDeadline = \date('Y-m-d H:i:s', \time() + ($slaHours * 3600));

        $depositId = ($this->cryptoDepositModel)->create([
            'user_id' => $userId,
            'intent_id' => (int)$intent->id,
            'network' => (string)$intent->network,
            'amount' => (float)$intent->expected_amount,
            'tx_hash' => $txHash,
            'from_wallet' => $fromWallet,
            'to_wallet' => (string)$intent->to_wallet,
            'user_submitted_at' => \date('Y-m-d H:i:s'),
            'auto_check_deadline' => (string)$intent->expires_at,
            'manual_review_deadline' => $manualDeadline,
            'verification_status' => 'pending',
            'explorer_url' => $this->buildExplorerUrl((string)$intent->network, $txHash),
            'ip_address' => get_client_ip(),
            'user_agent' => get_user_agent(),
            'created_at' => \date('Y-m-d H:i:s'),
            'updated_at' => \date('Y-m-d H:i:s'),
        ]);

        // intent -> claimed
        $stmt2 = $this->db->prepare("UPDATE crypto_deposit_intents SET status='claimed', claimed_at=NOW(), updated_at=NOW() WHERE id=:id");
        $stmt2->execute(['id' => (int)$intent->id]);

        // فعلاً best-effort: مستقیم manual review (تا verifier کامل شود)
        return [
            'success' => true,
            'deposit_id' => (int)$depositId,
            'auto' => false,
            'manual_review' => true,
            'message' => 'درخواست ثبت شد و برای بررسی ارسال شد',
        ];
    }

    public function tryAutoVerify(int $depositId): array
    {
        $d = $this->depositModel->find($depositId);
        if (!$d) return ['auto' => false, 'message' => 'واریز یافت نشد'];

        // فقط داخل پنجره 30 دقیقه
        if ($d->auto_check_deadline && strtotime((string)$d->auto_check_deadline) < time()) {
            // اگر هنوز pending است => reject timeout
            if ($d->verification_status === 'pending') {
                $this->depositModel->update($depositId, [
                    'verification_status' => 'rejected',
                    'mismatch_reason' => 'مهلت بررسی خودکار (۳۰ دقیقه) تمام شد',
                    'risk_score' => 20,
                    'reviewed_at' => date('Y-m-d H:i:s'),
                ]);
                return ['auto' => false, 'message' => 'رد شد (پایان مهلت ۳۰ دقیقه)'];
            }
        }

        // افزایش attempts
        $this->depositModel->update($depositId, [
            'auto_check_attempts' => (int)$d->auto_check_attempts + 1
        ]);

        $verifier = new CryptoExplorerBestEffortVerifier();
        $result = $verifier->verify((string)$d->network, (string)$d->tx_hash, (string)$d->from_wallet, (string)$d->to_wallet, (float)$d->amount);

        if (($result['status'] ?? '') === 'verified') {
            $ok = $this->wallet->deposit((int)$d->user_id, 'USDT', (float)$d->amount, 'deposit', [
                'type' => 'crypto_deposit',
                'deposit_id' => $depositId,
                'network' => (string)$d->network,
                'tx_hash' => (string)$d->tx_hash,
                'description' => 'واریز تتر (تأیید خودکار)'
            ]);

            if (!$ok) {
                return $this->moveToManualReview($depositId, 'تأیید شد اما شارژ کیف پول با خطا مواجه شد');
            }

            $this->depositModel->update($depositId, [
                'verification_status' => 'auto_verified',
                'reviewed_at' => date('Y-m-d H:i:s'),
                'mismatch_reason' => null,
                'risk_score' => 0
            ]);

            $this->notifier->depositSuccess((int)$d->user_id, (float)$d->amount, 'USDT');

            return ['auto' => true, 'message' => 'تراکنش تأیید شد و کیف پول شارژ شد'];
        }

        if (($result['status'] ?? '') === 'mismatch') {
            $reason = $result['reason'] ?? 'عدم تطابق اطلاعات';
            $this->depositModel->update($depositId, [
                'verification_status' => 'rejected',
                'mismatch_reason' => $reason,
                'risk_score' => 70,
                'reviewed_at' => date('Y-m-d H:i:s'),
            ]);

            $this->notifier->send((int)$d->user_id, \App\Models\Notification::TYPE_SECURITY,
                'هشدار مالی',
                'اطلاعات تراکنش ارسالی شما با داده‌های موجود مطابقت نداشت و درخواست رد شد.',
                ['deposit_id' => $depositId, 'reason' => $reason],
                url('/wallet/crypto-deposit/history'),
                'مشاهده',
                'urgent'
            );

            return ['auto' => false, 'message' => 'رد شد: عدم تطابق'];
        }

        // unavailable/not_found => طبق تصمیم شما: همان لحظه manual_review
        return $this->moveToManualReview($depositId, $result['reason'] ?? 'سیستم نتوانست تراکنش را رهگیری کند');
    }

    public function moveToManualReview(int $depositId, string $reason): array
    {
        $d = $this->depositModel->find($depositId);
        if (!$d) return ['success' => false, 'message' => 'واریز یافت نشد'];

        $this->depositModel->update($depositId, [
            'verification_status' => 'manual_review',
            'mismatch_reason' => $reason,
            'risk_score' => max((int)$d->risk_score, 30),
        ]);

        // پیام به کاربر
        $this->notifier->send((int)$d->user_id, \App\Models\Notification::TYPE_DEPOSIT,
            'ارجاع واریز به مدیریت',
            'سیستم نتوانست تراکنش شما را رهگیری کند و به مدیریت ارجاع داده شد. لطفاً ۲۴ تا ۴۸ ساعت صبر کنید.',
            ['deposit_id' => $depositId, 'explorer_url' => (string)$d->explorer_url],
            url('/wallet/crypto-deposit/history'),
            'مشاهده وضعیت',
            'high'
        );

        return ['auto' => false, 'manual_review' => true, 'message' => 'به مدیریت ارجاع شد'];
    }

    public function adminApprove(int $adminId, int $depositId, ?string $note): array
    {
        $d = $this->depositModel->find($depositId);
        if (!$d) return ['success'=>false,'message'=>'واریز یافت نشد'];

        if (in_array((string)$d->verification_status, ['approved','auto_verified'], true)) {
            return ['success'=>false,'message'=>'قبلاً تأیید شده است'];
        }

        $ok = $this->wallet->deposit((int)$d->user_id, 'USDT', (float)$d->amount, 'deposit', [
            'type' => 'crypto_deposit',
            'deposit_id' => $depositId,
            'network' => (string)$d->network,
            'tx_hash' => (string)$d->tx_hash,
            'description' => 'واریز تتر (تأیید دستی)'
        ]);

        if (!$ok) return ['success'=>false,'message'=>'خطا در شارژ کیف پول'];

        $this->depositModel->update($depositId, [
            'verification_status' => 'approved',
            'admin_note' => $note,
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);

        $this->notifier->depositSuccess((int)$d->user_id, (float)$d->amount, 'USDT');

        return ['success'=>true,'message'=>'تأیید شد و شارژ انجام شد'];
    }

    public function adminReject(int $adminId, int $depositId, string $reason): array
    {
        $d = $this->depositModel->find($depositId);
        if (!$d) return ['success'=>false,'message'=>'واریز یافت نشد'];

        if (in_array((string)$d->verification_status, ['approved','auto_verified'], true)) {
            return ['success'=>false,'message'=>'قبلاً تأیید شده است'];
        }

        $this->depositModel->update($depositId, [
            'verification_status' => 'rejected',
            'admin_note' => $reason,
            'mismatch_reason' => $reason,
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
        ]);

        $this->notifier->send((int)$d->user_id, \App\Models\Notification::TYPE_DEPOSIT,
            'واریز تتر رد شد',
            'درخواست واریز تتر شما رد شد. دلیل: ' . $reason,
            ['deposit_id' => $depositId, 'reason'=>$reason],
            url('/wallet/crypto-deposit/history'),
            'مشاهده',
            'high'
        );

        return ['success'=>true,'message'=>'رد شد'];
    }

    public function expireOpenIntents(): int
    {
        $now = date('Y-m-d H:i:s');

        // intentهای open که expire شدند
        return $this->db->table('crypto_deposit_intents')
            ->where('status', 'open')
            ->where('expires_at', '<', $now)
            ->update([
                'status' => 'expired',
                'updated_at' => $now,
            ]);
    }

    public function rejectExpiredPendingDeposits(): int
    {
        $now = date('Y-m-d H:i:s');

        // واریزهایی که هنوز pending هستند و deadline گذشته => reject
        return $this->db->table('crypto_deposits')
            ->where('verification_status', 'pending')
            ->whereNotNull('auto_check_deadline')
            ->where('auto_check_deadline', '<', $now)
            ->update([
                'verification_status' => 'rejected',
                'mismatch_reason' => 'مهلت بررسی خودکار (۳۰ دقیقه) تمام شد',
                'reviewed_at' => $now,
                'updated_at' => $now,
            ]);
    }

   public function buildExplorerUrl(string $network, string $txHash): string
    {
        $map = [
            'TRC20' => 'https://tronscan.org/#/transaction/',
            'BNB20' => 'https://bscscan.com/tx/',
            'ERC20' => 'https://etherscan.io/tx/',
            'TON'   => 'https://tonscan.org/tx/',
            'SOL'   => 'https://explorer.solana.com/tx/',
        ];
        return ($map[$network] ?? '#') . $txHash;
    }

     private function getSiteWallet(string $network): ?string
    {
        $map = [
            'TRC20' => 'site_wallet_trc20',
            'BNB20' => 'site_wallet_bnb20',
            'ERC20' => 'site_wallet_erc20',
            'TON'   => 'site_wallet_ton',
            'SOL'   => 'site_wallet_sol',
        ];

        $key = $map[$network] ?? null;
        if (!$key) return null;

        $val = (string) setting($key, '');
        return $val !== '' ? $val : null;
    }

    private function generateUniqueAmount(string $network, float $requested): float
    {
        $precisions = [2, 4, 6];

        foreach ($precisions as $p) {
            for ($i = 0; $i < 50; $i++) {
                $max = (10 ** $p) - 1;
                $rand = \mt_rand(1, $max);
                $suffix = $rand / (10 ** $p);
                $expected = \round($requested + $suffix, $p);

                $stmt = $this->db->prepare("SELECT id FROM crypto_deposit_intents WHERE network=:n AND status='open' AND expected_amount=:a LIMIT 1");
                $stmt->execute(['n' => $network, 'a' => $expected]);

                if (!$stmt->fetch(\PDO::FETCH_OBJ)) {
                    return (float)$expected;
                }
            }
        }

        return (float)\round($requested + (\mt_rand(1, 999999) / 1000000), 6);
    }
}