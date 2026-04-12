<?php
// app/Models/SEOExecution.php

namespace App\Models;
use Core\Model;

use Core\Database;

class SEOExecution extends Model {

    public int $id;
    public int $keyword_id;
    public int $executor_id;
    public string $search_query;
    public string $target_url;
    public int $scroll_duration;
    public int $browse_duration;
    public int $total_duration;
    public ?string $scroll_data;
    public ?string $behavior_data;
    public float $reward_amount;
    public string $reward_currency;
    public bool $reward_paid;
    public ?string $idempotency_key;
    public string $status;
    public ?string $failure_reason;
    public ?string $ip_address;
    public ?string $user_agent;
    public ?string $device_fingerprint;
    public float $fraud_score;
    public string $started_at;
    public ?string $completed_at;

    // JOIN
    public ?string $keyword_text = null;
    public ?string $executor_name = null;
public function find(int $id): ?self
    {
        $stmt = $this->db->prepare("
            SELECT se.*, sk.keyword as keyword_text, u.full_name as executor_name
            FROM seo_executions se
            JOIN seo_keywords sk ON sk.id = se.keyword_id
            JOIN users u ON u.id = se.executor_id
            WHERE se.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * بررسی محدودیت ساعتی
     */
    public function countByExecutorLastHour(int $executorId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM seo_executions 
            WHERE executor_id = ? AND started_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
              AND status IN ('completed','started','browsing')
        ");
        $stmt->execute([$executorId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * بررسی محدودیت روزانه
     */
    public function countByExecutorToday(int $executorId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM seo_executions 
            WHERE executor_id = ? AND DATE(started_at) = CURDATE()
              AND status IN ('completed','started','browsing')
        ");
        $stmt->execute([$executorId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * بررسی IP ساعت اخیر
     */
    public function countByIPLastHour(string $ip): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM seo_executions 
            WHERE ip_address = ? AND started_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$ip]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * آیا کاربر امروز این کلمه را انجام داده؟
     */
    public function existsByKeywordAndExecutorToday(int $keywordId, int $executorId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM seo_executions 
            WHERE keyword_id = ? AND executor_id = ? AND DATE(started_at) = CURDATE()
              AND status IN ('completed','started','browsing')
        ");
        $stmt->execute([$keywordId, $executorId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(array $data): ?self
    {
        $stmt = $this->db->prepare("
            INSERT INTO seo_executions 
            (keyword_id, executor_id, search_query, target_url,
             reward_amount, reward_currency, idempotency_key,
             ip_address, user_agent, device_fingerprint, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'started')
        ");

        $result = $stmt->execute([
            $data['keyword_id'],
            $data['executor_id'],
            $data['search_query'],
            $data['target_url'],
            $data['reward_amount'] ?? 0,
            $data['reward_currency'] ?? 'irt',
            $data['idempotency_key'] ?? str_random(32),
            $data['ip_address'] ?? get_client_ip(),
            $data['user_agent'] ?? get_user_agent(),
            $data['device_fingerprint'] ?? generate_device_fingerprint(),
        ]);

        return $result ? $this->find((int) $this->db->lastInsertId()) : null;
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        $allowed = [
            'status', 'failure_reason',
            'scroll_duration', 'browse_duration', 'total_duration',
            'scroll_data', 'behavior_data',
            'reward_paid', 'fraud_score', 'completed_at',
        ];

        foreach ($allowed as $field) {
            if (\array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) return false;
        $params[] = $id;
        $fieldStr = \implode(', ', $fields);
        $stmt = $this->db->prepare("UPDATE seo_executions SET {$fieldStr} WHERE id = ?");
        return $stmt->execute($params);
    }

    /**
     * لیست تاریخچه کاربر
     */
    public function getByExecutor(int $executorId, int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT se.*, sk.keyword as keyword_text
            FROM seo_executions se
            JOIN seo_keywords sk ON sk.id = se.keyword_id
            WHERE se.executor_id = ?
            ORDER BY se.started_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$executorId, $limit, $offset]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return \array_map([$this, 'hydrate'], $rows);
    }

    public function countByExecutor(int $executorId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM seo_executions WHERE executor_id = ?");
        $stmt->execute([$executorId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * آمار کاربر
     */
    public function getUserStats(int $userId): object
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN reward_paid = 1 THEN reward_amount ELSE 0 END) as total_earned
            FROM seo_executions WHERE executor_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(\PDO::FETCH_OBJ);
    }

    /**
     * لیست همه (Admin)
     */
    public function getAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) { $where[] = 'se.status = ?'; $params[] = $filters['status']; }
        if (!empty($filters['keyword_id'])) { $where[] = 'se.keyword_id = ?'; $params[] = (int) $filters['keyword_id']; }
        if (!empty($filters['search'])) {
            $where[] = '(sk.keyword LIKE ? OR u.full_name LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }

        $whereStr = \implode(' AND ', $where);
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare("
            SELECT se.*, sk.keyword as keyword_text, u.full_name as executor_name
            FROM seo_executions se
            JOIN seo_keywords sk ON sk.id = se.keyword_id
            JOIN users u ON u.id = se.executor_id
            WHERE {$whereStr}
            ORDER BY se.started_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return \array_map([$this, 'hydrate'], $rows);
    }

    public function statusLabel(string $status): string
    {
        return [
            'started'    => 'شروع شده',
            'browsing'   => 'در حال مرور',
            'completed'  => 'تکمیل شده',
            'failed'     => 'ناموفق',
            'suspicious' => 'مشکوک',
            'expired'    => 'منقضی',
        ][$status] ?? $status;
    }

    public function statusBadge(string $status): string
    {
        return [
            'started'    => 'info',
            'browsing'   => 'warning',
            'completed'  => 'success',
            'failed'     => 'danger',
            'suspicious' => 'dark',
            'expired'    => 'secondary',
        ][$status] ?? 'secondary';
    }

    private function hydrate(array $row): self
    {
        $obj = new self();
        $obj->id = (int) $row['id'];
        $obj->keyword_id = (int) $row['keyword_id'];
        $obj->executor_id = (int) $row['executor_id'];
        $obj->search_query = $row['search_query'];
        $obj->target_url = $row['target_url'];
        $obj->scroll_duration = (int) ($row['scroll_duration'] ?? 0);
        $obj->browse_duration = (int) ($row['browse_duration'] ?? 0);
        $obj->total_duration = (int) ($row['total_duration'] ?? 0);
        $obj->scroll_data = $row['scroll_data'] ?? null;
        $obj->behavior_data = $row['behavior_data'] ?? null;
        $obj->reward_amount = (float) ($row['reward_amount'] ?? 0);
        $obj->reward_currency = $row['reward_currency'] ?? 'irt';
        $obj->reward_paid = (bool) ($row['reward_paid'] ?? false);
        $obj->idempotency_key = $row['idempotency_key'] ?? null;
        $obj->status = $row['status'];
        $obj->failure_reason = $row['failure_reason'] ?? null;
        $obj->ip_address = $row['ip_address'] ?? null;
        $obj->user_agent = $row['user_agent'] ?? null;
        $obj->device_fingerprint = $row['device_fingerprint'] ?? null;
        $obj->fraud_score = (float) ($row['fraud_score'] ?? 0);
        $obj->started_at = $row['started_at'];
        $obj->completed_at = $row['completed_at'] ?? null;
        $obj->keyword_text = $row['keyword_text'] ?? null;
        $obj->executor_name = $row['executor_name'] ?? null;
        return $obj;
    }
}