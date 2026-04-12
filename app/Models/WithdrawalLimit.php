<?php

namespace App\Models;

use Core\Model;

class WithdrawalLimit extends Model
{
    protected static string $table = 'withdrawal_limits';

    protected array $fillable = [
        'user_id','limit_date','withdrawal_count','last_withdrawal_at'
    ];

    /**
     * بررسی محدودیت روزانه
     */
    public function checkDailyLimit(int $userId, int $limit): bool
    {
        $today = date('Y-m-d');
        $sql   = "SELECT withdrawal_count FROM " . static::$table . "
                  WHERE user_id = :user_id AND limit_date = :today LIMIT 1";
        $stmt  = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId, 'today' => $today]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);

        if (!$row) return true;
        return ((int)$row->withdrawal_count) < $limit;
    }

    /**
     * افزایش شمارنده برداشت روزانه (UPSERT)
     */
    public function incrementDailyCount(int $userId): void
    {
        $today = date('Y-m-d');
        $now   = date('Y-m-d H:i:s');

        // ابتدا بررسی وجود رکورد
        $sql  = "SELECT id, withdrawal_count FROM " . static::$table . "
                 WHERE user_id = :user_id AND limit_date = :today LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId, 'today' => $today]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);

        if ($row) {
            $updateSql  = "UPDATE " . static::$table . "
                           SET withdrawal_count = withdrawal_count + 1,
                               last_withdrawal_at = :now,
                               updated_at = :now2
                           WHERE id = :id";
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute(['now' => $now, 'now2' => $now, 'id' => $row->id]);
        } else {
            $insertSql  = "INSERT INTO " . static::$table . "
                           (user_id, limit_date, withdrawal_count, last_withdrawal_at, created_at, updated_at)
                           VALUES (:user_id, :today, 1, :now, :now2, :now3)";
            $insertStmt = $this->db->prepare($insertSql);
            $insertStmt->execute([
                'user_id' => $userId,
                'today'   => $today,
                'now'     => $now,
                'now2'    => $now,
                'now3'    => $now,
            ]);
        }
    }
}