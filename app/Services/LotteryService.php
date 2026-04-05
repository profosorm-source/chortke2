<?php
// app/Services/LotteryService.php

namespace App\Services;

use App\Models\LotteryRound;
use App\Models\LotteryParticipation;
use App\Models\LotteryDailyNumber;
use App\Models\LotteryVote;
use App\Models\LotteryChanceLog;
use Core\Database;

class LotteryService
{
    private Database $db;
    private WalletService $walletService;
    private NotificationService $notificationService;
    private LotteryRound $roundModel;
    private LotteryParticipation $participationModel;
    private LotteryDailyNumber $dailyModel;
    private LotteryVote $voteModel;
    private LotteryChanceLog $chanceLogModel;

    // ابهام ساختاری — نوع بررسی هر روز تصادفی
    private const MATCH_TYPES = ['value', 'position', 'value_position', 'signal'];

    public function __construct(Database $db, 
        WalletService $walletService,
        NotificationService $notificationService,
        \App\Models\LotteryRound $roundModel,
        \App\Models\LotteryParticipation $participationModel,
        \App\Models\LotteryDailyNumber $dailyModel,
        \App\Models\LotteryVote $voteModel,
        \App\Models\LotteryChanceLog $chanceLogModel){
        $this->db = $db;
        $this->roundModel = $roundModel;
        $this->participationModel = $participationModel;
        $this->dailyModel = $dailyModel;
        $this->voteModel = $voteModel;
        $this->chanceLogModel = $chanceLogModel;
        $this->walletService      = $walletService;
        $this->notificationService = $notificationService;
    }

    /**
     * ایجاد دوره جدید (ادمین)
     */
    public function createRound(int $adminId, array $data): array
    {
        $activeRound = $this->roundModel->getActiveRound();
        if ($activeRound) {
            return ['success' => false, 'message' => 'یک دوره فعال وجود دارد. ابتدا آن را ببندید.'];
        }

        $roundId = $this->roundModel->create([
            'title' => $data['title'],
            'type' => $data['type'] ?? LotteryRound::TYPE_WEEKLY,
            'entry_fee' => (float)($data['entry_fee'] ?? 0),
            'currency' => $data['currency'] ?? 'irt',
            'prize_amount' => (float)($data['prize_amount'] ?? 0),
            'prize_description' => $data['prize_description'] ?? null,
            'duration_days' => (int)($data['duration_days'] ?? 7),
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'status' => LotteryRound::STATUS_ACTIVE,
        ]);

        if (!$roundId) {
            return ['success' => false, 'message' => 'خطا در ایجاد دوره.'];
        }

        logger('lottery_round_created', "Admin {$adminId} created round #{$roundId}", 'info');

        return ['success' => true, 'message' => 'دوره قرعه‌کشی ایجاد شد.', 'round_id' => $roundId];
    }

    /**
     * شرکت در قرعه‌کشی
     */
    public function participate(int $userId, int $roundId): array
    {
        if (!feature('lottery_enabled')) {
            return ['success' => false, 'message' => 'سیستم قرعه‌کشی موقتاً غیرفعال است.'];
        }

        $round = $this->roundModel->find($roundId);
        if (!$round || $round->status !== LotteryRound::STATUS_ACTIVE) {
            return ['success' => false, 'message' => 'دوره قرعه‌کشی فعال نیست.'];
        }

        if ($this->participationModel->isParticipating($userId, $roundId)) {
            return ['success' => false, 'message' => 'شما قبلاً در این دوره شرکت کرده‌اید.'];
        }

        $this->db->beginTransaction();

        try {
            // پرداخت هزینه ورود
            $transactionId = null;
            if ($round->entry_fee > 0) {
                $result = $this->walletService->withdraw(
                    $userId, $round->entry_fee, $round->currency, 'lottery_entry',
                    ['round_id' => $roundId, 'description' => "ورود به قرعه‌کشی: {$round->title}"]
                );
                if (!$result['success']) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'موجودی کافی نیست. ' . ($result['message'] ?? '')];
                }
                $transactionId = $result['transaction_id'] ?? null;
            }

            // تولید کد 10 رقمی Permutation
            $code = $this->generateUniqueCode($roundId);

