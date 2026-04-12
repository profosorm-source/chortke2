<?php

namespace App\Models;

use Core\Database;

class RiskPolicy
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function get(string $domain, string $keyName): ?object
    {
        return $this->db->fetch(
            'SELECT * FROM risk_policies WHERE domain = ? AND key_name = ? LIMIT 1',
            [$domain, $keyName]
        );
    }

    public function allByDomain(string $domain): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM risk_policies WHERE domain = ? ORDER BY key_name ASC',
            [$domain]
        );
    }

    public function upsert(
        string $domain,
        string $keyName,
        string $value,
        string $valueType,
        ?string $description,
        int $updatedBy
    ): bool {
        $sql = 'INSERT INTO risk_policies (domain, key_name, value, value_type, description, updated_by) VALUES (?, ?, ?, ?, ?, ?)\n'
             . 'ON DUPLICATE KEY UPDATE value = VALUES(value), value_type = VALUES(value_type), description = VALUES(description), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP';

        return (bool) $this->db->query($sql, [$domain, $keyName, $value, $valueType, $description, $updatedBy]);
    }
}