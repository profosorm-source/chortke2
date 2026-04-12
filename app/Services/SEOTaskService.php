<?php
// app/Services/SEOTaskService.php

namespace App\Services;

use App\Models\SEOKeyword;
use App\Models\SEOExecution;
use App\Services\WalletService;
use Core\Database;

class SEOTaskService
{
    private \App\Services\ReferralCommissionService $referralCommissionService;
    private Database $db;
    private $seoExecutionModel;
    private $seoKeywordModel;
    private WalletService $walletService;

    public function __construct(Database $db, 
        WalletService $walletService,
        \App\Models\SEOExecution $seoExecutionModel,
        \App\Models\SEOKeyword $seoKeywordModel,
        \App\Services\ReferralCommissionService $referralCommissionService){
        $this->db = $db;
        $this->seoExecutionModel = $seoExecutionModel;
        $this->seoKeywordModel = $seoKeywordModel;
        $this->walletService = $walletService;
        $this->referralCommissionService = $referralCommissionService;
    }

    /**
     * شروع تسک جستجو
     */
    public function start(int $keywordId, int $executorId): array
    {
        $keyword = $this->seoKeywordModel->find($keywordId);
        if (!$keyword || !$keyword->is_active) {
            return ['success' => false, 'message' => 'کلمه کلیدی یافت نشد یا غیرفعال است.'];
        }

        // بررسی تکراری امروز
        if ($this->seoExecutionModel->existsByKeywordAndExecutorToday($keywordId, $executorId)) {
            return ['success' => false, 'message' => 'شما امروز این کلمه را قبلاً جستجو کرده‌اید.'];
        }

        // بررسی محدودیت ساعتی
        $hourlyCount = $this->seoExecutionModel->countByExecutorLastHour($executorId);
        if ($hourlyCount >= $keyword->max_per_hour) {
            return ['success' => false, 'message' => "حداکثر {$keyword->max_per_hour} جستجو در ساعت مجاز است. لطفاً بعداً تلاش کنید."];
        }

        // بررسی محدودیت روزانه
        $dailyCount = $this->seoExecutionModel->countByExecutorToday($executorId);
        if ($dailyCount >= $keyword->max_per_day) {
            return ['success' => false, 'message' => "حداکثر {$keyword->max_per_day} جستجو در روز مجاز است."];
        }

        // بررسی IP
        $ip = get_client_ip();
        $ipHourly = $this->seoExecutionModel->countByIPLastHour($ip);
        if ($ipHourly >= 10) {
            return ['success' => false, 'message' => 'محدودیت IP. لطفاً بعداً تلاش کنید.'];
        }

        // بررسی بودجه روزانه کلمه
        if ($keyword->today_executions >= $keyword->daily_budget) {
            return ['success' => false, 'message' => 'ظرفیت روزانه این کلمه تکمیل شده.'];
        }

        // بررسی Silent Blacklist
        if ($this->isBlacklisted($executorId)) {
            return ['success' => false, 'message' => 'در حال حاضر امکان انجام ندارد.'];
        }

        $execution = $this->seoExecutionModel->create([
            'keyword_id'      => $keywordId,
            'executor_id'     => $executorId,
            'search_query'    => $keyword->keyword,
            'target_url'      => $keyword->target_url,
            'reward_amount'   => $keyword->reward_amount,
            'reward_currency' => $keyword->currency,
            'idempotency_key' => 'seo_' . $keywordId . '_' . $executorId . '_' . \date('Ymd') . '_' . str_random(8),
        ]);

        if (!$execution) {
            return ['success' => false, 'message' => 'خطا در شروع. لطفاً دوباره تلاش کنید.'];
        }

        logger('seo_task', "User {$executorId} started SEO task for keyword #{$keywordId}: {$keyword->keyword}");

        // لینک جستجوی گوگل
        $googleUrl = 'https://www.google.com/search?q=' . \urlencode($keyword->keyword);

        return [
            'success'    => true,
            'message'    => 'تسک شروع شد.',
            'execution'  => $execution,
            'google_url' => $googleUrl,
            'target_url' => $keyword->target_url,
            'config'     => [
                'scroll_min'   => $keyword->scroll_min_seconds,
                'scroll_max'   => $keyword->scroll_max_seconds,
                'pause_min'    => $keyword->pause_min_seconds,
                'pause_max'    => $keyword->pause_max_seconds,
                'total_browse' => $keyword->total_browse_seconds,
            ],
        ];
    }

