<?php
// app/Services/InvestmentService.php

namespace App\Services;

use App\Models\Investment;
use App\Models\TradingRecord;
use App\Models\InvestmentProfit;
use App\Models\InvestmentWithdrawal;
use Core\Database;

class InvestmentService
{
    private Database             $db;
    private WalletService        $walletService;
    private NotificationService  $notificationService;
    private Investment           $investmentModel;
    private TradingRecord        $tradingModel;
    private InvestmentProfit     $profitModel;
    private InvestmentWithdrawal $withdrawalModel;
    private AuditTrail           $audit;

    private const RISK_WARNING = <<<EOT
⚠️ هشدار ریسک سرمایه‌گذاری

سرمایه‌گذاری در بازارهای مالی (فارکس/طلا) دارای ریسک بالایی است.

۱. احتمال ضرر تا ۱۰۰٪ سرمایه وجود دارد.
۲. سیستم هیچ تضمینی برای سودآوری نمی‌دهد.
۳. عملکرد گذشته تضمینی برای آینده نیست.
۴. فقط پولی را سرمایه‌گذاری کنید که توان از دست دادن آن را دارید.
۵. مسئولیت کامل سرمایه‌گذاری با شما است.

با تأیید، اعلام می‌کنید که این ریسک‌ها را درک کرده و می‌پذیرید.
EOT;

    public function __construct(
        Database             $db,
        WalletService        $walletService,
        NotificationService  $notificationService,
        \App\Models\Investment           $investmentModel,
        \App\Models\TradingRecord        $tradingModel,
        \App\Models\InvestmentProfit     $profitModel,
        \App\Models\InvestmentWithdrawal $withdrawalModel,
        AuditTrail           $audit
    ) {
        $this->db                  = $db;
        $this->investmentModel     = $investmentModel;
        $this->tradingModel        = $tradingModel;
        $this->profitModel         = $profitModel;
        $this->withdrawalModel     = $withdrawalModel;
        $this->walletService       = $walletService;
        $this->notificationService = $notificationService;
        $this->audit               = $audit;
    }

