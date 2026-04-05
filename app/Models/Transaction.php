<?php

namespace App\Models;

use Core\Model;

class Transaction extends Model
{
    protected static string $table = 'transactions';

    /**
     * ایجاد تراکنش جدید
     */
    public function create(array $data): ?object
    {
        if (!isset($data['transaction_id']) || $data['transaction_id'] === '') {
            $data['transaction_id'] = $this->generateUUID();
        }

        $type = (string)($data['type'] ?? '');
        if (!isset($data['idempotency_key']) && \in_array($type, ['deposit', 'withdraw'], true)) {
            $data['idempotency_key'] = $this->generateIdempotencyKey($data);
        }

        $data['ip_address'] = $data['ip_address'] ?? (function_exists('get_client_ip') ? get_client_ip() : null);
        $data['device_fingerprint'] = $data['device_fingerprint'] ?? (
            \function_exists('generate_device_fingerprint') ? generate_device_fingerprint() : null
        );

        if (isset($data['metadata']) && \is_array($data['metadata'])) {
            $data['metadata'] = \json_encode($data['metadata'], JSON_UNESCAPED_UNICODE);
        }

        $now = \date('Y-m-d H:i:s');
        $data['created_at'] = $data['created_at'] ?? $now;
        $data['updated_at'] = $data['updated_at'] ?? $now;

        $idOrBool = parent::create($data);

        if (\is_int($idOrBool)) {
            return $this->find($idOrBool);
        }

        return null;
    }

