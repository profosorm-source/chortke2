<?php

namespace App\Models;

use Core\Database;

class UserScoreEvent
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function create(int $userId, string $domain, string $source, float $delta, array $meta = []): bool
    {
        $sql = 'INSERT INTO user_score_events (user_id, domain, source, delta, meta_json) VALUES (?, ?, ?, ?, ?)';

        return (bool) $this->db->query($sql, [
            $userId,
            $domain,
            $source,
            $delta,
            !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    public function byUser(int $userId, ?string $domain = null, int $limit = 200): array
    {
        if ($domain === null) {
            return $this->db->fetchAll(
                'SELECT * FROM user_score_events WHERE user_id = ? ORDER BY created_at DESC LIMIT ?',
                [$userId, $limit]
            );
        }

        return $this->db->fetchAll(
            'SELECT * FROM user_score_events WHERE user_id = ? AND domain = ? ORDER BY created_at DESC LIMIT ?',
            [$userId, $domain, $limit]
        );
    }
}