    /**
     * تکمیل تسک جستجو
     */
    public function complete(int $executionId, int $executorId, array $data): array
    {
        $execution = $this->seoExecutionModel->find($executionId);
        if (!$execution || $execution->executor_id !== $executorId) {
            return ['success' => false, 'message' => 'تسک یافت نشد.'];
        }

        if (!\in_array($execution->status, ['started', 'browsing'])) {
            return ['success' => false, 'message' => 'این تسک قابل تکمیل نیست.'];
        }

        $totalDuration = (int) ($data['total_duration'] ?? 0);
        $scrollDuration = (int) ($data['scroll_duration'] ?? 0);
        $browseDuration = (int) ($data['browse_duration'] ?? 0);

        // بررسی حداقل زمان
        $keyword = $this->seoKeywordModel->find($execution->keyword_id);
        $minTime = $keyword ? $keyword->scroll_min_seconds : 20;

        if ($totalDuration < $minTime) {
            $this->seoExecutionModel->update($executionId, [
                'status'         => 'failed',
                'failure_reason' => 'زمان بسیار کم',
                'fraud_score'    => $execution->fraud_score + 40,
                'total_duration' => $totalDuration,
            ]);
            return ['success' => false, 'message' => 'زمان مرور کافی نبود. تسک رد شد.'];
        }

        // بررسی رفتاری
        $fraudScore = $this->analyzeBehavior($data);

        if ($fraudScore >= 70) {
            $this->seoExecutionModel->update($executionId, [
                'status'         => 'suspicious',
                'failure_reason' => 'رفتار مشکوک',
                'fraud_score'    => $fraudScore,
                'scroll_data'    => isset($data['scroll_data']) ? \json_encode($data['scroll_data']) : null,
                'behavior_data'  => isset($data['behavior_data']) ? \json_encode($data['behavior_data']) : null,
                'total_duration' => $totalDuration,
            ]);
            return ['success' => false, 'message' => 'رفتار مشکوک تشخیص داده شد. تسک رد شد.'];
        }

        try {
            $this->db->beginTransaction();

            // پرداخت پاداش
            $rewardResult = $this->walletService->deposit(
                $executorId,
                $execution->reward_amount,
                $execution->reward_currency,
                'seo_reward',
                'پاداش جستجوی کلمه: ' . $execution->search_query,
                null,
                $execution->idempotency_key
            );

            if (!$rewardResult['success']) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در پرداخت پاداش.'];
            }

            $this->seoExecutionModel->update($executionId, [
                'status'          => 'completed',
                'scroll_duration' => $scrollDuration,
                'browse_duration' => $browseDuration,
                'total_duration'  => $totalDuration,
                'scroll_data'     => isset($data['scroll_data']) ? \json_encode($data['scroll_data']) : null,
                'behavior_data'   => isset($data['behavior_data']) ? \json_encode($data['behavior_data']) : null,
                'reward_paid'     => 1,
                'fraud_score'     => $fraudScore,
                'completed_at'    => \date('Y-m-d H:i:s'),
            ]);

            // بروزرسانی شمارنده کلمه
            if ($keyword) {
                $this->seoKeywordModel->incrementExecution($keyword->id);
            }

            // کمیسیون معرف
            $this->payReferralCommission($execution);

            $this->db->commit();

            logger('seo_task', "User {$executorId} completed SEO task #{$executionId} | Duration: {$totalDuration}s | Reward: {$execution->reward_amount}");

            return [
                'success' => true,
                'message' => 'تسک با موفقیت تکمیل شد! پاداش به کیف پول شما اضافه شد.',
                'reward'  => $execution->reward_amount,
            ];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            logger('seo_task_error', $e->getMessage());
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }
    }

    /**
     * تحلیل رفتاری
     */
    private function analyzeBehavior(array $data): float
    {
        $score = 0;

        // بررسی حرکت موس
        $mouseData = $data['behavior_data']['mouse_movements'] ?? 0;
        $duration = (int) ($data['total_duration'] ?? 1);

        if ($duration > 0) {
            $movementsPerSecond = $mouseData / $duration;

            // بدون حرکت موس
            if ($mouseData < 5 && $duration > 30) {
                $score += 30;
            }

            // حرکت خیلی زیاد و یکنواخت
            if ($movementsPerSecond > 10) {
                $score += 25;
            }
        }

        // بررسی Jitter
        $jitter = (float) ($data['behavior_data']['mouse_jitter'] ?? 0);
        if ($jitter < 0.01 && $mouseData > 20) {
            $score += 20; // حرکت بسیار مستقیم = ربات
        }

        // تعداد تغییر تب
        $tabSwitches = (int) ($data['behavior_data']['tab_switches'] ?? 0);
        if ($tabSwitches > 5) {
            $score += 15;
        }

        // بررسی زمان اسکرول (خیلی یکنواخت)
        $scrollIntervals = $data['scroll_data']['intervals'] ?? [];
        if (\count($scrollIntervals) > 5) {
            $stdDev = $this->calculateStdDev($scrollIntervals);
            if ($stdDev < 0.5) {
                $score += 20; // فاصله‌های یکسان = ربات
            }
        }

        return \min(100, $score);
    }

    /**
     * انحراف معیار
     */
    private function calculateStdDev(array $values): float
    {
        $count = \count($values);
        if ($count < 2) return 0;

        $mean = \array_sum($values) / $count;
        $sqDiffs = \array_map(fn($v) => ($v - $mean) ** 2, $values);

        return \sqrt(\array_sum($sqDiffs) / ($count - 1));
    }

    private function isBlacklisted(int $userId): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT fraud_score FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            return ((float) $stmt->fetchColumn()) >= 80;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function payReferralCommission(SEOExecution $execution): void
    {
        try {
            if (\class_exists(\App\Services\ReferralCommissionService::class)) {
                $ref = $this->referralCommissionService;
                $ref->payCommission($execution->executor_id, $execution->reward_amount, $execution->reward_currency, 'seo_reward', $execution->id);
            }
        } catch (\Throwable $e) {
            logger('referral_error', $e->getMessage());
        }
    }
}