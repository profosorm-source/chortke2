<?php

namespace App\Services;

use Core\Database;

class UserScoreService
{
    private Database $db;
    private RiskPolicyService $policyService;

    public function __construct(Database $db, RiskPolicyService $policyService)
    {
        $this->db = $db;
        $this->policyService = $policyService;
    }

    /**
     * ثبت event امتیاز و اعمال delta (فعلا روی fraud در جدول users)
     */
    public function applyEventDelta(
        int $userId,
        string $domain,
        float $delta,
        string $source,
        array $meta = []
    ): bool {
        try {
            $this->db->beginTransaction();

            // 1) ثبت event
            $eventStmt = $this->db->prepare("
                INSERT INTO user_score_events (user_id, domain, source, delta, meta_json, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $eventStmt->execute([
                $userId,
                $domain,
                $source,
                $delta,
                json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);

            // 2) اعمال delta روی score اصلی دامنه
            if ($domain === 'fraud') {
                $raw = $this->getRawFraudScore($userId);
                $newRaw = $this->clamp($raw + $delta, 0, 100);

                $updateStmt = $this->db->prepare("
                    UPDATE users
                    SET fraud_score = ?
                    WHERE id = ?
                    LIMIT 1
                ");
                $updateStmt->execute([$newRaw, $userId]);
            }

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            logger('score_event_error', $e->getMessage(), 'error', [
                'user_id' => $userId,
                'domain' => $domain,
                'source' => $source,
            ]);
            return false;
        }
    }

    public function getFraudScore(int $userId): float
    {
        $raw = $this->getRawFraudScore($userId);
        $effective = $this->applyAdjustments($userId, 'fraud', $raw);

        return $this->clamp($effective, 0, 100);
    }

    public function getRawFraudScore(int $userId): float
    {
        $stmt = $this->db->prepare("SELECT fraud_score FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (float)$value : 0.0;
    }

    /**
     * اسکور موثر هر دامنه: raw + adjustments
     */
    public function getEffectiveScore(int $userId, string $domain, float $rawScore): float
    {
        return $this->clamp($this->applyAdjustments($userId, $domain, $rawScore), 0, 100);
    }

    /**
     * لیست adjustment های فعال کاربر
     */
    public function getActiveAdjustments(int $userId, string $domain): array
    {
        $stmt = $this->db->prepare("
            SELECT id, operation, value, reason, expires_at, created_by, created_at
            FROM user_score_adjustments
            WHERE user_id = ?
              AND domain = ?
              AND is_active = 1
              AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY created_at DESC
        ");
        $stmt->execute([$userId, $domain]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function applyAdjustments(int $userId, string $domain, float $rawScore): float
    {
        $adjustments = $this->getActiveAdjustments($userId, $domain);
        if (!$adjustments) {
            return $rawScore;
        }

        $score = $rawScore;

        foreach ($adjustments as $adj) {
            $op = strtolower((string)$adj['operation']);
            $value = (float)$adj['value'];

            if ($op === 'set') {
                $score = $value;
                continue;
            }

            if ($op === 'add') {
                $score += $value;
                continue;
            }

            if ($op === 'subtract') {
                $score -= $value;
                continue;
            }
        }

        return $score;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }
}