    /**
     * بروزرسانی وضعیت تراکنش
     * 
     * @deprecated 2.0.0 این متد نقض اصل Immutable Ledger می‌کند.
     *             بجای آن از recordStatusChange() استفاده کنید.
     */
    public function updateStatus(int $id, string $status, ?array $metadata = null): bool
    {
        // ⚠️ Warning for developers
        error_log("⚠️ DEPRECATED: updateStatus() called. Use recordStatusChange() instead. (Transaction ID: {$id})");
        
        // برای backward compatibility، اول event ثبت می‌کنیم
        $transaction = $this->find($id);
        if ($transaction) {
            $this->recordStatusChange(
                $transaction->transaction_id,
                $status,
                'Status changed via deprecated updateStatus method',
                null,
                $metadata
            );
        }
        
        // سپس UPDATE انجام می‌شود
        $sql = "UPDATE " . static::$table . " SET status = :status, updated_at = NOW()";
        $params = ['id' => $id, 'status' => $status];

        if ($status === 'completed') {
            $sql .= ", completed_at = NOW()";
        }

        if ($metadata) {
            $sql .= ", metadata = :metadata";
            $params['metadata'] = \json_encode($metadata, JSON_UNESCAPED_UNICODE);
        }

        $sql .= " WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
    
    /**
     * ثبت تغییر وضعیت در transaction_events (Immutable Audit Trail)
     * 
     * این متد اصل Event Sourcing را رعایت می‌کند و هیچ‌وقت داده‌های قبلی را UPDATE نمی‌کند.
     * 
     * @param string $transactionId شناسه یکتا تراکنش
     * @param string $newStatus وضعیت جدید
     * @param string|null $reason دلیل تغییر
     * @param int|null $changedBy شناسه کاربر/ادمین که تغییر داده (NULL = system)
     * @param array|null $eventMetadata اطلاعات اضافی
     * @return bool
     */
    public function recordStatusChange(
        string $transactionId,
        string $newStatus,
        ?string $reason = null,
        ?int $changedBy = null,
        ?array $eventMetadata = null
    ): bool {
        try {
            // دریافت تراکنش و وضعیت فعلی
            $transaction = $this->findByTransactionId($transactionId);
            
            if (!$transaction) {
                error_log("❌ Transaction not found: {$transactionId}");
                return false;
            }
            
            $previousStatus = $transaction->status;
            
            // اگر وضعیت تغییری نکرده، نیازی به ثبت event نیست
            if ($previousStatus === $newStatus) {
                error_log("⚠️ Status unchanged for transaction {$transactionId}: {$newStatus}");
                return true;
            }
            
            // ✅ STEP 1: ثبت event در transaction_events (Immutable)
            $eventSql = "INSERT INTO transaction_events (
                transaction_id, 
                event_type, 
                previous_status, 
                new_status, 
                reason, 
                changed_by, 
                ip_address,
                metadata,
                created_at
            ) VALUES (
                :transaction_id,
                :event_type,
                :previous_status,
                :new_status,
                :reason,
                :changed_by,
                :ip_address,
                :metadata,
                NOW()
            )";
            
            $eventParams = [
                'transaction_id' => $transactionId,
                'event_type' => 'status_change',
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'reason' => $reason,
                'changed_by' => $changedBy,
                'ip_address' => function_exists('get_client_ip') ? get_client_ip() : null,
                'metadata' => $eventMetadata ? json_encode($eventMetadata, JSON_UNESCAPED_UNICODE) : null
            ];
            
            $eventStmt = $this->db->prepare($eventSql);
            $eventStmt->execute($eventParams);
            
            // ✅ STEP 2: UPDATE تراکنش (برای backward compatibility و query performance)
            $updateSql = "UPDATE " . static::$table . " 
                         SET status = :status, updated_at = NOW()";
            
            $updateParams = [
                'transaction_id' => $transactionId,
                'status' => $newStatus
            ];
            
            // اگر وضعیت completed شد، زمان completion ثبت می‌شود
            if ($newStatus === 'completed') {
                $updateSql .= ", completed_at = NOW()";
            }
            
            $updateSql .= " WHERE transaction_id = :transaction_id";
            
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute($updateParams);
            
            // ✅ لاگ موفقیت
            error_log("✅ Status changed: {$transactionId} | {$previousStatus} → {$newStatus} | Reason: {$reason}");
            
            return true;
            
        } catch (\PDOException $e) {
            error_log("❌ Error recording status change for {$transactionId}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * دریافت تاریخچه تغییرات یک تراکنش (Event History)
     * 
     * @param string $transactionId
     * @return array
     */
    public function getStatusHistory(string $transactionId): array
    {
        try {
            $sql = "SELECT 
                        event_type,
                        previous_status,
                        new_status,
                        reason,
                        changed_by,
                        ip_address,
                        metadata,
                        created_at
                    FROM transaction_events
                    WHERE transaction_id = :transaction_id
                    ORDER BY created_at ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['transaction_id' => $transactionId]);
            
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
        } catch (\PDOException $e) {
            error_log("Error getting status history for {$transactionId}: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * دریافت آخرین event یک تراکنش
     * 
     * @param string $transactionId
     * @return array|null
     */
    public function getLatestEvent(string $transactionId): ?array
    {
        try {
            $sql = "SELECT *
                    FROM transaction_events
                    WHERE transaction_id = :transaction_id
                    ORDER BY created_at DESC
                    LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['transaction_id' => $transactionId]);
            
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $result ?: null;
            
        } catch (\PDOException $e) {
            error_log("Error getting latest event for {$transactionId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * دریافت تراکنش بر اساس transaction_id
     */
    public function findByTransactionId(string $transactionId): ?object
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE transaction_id = :transaction_id LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['transaction_id' => $transactionId]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * دریافت تراکنش بر اساس Idempotency Key
     */
    public function findByIdempotencyKey(string $key): ?object
    {
        $sql = "SELECT * FROM " . static::$table . " WHERE idempotency_key = :k LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['k' => $key]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * دریافت تراکنش‌های کاربر
     */
    public function getUserTransactions(
        int $userId,
        ?string $type = null,
        ?string $currency = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT * FROM " . static::$table . " WHERE user_id = :user_id";
        $params = ['user_id' => $userId];

        if ($type) {
            $sql .= " AND type = :type";
            $params['type'] = $type;
        }

        if ($currency) {
            $sql .= " AND currency = :currency";
            $params['currency'] = $currency;
        }

        $sql .= " ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش تراکنش‌های کاربر
     */
    public function countUserTransactions(int $userId, ?string $type = null, ?string $currency = null): int
    {
        $sql = "SELECT COUNT(*) as count FROM " . static::$table . " WHERE user_id = :user_id";
        $params = ['user_id' => $userId];

        if ($type) {
            $sql .= " AND type = :type";
            $params['type'] = $type;
        }

        if ($currency) {
            $sql .= " AND currency = :currency";
            $params['currency'] = $currency;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return (int)($result->count ?? 0);
    }

    /**
     * آمار تراکنش‌های کاربر
     */
    public function getUserStats(int $userId): object
    {
        $sql = "
            SELECT
                currency,
                SUM(CASE WHEN type = 'deposit' AND status = 'completed' THEN amount ELSE 0 END) as total_deposits,
                SUM(CASE WHEN type = 'withdraw' AND status = 'completed' THEN amount ELSE 0 END) as total_withdrawals,
                COUNT(CASE WHEN type = 'deposit' THEN 1 END) as deposit_count,
                COUNT(CASE WHEN type = 'withdraw' THEN 1 END) as withdrawal_count
            FROM " . static::$table . "
            WHERE user_id = :user_id
            GROUP BY currency
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['user_id' => $userId]);
        $results = $stmt->fetchAll(\PDO::FETCH_OBJ);

        $stats = (object)[
            'irt'  => (object)['total_deposits' => 0, 'total_withdrawals' => 0, 'deposit_count' => 0, 'withdrawal_count' => 0],
            'usdt' => (object)['total_deposits' => 0, 'total_withdrawals' => 0, 'deposit_count' => 0, 'withdrawal_count' => 0],
        ];

        foreach ($results as $result) {
            $cur = (string)($result->currency ?? '');
            if ($cur !== '') {
                $stats->{$cur} = $result;
            }
        }

        return $stats;
    }

    /**
     * دریافت تمام تراکنش‌ها (ادمین)
     */
    public function getAll(?string $status = null, ?string $type = null, ?string $currency = null, int $limit = 50, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT t.*, u.full_name, u.email
                FROM " . static::$table . " t
                LEFT JOIN users u ON t.user_id = u.id
                WHERE 1=1";
        $params = [];

        if ($status) {
            $sql .= " AND t.status = :status";
            $params['status'] = $status;
        }
        if ($type) {
            $sql .= " AND t.type = :type";
            $params['type'] = $type;
        }
        if ($currency) {
            $sql .= " AND t.currency = :currency";
            $params['currency'] = $currency;
        }

        $sql .= " ORDER BY t.created_at DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * شمارش کل تراکنش‌ها (ادمین)
     */
    public function countAll(?string $status = null, ?string $type = null, ?string $currency = null): int
    {
        $sql = "SELECT COUNT(*) as count FROM " . static::$table . " WHERE 1=1";
        $params = [];

        if ($status) {
            $sql .= " AND status = :status";
            $params['status'] = $status;
        }
        if ($type) {
            $sql .= " AND type = :type";
            $params['type'] = $type;
        }
        if ($currency) {
            $sql .= " AND currency = :currency";
            $params['currency'] = $currency;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return (int)($result->count ?? 0);
    }

    private function generateUUID(): string
    {
        return \sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            \mt_rand(0, 0xffff), \mt_rand(0, 0xffff),
            \mt_rand(0, 0xffff),
            \mt_rand(0, 0x0fff) | 0x4000,
            \mt_rand(0, 0x3fff) | 0x8000,
            \mt_rand(0, 0xffff), \mt_rand(0, 0xffff), \mt_rand(0, 0xffff)
        );
    }

    private function generateIdempotencyKey(array $data): string
    {
        $seed = \implode('|', [
            (string)($data['user_id'] ?? ''),
            (string)($data['type'] ?? ''),
            (string)($data['amount'] ?? ''),
            (string)($data['currency'] ?? ''),
            \bin2hex(\random_bytes(8)),
        ]);
        return \hash('sha256', $seed);
    }

    /**
     * بروزرسانی وضعیت تراکنش با شناسه UUID (برای completeWithdrawal و cancelWithdrawal)
     */
    public function updateStatusByTransactionId(string $transactionId, int $userId, string $status): bool
    {
        $sql = "UPDATE " . static::$table . "
                SET status = :status, updated_at = NOW()
                WHERE transaction_id = :transaction_id AND user_id = :user_id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'status'         => $status,
            'transaction_id' => $transactionId,
            'user_id'        => $userId,
        ]);
    }

    /**
     * دریافت device fingerprint های شناخته‌شده کاربر (30 روز اخیر)
     */
    public function getKnownDeviceFingerprints(int $userId, int $days = 30, int $limit = 5): array
    {
        $sql = "SELECT DISTINCT device_fingerprint, COUNT(*) as usage_count
                FROM " . static::$table . "
                WHERE user_id = :user_id
                  AND device_fingerprint IS NOT NULL
                  AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY device_fingerprint
                ORDER BY usage_count DESC
                LIMIT :lim";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
