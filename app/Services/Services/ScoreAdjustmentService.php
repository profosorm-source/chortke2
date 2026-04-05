<?php

namespace App\Services;

use App\Models\UserScoreAdjustment;
use App\Models\UserScoreEvent;
use InvalidArgumentException;

class ScoreAdjustmentService
{
    private UserScoreAdjustment $adjustmentModel;
    private UserScoreEvent $eventModel;

    public function __construct(UserScoreAdjustment $adjustmentModel, UserScoreEvent $eventModel)
    {
        $this->adjustmentModel = $adjustmentModel;
        $this->eventModel = $eventModel;
    }

    public function adjust(
        int $adminId,
        int $userId,
        string $domain,
        string $operation,
        float $value,
        string $reason,
        ?string $expiresAt = null
    ): bool {
        $this->validate($domain, $operation, $value, $reason);

        $ok = $this->adjustmentModel->create([
            'user_id' => $userId,
            'domain' => $domain,
            'operation' => $operation,
            'value' => $value,
            'reason' => $reason,
            'expires_at' => $expiresAt,
            'created_by' => $adminId,
        ]);

        if ($ok) {
            $this->eventModel->create($userId, $domain, 'admin_adjustment', 0, [
                'operation' => $operation,
                'value' => $value,
                'reason' => $reason,
                'expires_at' => $expiresAt,
                'admin_id' => $adminId,
            ]);
        }

        return $ok;
    }

    public function revoke(int $adminId, int $adjustmentId, string $reason): bool
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Revoke reason is required.');
        }

        return $this->adjustmentModel->revoke($adjustmentId, $adminId, $reason);
    }

    private function validate(string $domain, string $operation, float $value, string $reason): void
    {
        if (!in_array($domain, ['fraud', 'task'], true)) {
            throw new InvalidArgumentException('Invalid score domain.');
        }

        if (!in_array($operation, ['set', 'add', 'subtract'], true)) {
            throw new InvalidArgumentException('Invalid score operation.');
        }

        if ($value < 0) {
            throw new InvalidArgumentException('Adjustment value cannot be negative.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Adjustment reason is required.');
        }
    }
}