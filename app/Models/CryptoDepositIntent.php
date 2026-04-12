<?php

namespace App\Models;

use Core\Model;

class CryptoDepositIntent extends Model
{
    protected static string $table = 'crypto_deposit_intents';

    public function getOpenIntentForUser(int $userId): ?object
    {
        $sql = "SELECT * FROM " . static::$table . "
                WHERE user_id = :user_id AND status = 'open'
                ORDER BY id DESC LIMIT 1";

        $stmt = static::db()->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);

        return $row ?: null;
    }

    public function expireIfPassed(int $intentId): void
    {
        $sql = "UPDATE " . static::$table . "
                SET status='expired', updated_at=NOW()
                WHERE id=:id AND status='open' AND expires_at < NOW()";

        $stmt = static::db()->prepare($sql);
        $stmt->execute(['id' => $intentId]);
    }
}