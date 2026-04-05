<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class LotteryDailyNumber extends Model {
/**
     * ایجاد رکورد شماره‌های روزانه
     * خروجی: id یا null
     */
    public function create(array $data): ?int
    {
        if (!isset($data['is_deleted'])) {
            $data['is_deleted'] = 0;
        }

        $columns = \array_keys($data);
        $values  = \array_values($data);

        $placeholders = \array_fill(0, \count($columns), '?');
        $colsSql = '`' . \implode('`,`', $columns) . '`';

        $sql = "INSERT INTO `lottery_daily_numbers` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

        $stmt = $this->db->prepare($sql);
        $ok = $stmt->execute($values);

        if (!$ok) {
            return null;
        }

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : null;
    }

    public function find(int $id): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM lottery_daily_numbers WHERE id = ? AND is_deleted = 0 LIMIT 1",
            [$id]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    public function getByRoundAndDate(int $roundId, string $date): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM lottery_daily_numbers
             WHERE round_id = ? AND date = ? AND is_deleted = 0
             LIMIT 1",
            [$roundId, $date]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    public function getToday(int $roundId): ?object
    {
        return $this->getByRoundAndDate($roundId, \date('Y-m-d'));
    }

    public function getByRound(int $roundId): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM lottery_daily_numbers
             WHERE round_id = ? AND is_deleted = 0
             ORDER BY date ASC",
            [$roundId]
        );

        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    public function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        // اگر جدول updated_at دارد:
        if (!isset($data['updated_at'])) {
            $data['updated_at'] = \date('Y-m-d H:i:s');
        }

        $fields = [];
        $values = [];

        foreach ($data as $k => $v) {
            $fields[] = "`{$k}` = ?";
            $values[] = $v;
        }

        $values[] = $id;

        $sql = "UPDATE lottery_daily_numbers
                SET " . \implode(', ', $fields) . "
                WHERE id = ? AND is_deleted = 0";

        $stmt = $this->db->query($sql, $values);

        if ($stmt instanceof \PDOStatement) {
            return $stmt->rowCount() >= 0;
        }

        return (bool)$stmt;
    }
}