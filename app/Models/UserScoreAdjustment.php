<?php

namespace App\Models;

use Core\Database;

class UserScoreAdjustment
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function getActiveByUserAndDomain(int $userId, string $domain): array
    {
        $sql = 'SELECT * FROM user_score_adjustments\n'
             . 'WHERE user_id = ? AND domain = ? AND is_active = 1\n'
             . 'AND (expires_at IS NULL OR expires_at > NOW())\n'
             . 'ORDER BY created_at DESC';

        return $this->db->fetchAll($sql, [$userId, $domain]);
    }

    public function create(array $data): bool
    {
        $sql = 'INSERT INTO user_score_adjustments (user_id, domain, operation, value, reason, expires_at, created_by, is_active)\n'
             . 'VALUES (?, ?, ?, ?, ?, ?, ?, 1)';

        return (bool) $this->db->query($sql, [
            $data['user_id'],
            $data['domain'],
            $data['operation'],
            $data['value'],
            $data['reason'],
            $data['expires_at'] ?? null,
            $data['created_by'],
        ]);
    }

    public function revoke(int $id, int $revokedBy, string $revokeReason): bool
    {
        $sql = 'UPDATE user_score_adjustments\n'
             . 'SET is_active = 0, revoked_by = ?, revoked_at = NOW(), revoke_reason = ?\n'
             . 'WHERE id = ? AND is_active = 1';

        return (bool) $this->db->query($sql, [$revokedBy, $revokeReason, $id]);
    }

    public function forUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM user_score_adjustments WHERE user_id = ? ORDER BY created_at DESC LIMIT 200',
            [$userId]
        );
    }
}