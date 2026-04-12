<?php

namespace App\Services;

use Core\Database;

class RiskPolicyService
{
    private Database $db;

    /** @var array<string, mixed> */
    private static array $cache = [];

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    private function cacheKey(string $domain, string $key): string
    {
        return $domain . '::' . $key;
    }

    /**
     * مقدار خام policy را برمی‌گرداند (یا default)
     */
    public function get(string $domain, string $key, $default = null)
    {
        $cacheKey = $this->cacheKey($domain, $key);

        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $stmt = $this->db->prepare("
            SELECT value, value_type
            FROM risk_policies
            WHERE domain = ? AND key_name = ?
            LIMIT 1
        ");
        $stmt->execute([$domain, $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            self::$cache[$cacheKey] = $default;
            return $default;
        }

        $value = $this->castValue($row['value'], $row['value_type'] ?? 'string');
        self::$cache[$cacheKey] = $value;

        return $value;
    }

    public function getInt(string $domain, string $key, int $default = 0): int
    {
        return (int)$this->get($domain, $key, $default);
    }

    public function getFloat(string $domain, string $key, float $default = 0.0): float
    {
        return (float)$this->get($domain, $key, $default);
    }

    public function getBool(string $domain, string $key, bool $default = false): bool
    {
        $value = $this->get($domain, $key, $default);

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower((string)$value);
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * ثبت/آپدیت policy (برای پنل مدیریت)
     */
    public function set(
        string $domain,
        string $key,
        $value,
        string $valueType = 'string',
        ?int $adminId = null,
        ?string $description = null
    ): bool {
        $storedValue = $this->stringifyValue($value, $valueType);

        $stmt = $this->db->prepare("
            INSERT INTO risk_policies (domain, key_name, value, value_type, description, updated_by, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                value = VALUES(value),
                value_type = VALUES(value_type),
                description = VALUES(description),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");

        $ok = $stmt->execute([
            $domain,
            $key,
            $storedValue,
            $valueType,
            $description,
            $adminId,
        ]);

        unset(self::$cache[$this->cacheKey($domain, $key)]);

        return $ok;
    }

    public function clearCache(): void
    {
        self::$cache = [];
    }

    private function castValue($value, string $type)
    {
        switch (strtolower($type)) {
            case 'int':
            case 'integer':
                return (int)$value;

            case 'float':
            case 'double':
                return (float)$value;

            case 'bool':
            case 'boolean':
                $normalized = strtolower((string)$value);
                return in_array($normalized, ['1', 'true', 'yes', 'on'], true);

            case 'json':
                $decoded = json_decode((string)$value, true);
                return json_last_error() === JSON_ERROR_NONE ? $decoded : null;

            default:
                return $value;
        }
    }

    private function stringifyValue($value, string $type): string
    {
        switch (strtolower($type)) {
            case 'json':
                return json_encode($value, JSON_UNESCAPED_UNICODE);

            case 'bool':
            case 'boolean':
                return $value ? '1' : '0';

            default:
                return (string)$value;
        }
    }
}