    /**
     * ثبت سرمایه‌گذاری جدید
     */
    public function createInvestment(int $userId, array $data): array
    {
        $amount = (float)($data['amount'] ?? 0);

        $balance = $this->walletService->getBalance($userId, 'usdt');
        if ($balance < $amount) {
            return ['success' => false, 'message' => 'موجودی تتری کیف پول شما کافی نیست'];
        }

        $this->db->beginTransaction();

        try {
            $withdrawResult = $this->walletService->withdraw(
                $userId,
                $amount,
                'usdt',
                [
                    'type'        => 'investment_deposit',
                    'description' => 'سرمایه‌گذاری جدید',
                ]
            );

            if (!$withdrawResult['success']) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در کسر موجودی: ' . ($withdrawResult['message'] ?? '')];
            }

            $investmentId = $this->investmentModel->create([
                'user_id'                    => $userId,
                'amount'                     => $amount,
                'current_balance'            => $amount,
                'status'                     => Investment::STATUS_ACTIVE,
                'risk_accepted_ip'           => get_client_ip(),
                'risk_accepted_fingerprint'  => generate_device_fingerprint(),
                'risk_accepted_at'           => date('Y-m-d H:i:s'),
            ]);

            if (!$investmentId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ثبت سرمایه‌گذاری'];
            }

            $this->db->commit();

            $this->audit->record(AuditTrail::INVESTMENT_CREATED, $userId, [
                'investment_id' => $investmentId,
                'amount'        => $amount,
                'currency'      => 'usdt',
            ]);

            $this->notify($userId, 'سرمایه‌گذاری ثبت شد',
                "سرمایه‌گذاری شما به مبلغ {$amount} تتر با موفقیت ثبت شد", 'investment_created');

            logger('investment_created', "User {$userId} invested {$amount} USDT, ID: {$investmentId}", 'info');

            return [
                'success'       => true,
                'message'       => "سرمایه‌گذاری {$amount} تتر با موفقیت ثبت شد",
                'investment_id' => $investmentId,
            ];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            logger('investment_error', "Error: " . $e->getMessage(), 'error');
            return ['success' => false, 'message' => 'خطای سیستمی. لطفاً دوباره تلاش کنید'];
        }
    }

    /**
     * ثبت ترید جدید (ادمین)
     */
    public function createTrade(int $adminId, array $data): array
    {
        $tradeId = $this->tradingModel->create([
            'admin_id'            => $adminId,
            'trade_type'          => $data['trade_type'] ?? 'gold',
            'direction'           => $data['direction'],
            'pair'                => $data['pair'] ?? 'XAUUSD',
            'open_price'          => $data['open_price'],
            'close_price'         => $data['close_price'] ?? null,
            'open_time'           => $data['open_time'],
            'close_time'          => $data['close_time'] ?? null,
            'lot_size'            => $data['lot_size'] ?? 0,
            'stop_loss'           => $data['stop_loss'] ?? null,
            'take_profit'         => $data['take_profit'] ?? null,
            'profit_loss_percent' => $data['profit_loss_percent'] ?? 0,
            'profit_loss_amount'  => $data['profit_loss_amount'] ?? 0,
            'status'              => !empty($data['close_time']) ? TradingRecord::STATUS_CLOSED : TradingRecord::STATUS_OPEN,
            'notes'               => $data['notes'] ?? null,
            'screenshot_path'     => $data['screenshot_path'] ?? null,
        ]);

        if (!$tradeId) {
            return ['success' => false, 'message' => 'خطا در ثبت ترید.'];
        }

        $this->audit->record(AuditTrail::ADMIN_SETTINGS_CHANGED, null, [
            'action'   => 'trade_created',
            'trade_id' => $tradeId,
            'admin_id' => $adminId,
        ], $adminId);

        logger('trade_created', "Admin {$adminId} created trade #{$tradeId}", 'info');

        return ['success' => true, 'message' => 'ترید با موفقیت ثبت شد.', 'trade_id' => $tradeId];
    }

    /**
     * بستن ترید (ادمین)
     */
    public function closeTrade(int $tradeId, int $adminId, array $data): array
    {
        $trade = $this->tradingModel->find($tradeId);
        if (!$trade) {
            return ['success' => false, 'message' => 'ترید یافت نشد.'];
        }
        if ($trade->status !== TradingRecord::STATUS_OPEN) {
            return ['success' => false, 'message' => 'فقط تریدهای باز قابل بستن هستند.'];
        }

        $this->tradingModel->update($tradeId, [
            'close_price'         => $data['close_price'],
            'close_time'          => $data['close_time'] ?? date('Y-m-d H:i:s'),
            'profit_loss_percent' => $data['profit_loss_percent'],
            'profit_loss_amount'  => $data['profit_loss_amount'],
            'status'              => $data['status'] ?? TradingRecord::STATUS_CLOSED,
            'notes'               => $data['notes'] ?? $trade->notes,
        ]);

        logger('trade_closed', "Admin {$adminId} closed trade #{$tradeId}", 'info');

        return ['success' => true, 'message' => 'ترید بسته شد.'];
    }

    /**
     * اعمال سود/ضرر هفتگی بر تمام سرمایه‌گذاری‌های فعال (ادمین)
     */
    public function applyWeeklyProfitLoss(int $adminId, int $tradingRecordId, float $profitLossPercent, string $period): array
    {
        $trade = $this->tradingModel->find($tradingRecordId);
        if (!$trade) {
            return ['success' => false, 'message' => 'رکورد ترید یافت نشد.'];
        }

        $activeInvestments = $this->investmentModel->getAll(['status' => Investment::STATUS_ACTIVE], 10000, 0);

        if (empty($activeInvestments)) {
            return ['success' => false, 'message' => 'سرمایه‌گذاری فعالی یافت نشد.'];
        }

        $siteFeePercent = (float)setting('investment_site_fee_percent', 10);
        $taxPercent     = (float)setting('investment_tax_percent', 9);
        $count          = 0;

        foreach ($activeInvestments as $inv) {
            $this->db->beginTransaction();
            try {
                $investAmount     = (float)$inv->current_balance;
                $profitLossAmount = round($investAmount * ($profitLossPercent / 100), 2);
                $isProfit         = $profitLossAmount >= 0;

                $siteFee   = 0;
                $taxAmount = 0;
                $netAmount = $profitLossAmount;

                if ($isProfit && $profitLossAmount > 0) {
                    $siteFee   = round($profitLossAmount * ($siteFeePercent / 100), 2);
                    $afterFee  = $profitLossAmount - $siteFee;
                    $taxAmount = round($afterFee * ($taxPercent / 100), 2);
                    $netAmount = round($afterFee - $taxAmount, 2);
                }

                $balanceBefore = $investAmount;
                $balanceAfter  = round($investAmount + $netAmount, 2);

                $this->profitModel->create([
                    'investment_id'       => $inv->id,
                    'user_id'             => $inv->user_id,
                    'trading_record_id'   => $tradingRecordId,
                    'period'              => $period,
                    'investment_amount'   => $investAmount,
                    'profit_loss_percent' => $profitLossPercent,
                    'profit_loss_amount'  => $profitLossAmount,
                    'site_fee_percent'    => $isProfit ? $siteFeePercent : 0,
                    'site_fee_amount'     => $siteFee,
                    'tax_percent'         => $isProfit ? $taxPercent : 0,
                    'tax_amount'          => $taxAmount,
                    'net_amount'          => $netAmount,
                    'balance_before'      => $balanceBefore,
                    'balance_after'       => $balanceAfter,
                    'type'                => $isProfit ? 'profit' : 'loss',
                ]);

                $updateData = [
                    'current_balance'  => $balanceAfter,
                    'last_profit_date' => date('Y-m-d H:i:s'),
                ];

                if ($isProfit) {
                    $updateData['total_profit'] = (float)$inv->total_profit + $netAmount;
                } else {
                    $updateData['total_loss'] = (float)$inv->total_loss + abs($netAmount);
                }

                if ($balanceAfter <= 0) {
                    $updateData['current_balance'] = 0;
                    $updateData['status']          = Investment::STATUS_FROZEN;
                }

                $this->investmentModel->update($inv->id, $updateData);

                $this->audit->record(AuditTrail::INVESTMENT_PROFIT_APPLIED, (int)$inv->user_id, [
                    'investment_id'       => $inv->id,
                    'period'              => $period,
                    'profit_loss_percent' => $profitLossPercent,
                    'net_amount'          => $netAmount,
                    'balance_before'      => $balanceBefore,
                    'balance_after'       => $balanceAfter,
                    'admin_id'            => $adminId,
                ], $adminId);

                $typeLabel       = $isProfit ? 'سود' : 'ضرر';
                $amountFormatted = number_format(abs($netAmount), 2);
                $this->notify($inv->user_id,
                    "گزارش هفتگی سرمایه‌گذاری",
                    "دوره {$period}: {$typeLabel} {$amountFormatted} تتر | موجودی جدید: " . number_format($balanceAfter, 2) . " تتر",
                    'investment_profit'
                );

                $this->db->commit();
                $count++;

            } catch (\Throwable $e) {
                $this->db->rollBack();
                logger('investment_profit_error', "Error for investment #{$inv->id}: " . $e->getMessage(), 'error');
            }
        }

        logger('investment_weekly_apply', "Admin {$adminId} applied {$profitLossPercent}% for {$period}, affected: {$count}", 'info');

        return ['success' => true, 'message' => "سود/ضرر هفتگی برای {$count} سرمایه‌گذار اعمال شد."];
    }

    /**
     * درخواست برداشت سود
     */
    public function requestWithdrawal(int $userId, array $data): array
    {
        $investment = $this->investmentModel->getActiveByUser($userId);
        if (!$investment) {
            return ['success' => false, 'message' => 'سرمایه‌گذاری فعالی ندارید.'];
        }

        $canWithdraw = $this->investmentModel->canWithdraw($userId);
        if (!$canWithdraw['allowed']) {
            return ['success' => false, 'message' => $canWithdraw['reason']];
        }

        if ($this->withdrawalModel->hasPending($userId)) {
            return ['success' => false, 'message' => 'شما یک درخواست برداشت در حال بررسی دارید.'];
        }

        $withdrawType   = $data['withdrawal_type'] ?? InvestmentWithdrawal::TYPE_PROFIT_ONLY;
        $currentBalance = (float)$investment->current_balance;
        $originalAmount = (float)$investment->amount;

        if ($withdrawType === InvestmentWithdrawal::TYPE_PROFIT_ONLY) {
            $profit = $currentBalance - $originalAmount;
            if ($profit <= 0) {
                return ['success' => false, 'message' => 'سودی برای برداشت وجود ندارد. موجودی فعلی کمتر یا برابر سرمایه اولیه است.'];
            }
            $amount = $profit;
        } else {
            $amount = $currentBalance;
        }

        $idempotencyKey = hash('sha256', "inv_withdraw_{$userId}_{$investment->id}_" . time());

        $withdrawalId = $this->withdrawalModel->create([
            'investment_id'   => $investment->id,
            'user_id'         => $userId,
            'amount'          => $amount,
            'withdrawal_type' => $withdrawType,
            'balance_before'  => $currentBalance,
            'balance_after'   => $currentBalance - $amount,
            'idempotency_key' => $idempotencyKey,
        ]);

        if (!$withdrawalId) {
            return ['success' => false, 'message' => 'خطا در ثبت درخواست برداشت.'];
        }

        $this->notify(0, 'درخواست برداشت سرمایه‌گذاری',
            "کاربر #{$userId} درخواست برداشت " . number_format($amount, 2) . " تتر از سرمایه‌گذاری داده است.",
            'investment_withdrawal_request');

        logger('investment_withdrawal_request', "User {$userId} requested withdrawal {$amount} USDT from investment #{$investment->id}", 'info');

        return [
            'success'       => true,
            'message'       => 'درخواست برداشت ثبت شد و پس از بررسی مدیریت پرداخت خواهد شد.',
            'withdrawal_id' => $withdrawalId,
        ];
    }

    /**
     * تأیید و پرداخت برداشت (ادمین)
     */
    public function approveWithdrawal(int $withdrawalId, int $adminId): array
    {
        $withdrawal = $this->withdrawalModel->findWithDetails($withdrawalId);

        if (!$withdrawal) {
            return ['success' => false, 'message' => 'درخواست یافت نشد'];
        }

        if ($withdrawal->status !== InvestmentWithdrawal::STATUS_PENDING) {
            return ['success' => false, 'message' => 'فقط درخواست‌های در انتظار قابل تأیید هستند'];
        }

        $investment = $this->investmentModel->find($withdrawal->investment_id);

        if (!$investment) {
            return ['success' => false, 'message' => 'سرمایه‌گذاری یافت نشد'];
        }

        $this->db->beginTransaction();

        try {
            $depositResult = $this->walletService->deposit(
                (int)$withdrawal->user_id,
                (float)$withdrawal->amount,
                'usdt',
                [
                    'type'          => 'investment_withdrawal',
                    'investment_id' => $investment->id,
                    'withdrawal_id' => $withdrawalId,
                    'description'   => 'برداشت سود سرمایه‌گذاری',
                ]
            );

            if (!$depositResult['success']) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در واریز: ' . ($depositResult['message'] ?? '')];
            }

            $this->withdrawalModel->update($withdrawalId, [
                'status'         => InvestmentWithdrawal::STATUS_COMPLETED,
                'approved_at'    => date('Y-m-d H:i:s'),
                'completed_at'   => date('Y-m-d H:i:s'),
                'transaction_id' => $depositResult['transaction_id'] ?? null,
            ]);

            $newBalance  = (float)$investment->current_balance - $withdrawal->amount;
            $investUpdate = [
                'current_balance'      => max(0, $newBalance),
                'last_withdrawal_date' => date('Y-m-d H:i:s'),
                'deposit_lock_until'   => date('Y-m-d H:i:s', time() + (Investment::DEPOSIT_LOCK_DAYS * 86400)),
            ];

            if ($withdrawal->withdrawal_type === InvestmentWithdrawal::TYPE_FULL_CLOSE) {
                $investUpdate['status']          = Investment::STATUS_CLOSED;
                $investUpdate['current_balance'] = 0;
            }

            $this->investmentModel->update($investment->id, $investUpdate);

            $this->db->commit();

            $this->audit->record(AuditTrail::INVESTMENT_CLOSED, (int)$withdrawal->user_id, [
                'withdrawal_id'   => $withdrawalId,
                'investment_id'   => $investment->id,
                'amount'          => (float)$withdrawal->amount,
                'withdrawal_type' => $withdrawal->withdrawal_type,
                'admin_id'        => $adminId,
            ], $adminId);

            $this->notify($withdrawal->user_id, 'برداشت سرمایه‌گذاری تأیید شد',
                "مبلغ " . number_format($withdrawal->amount, 2) . " تتر به کیف پول شما واریز شد",
                'investment_withdrawal_approved');

            logger('investment_withdrawal_approved', "Admin {$adminId} approved withdrawal #{$withdrawalId}", 'info');

            return ['success' => true, 'message' => 'برداشت تأیید و واریز شد'];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            logger('investment_withdrawal_error', "Error: " . $e->getMessage(), 'error');
            return ['success' => false, 'message' => 'خطای سیستمی'];
        }
    }

    /**
     * رد درخواست برداشت (ادمین)
     */
    public function rejectWithdrawal(int $withdrawalId, int $adminId, string $reason): array
    {
        $withdrawal = $this->withdrawalModel->find($withdrawalId);
        if (!$withdrawal || $withdrawal->status !== InvestmentWithdrawal::STATUS_PENDING) {
            return ['success' => false, 'message' => 'درخواست معتبر نیست.'];
        }

        $this->withdrawalModel->update($withdrawalId, [
            'status'           => InvestmentWithdrawal::STATUS_REJECTED,
            'rejection_reason' => $reason,
        ]);

        $this->audit->record(AuditTrail::INVESTMENT_CLOSED, (int)$withdrawal->user_id, [
            'action'        => 'withdrawal_rejected',
            'withdrawal_id' => $withdrawalId,
            'reason'        => $reason,
            'admin_id'      => $adminId,
        ], $adminId);

        $this->notify($withdrawal->user_id, 'درخواست برداشت رد شد',
            "دلیل: {$reason}", 'investment_withdrawal_rejected');

        logger('investment_withdrawal_rejected', "Admin {$adminId} rejected withdrawal #{$withdrawalId}", 'info');

        return ['success' => true, 'message' => 'درخواست رد شد.'];
    }

    public function getRiskWarning(): string
    {
        return self::RISK_WARNING;
    }

    public function getSettings(): array
    {
        return [
            'min_amount'          => (float)setting('investment_min_amount', 10),
            'max_amount'          => (float)setting('investment_max_amount', 10000),
            'site_fee_percent'    => (float)setting('investment_site_fee_percent', 10),
            'tax_percent'         => (float)setting('investment_tax_percent', 9),
            'withdrawal_cooldown' => Investment::WITHDRAWAL_COOLDOWN_DAYS,
            'deposit_lock'        => Investment::DEPOSIT_LOCK_DAYS,
        ];
    }

    private function notify(int $userId, string $title, string $message, string $type): void
    {
        try {
            $this->notificationService->send($userId, $type, $title, $message);
        } catch (\Throwable $e) {
            logger('notification_error', $e->getMessage(), 'error');
        }
    }
}