            $participationId = $this->participationModel->create([
                'round_id' => $roundId,
                'user_id' => $userId,
                'code' => $code,
                'chance_score' => LotteryParticipation::DEFAULT_CHANCE,
                'transaction_id' => $transactionId,
            ]);

            if (!$participationId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'خطا در ثبت مشارکت.'];
            }

            $this->db->commit();

            $this->notify($userId, 'ثبت‌نام در قرعه‌کشی',
                "شما با موفقیت در قرعه‌کشی «{$round->title}» شرکت کردید.\nکد اختصاصی شما: {$code}",
                'lottery_joined');

            logger('lottery_participation', "User {$userId} joined round #{$roundId}, code: {$code}", 'info');

            return [
                'success' => true,
                'message' => 'با موفقیت در قرعه‌کشی ثبت‌نام شدید!',
                'code' => $code,
            ];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            logger('lottery_error', $e->getMessage(), 'error');
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }
    }

    /**
     * تولید کد 10 رقمی Permutation یکتا
     */
    private function generateUniqueCode(int $roundId): string
    {
        $maxAttempts = 100;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $digits = \range(0, 9);
            \shuffle($digits);
            $code = \implode('', $digits);

            // بررسی یکتایی
            $exists = $this->db->query(
                "SELECT COUNT(*) as c FROM lottery_participations WHERE round_id = ? AND code = ?",
                [$roundId, $code]
            )->fetch();

            if ((int)($exists->c ?? 0) === 0) {
                return $code;
            }
        }
        // Fallback
        return \implode('', \array_map(fn() => \random_int(0, 9), \range(1, 10)));
    }

    /**
     * تولید ۳ عدد روزانه (Cron یا ادمین)
     */
    public function generateDailyNumbers(int $roundId): array
    {
        $round = $this->roundModel->find($roundId);
        if (!$round || !\in_array($round->status, [LotteryRound::STATUS_ACTIVE, LotteryRound::STATUS_VOTING])) {
            return ['success' => false, 'message' => 'دوره فعال نیست.'];
        }

        $today = \date('Y-m-d');
        $existing = $this->dailyModel->getByRoundAndDate($roundId, $today);
        if ($existing) {
            return ['success' => false, 'message' => 'اعداد امروز قبلاً تولید شده‌اند.'];
        }

        // تولید ۳ عدد متفاوت از 0-9
        $numbers = [];
        while (\count($numbers) < 3) {
            $n = \random_int(0, 9);
            if (!\in_array($n, $numbers)) {
                $numbers[] = $n;
            }
        }

        // Seed روزانه
        $seedRaw = \bin2hex(\random_bytes(32));
        $seedHash = \hash('sha256', $seedRaw . $today . $roundId);

        // نوع بررسی تصادفی
        $matchType = self::MATCH_TYPES[\array_rand(self::MATCH_TYPES)];

        $dailyId = $this->dailyModel->create([
            'round_id' => $roundId,
            'date' => $today,
            'number_1' => $numbers[0],
            'number_2' => $numbers[1],
            'number_3' => $numbers[2],
            'seed_hash' => $seedHash,
            'seed_raw' => $seedRaw,
            'match_type' => $matchType,
        ]);

        if (!$dailyId) {
            return ['success' => false, 'message' => 'خطا در تولید اعداد.'];
        }

        // بروزرسانی وضعیت
        if ($round->status === LotteryRound::STATUS_ACTIVE) {
            $this->roundModel->update($roundId, ['status' => LotteryRound::STATUS_VOTING]);
        }

        logger('lottery_daily_numbers', "Round #{$roundId}, Date: {$today}, Numbers: " . \implode(',', $numbers), 'info');

        return [
            'success' => true,
            'message' => 'اعداد روزانه تولید شد.',
            'numbers' => $numbers,
            'daily_id' => $dailyId,
        ];
    }

    /**
     * رأی‌دادن کاربر
     */
    public function vote(int $userId, int $roundId, int $votedNumber): array
    {
        $round = $this->roundModel->find($roundId);
        if (!$round || $round->status !== LotteryRound::STATUS_VOTING) {
            return ['success' => false, 'message' => 'رأی‌گیری فعال نیست.'];
        }

        $participation = $this->participationModel->findByUserAndRound($userId, $roundId);
        if (!$participation) {
            return ['success' => false, 'message' => 'ابتدا در قرعه‌کشی ثبت‌نام کنید.'];
        }

        $today = \date('Y-m-d');
        $dailyNumber = $this->dailyModel->getByRoundAndDate($roundId, $today);
        if (!$dailyNumber) {
            return ['success' => false, 'message' => 'اعداد امروز هنوز تولید نشده‌اند.'];
        }

        // بررسی عدد معتبر
        $availableNumbers = [$dailyNumber->number_1, $dailyNumber->number_2, $dailyNumber->number_3];
        if (!\in_array($votedNumber, $availableNumbers)) {
            return ['success' => false, 'message' => 'عدد انتخابی نامعتبر است.'];
        }

        // بررسی رأی تکراری
        if ($this->voteModel->hasVotedToday($userId, $dailyNumber->id)) {
            return ['success' => false, 'message' => 'شما امروز قبلاً رأی داده‌اید.'];
        }

        $this->voteModel->create([
            'round_id' => $roundId,
            'daily_number_id' => $dailyNumber->id,
            'user_id' => $userId,
            'voted_number' => $votedNumber,
            'vote_date' => $today,
            'ip_address' => get_client_ip(),
            'user_agent' => get_user_agent(),
            'device_fingerprint' => generate_device_fingerprint(),
        ]);

        return ['success' => true, 'message' => "رأی شما به عدد {$votedNumber} ثبت شد."];
    }

    /**
     * نهایی‌سازی عدد منتخب روز + اعمال وزن شانس (Cron/ادمین)
     */
    public function finalizeDailyNumber(int $dailyNumberId): array
    {
        $daily = $this->dailyModel->find($dailyNumberId);
        if (!$daily || $daily->is_finalized) {
            return ['success' => false, 'message' => 'رکورد نامعتبر یا قبلاً نهایی شده.'];
        }

        // محاسبه عدد منتخب (رأی + تصادفی)
        $voteCounts = $this->voteModel->getVoteCounts($dailyNumberId);
        $availableNumbers = [$daily->number_1, $daily->number_2, $daily->number_3];

        $selectedNumber = $this->selectDailyNumber($availableNumbers, $voteCounts);

        // بروزرسانی
        $this->dailyModel->update($dailyNumberId, [
            'selected_number' => $selectedNumber,
            'selection_method' => !empty($voteCounts) ? 'vote_weighted' : 'random_weighted',
            'is_finalized' => 1,
        ]);

        // اعمال وزن‌دهی شانس بر شرکت‌کنندگان
        $this->applyChanceWeights($daily->round_id, $daily->date, $selectedNumber, $daily->match_type);

        logger('lottery_daily_finalized', "Daily #{$dailyNumberId}, selected: {$selectedNumber}", 'info');

        return ['success' => true, 'message' => "عدد منتخب امروز: {$selectedNumber}", 'selected' => $selectedNumber];
    }

    /**
     * انتخاب عدد منتخب روز (رأی + ضریب تصادفی)
     */
    private function selectDailyNumber(array $numbers, array $voteCounts): int
    {
        if (empty($voteCounts)) {
            return $numbers[\array_rand($numbers)];
        }

        // وزن = رأی + تصادفی
        $weights = [];
        foreach ($numbers as $n) {
            $voteWeight = $voteCounts[$n] ?? 0;
            $randomWeight = \random_int(1, 10); // ضریب تصادفی
            $weights[$n] = $voteWeight + $randomWeight;
        }

        // انتخاب وزن‌دار
        $totalWeight = \array_sum($weights);
        $random = \random_int(1, \max(1, $totalWeight));
        $cumulative = 0;

        foreach ($weights as $number => $weight) {
            $cumulative += $weight;
            if ($random <= $cumulative) {
                return $number;
            }
        }

        return $numbers[0];
    }

    /**
     * اعمال وزن‌دهی شانس
     */
    private function applyChanceWeights(int $roundId, string $date, int $selectedNumber, string $matchType): void
    {
        $participants = $this->participationModel->getAllActiveByRound($roundId);

        foreach ($participants as $p) {
            $code = $p->code;
            $matched = $this->checkMatch($code, $selectedNumber, $matchType);

            $randomFactor = \mt_rand(85, 115) / 100; // 0.85 - 1.15
            $scoreBefore = (float)$p->chance_score;

            if ($matched) {
                $change = LotteryParticipation::BASE_REWARD * $randomFactor;
                $reason = 'match_success';
            } else {
                $change = -(LotteryParticipation::BASE_PENALTY * $randomFactor);
                $reason = 'match_fail';
            }

            $scoreAfter = \round($scoreBefore + $change, 4);

            // کف امید
            if ($scoreAfter < LotteryParticipation::MIN_CHANCE) {
                $scoreAfter = LotteryParticipation::MIN_CHANCE;
            }

            $this->participationModel->update($p->id, ['chance_score' => $scoreAfter]);

            // لاگ
            $this->chanceLogModel->create([
                'participation_id' => $p->id,
                'user_id' => $p->user_id,
                'round_id' => $roundId,
                'date' => $date,
                'score_before' => $scoreBefore,
                'score_change' => $change,
                'score_after' => $scoreAfter,
                'reason' => $reason,
                'details' => "selected:{$selectedNumber}, match_type:{$matchType}, matched:" . ($matched ? 'yes' : 'no'),
            ]);
        }

        // کاهش نرم برای کسانی که رأی نداده‌اند
        $this->applyNoVoteDecay($roundId, $date);
    }

    /**
     * بررسی تطبیق (ابهام ساختاری)
     */
    private function checkMatch(string $code, int $selectedNumber, string $matchType): bool
    {
        $digits = \str_split($code);

        switch ($matchType) {
            case 'value':
                return \in_array($selectedNumber, $digits);

            case 'position':
                // جایگاه عدد در کد
                $pos = $selectedNumber % 10;
                return isset($digits[$pos]) && (int)$digits[$pos] === $selectedNumber;

            case 'value_position':
                // ترکیب مقدار + جایگاه
                $idx = \array_search($selectedNumber, $digits);
                return $idx !== false && ($idx % 2 === 0);

            case 'signal':
                // سیگنال آماری
                $sum = \array_sum(\array_slice($digits, 0, 5));
                return ($sum % 10) === $selectedNumber;

            default:
                return \in_array($selectedNumber, $digits);
        }
    }

    /**
     * کاهش نرم برای عدم مشارکت
     */
    private function applyNoVoteDecay(int $roundId, string $date): void
    {
        $dailyNumber = $this->dailyModel->getByRoundAndDate($roundId, $date);
        if (!$dailyNumber) return;

        $participants = $this->participationModel->getAllActiveByRound($roundId);

        foreach ($participants as $p) {
            $hasVoted = $this->voteModel->hasVotedToday($p->user_id, $dailyNumber->id);
            if (!$hasVoted) {
                $scoreBefore = (float)$p->chance_score;
                $scoreAfter = \round($scoreBefore * LotteryParticipation::DECAY_FACTOR, 4);

                if ($scoreAfter < LotteryParticipation::MIN_CHANCE) {
                    $scoreAfter = LotteryParticipation::MIN_CHANCE;
                }

                $this->participationModel->update($p->id, ['chance_score' => $scoreAfter]);

                $this->chanceLogModel->create([
                    'participation_id' => $p->id,
                    'user_id' => $p->user_id,
                    'round_id' => $roundId,
                    'date' => $date,
                    'score_before' => $scoreBefore,
                    'score_change' => $scoreAfter - $scoreBefore,
                    'score_after' => $scoreAfter,
                    'reason' => 'no_participation',
                ]);
            }
        }
    }

    /**
     * انتخاب برنده نهایی (Weighted Random)
     */
    public function selectWinner(int $roundId, int $adminId): array
    {
        $round = $this->roundModel->find($roundId);
        if (!$round) {
            return ['success' => false, 'message' => 'دوره یافت نشد.'];
        }

        $participants = $this->participationModel->getAllActiveByRound($roundId);
        if (empty($participants)) {
            return ['success' => false, 'message' => 'شرکت‌کننده‌ای وجود ندارد.'];
        }

        $totalScore = $this->participationModel->getTotalChanceScore($roundId);
        if ($totalScore <= 0) {
            return ['success' => false, 'message' => 'مجموع امتیازات صفر است.'];
        }

        // Weighted Random Selection
        $randomPoint = \mt_rand(0, (int)($totalScore * 10000)) / 10000;
        $cumulative = 0;
        $winner = null;

        foreach ($participants as $p) {
            $cumulative += (float)$p->chance_score;
            if ($randomPoint <= $cumulative) {
                $winner = $p;
                break;
            }
        }

        // Fallback
        if (!$winner) {
            $winner = $participants[\array_rand($participants)];
        }

        // Seed نهایی
        $finalSeed = \hash('sha256', \implode('|', [
            $roundId, $winner->user_id, $winner->chance_score,
            $totalScore, $randomPoint, \microtime(true)
        ]));

        $this->db->beginTransaction();

        try {
            // بروزرسانی دوره
            $this->roundModel->update($roundId, [
                'status' => LotteryRound::STATUS_COMPLETED,
                'winner_user_id' => $winner->user_id,
                'winner_chance_score' => $winner->chance_score,
                'final_seed' => $finalSeed,
            ]);

            // بروزرسانی شرکت‌کننده
            $this->participationModel->update($winner->id, ['status' => 'winner']);

            // بقیه
            foreach ($participants as $p) {
                if ($p->id !== $winner->id) {
                    $this->participationModel->update($p->id, ['status' => 'completed']);
                }
            }

            // واریز جایزه
            if ($round->prize_amount > 0) {
                $this->walletService->deposit(
                    $winner->user_id, $round->prize_amount, $round->currency, 'lottery_prize',
                    ['round_id' => $roundId, 'description' => "جایزه قرعه‌کشی: {$round->title}"]
                );
            }

            $this->db->commit();

            // نوتیفیکیشن برنده
            $prizeFormatted = \number_format($round->prize_amount);
            $currencyLabel = $round->currency === 'usdt' ? 'تتر' : 'تومان';
            $this->notify($winner->user_id, '🎉 تبریک! شما برنده شدید!',
                "شما برنده قرعه‌کشی «{$round->title}» شدید!\nجایزه: {$prizeFormatted} {$currencyLabel}",
                'lottery_winner');

            logger('lottery_winner', "Round #{$roundId}, Winner: user #{$winner->user_id}, score: {$winner->chance_score}", 'info');

            return [
                'success' => true,
                'message' => 'برنده انتخاب شد!',
                'winner_user_id' => $winner->user_id,
                'winner_score' => $winner->chance_score,
                'final_seed' => $finalSeed,
            ];

        } catch (\Throwable $e) {
            $this->db->rollBack();
            logger('lottery_winner_error', $e->getMessage(), 'error');
            return ['success' => false, 'message' => 'خطای سیستمی.'];
        }
    }

    /**
     * متن شفافیت برای نمایش بعد از اعلام برنده
     */
    public function getTransparencyText(): string
    {
        return <<<EOT
شفافیت و اعتمادسازی سیستم قرعه‌کشی چرتکه

قرعه‌کشی بر اساس رأی فقط کاربران باعث تبانی و تقلب چه مدیر چه کاربران می‌شود. برای همین، سیستم وزن‌دهی خودکار بر اساس روزانه تعیین می‌کند. این باعث جلوگیری ۱۰۰٪ از تقلب کاربران، مدیریت و کارکنان سیستم می‌شود.

نکات مهم:
• هیچ کاربری حذف نمی‌شود — فقط وزن شانس تغییر می‌کند
• کف شانس وجود دارد — هیچ‌کس به صفر نمی‌رسد
• انتخاب نهایی وزن‌دار است — شانس بالا = احتمال بیشتر، نه تضمین
• تاریخچه اعداد و Seed هش‌شده روزانه منتشر می‌شود
• سیستم رأی‌گیری + وزن‌دهی خودکار = باعث می‌شود همه چیز واقعی باشد
EOT;
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