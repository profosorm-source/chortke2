<?php
// app/Models/TaskExecution.php

namespace App\Models;
use Core\Model;

class TaskExecution extends Model {
    
    public int $id;
    public int $advertisement_id;
    public int $executor_id;
    public ?int $executor_social_account_id;
    public ?string $proof_image;
    public ?string $proof_metadata;
    public string $status;
    public ?string $rejection_reason;
    public ?string $reviewed_by;
    public ?int $reviewer_id;
    public float $reward_amount;
    public string $reward_currency;
    public bool $reward_paid;
    public bool $commission_paid;
    public ?string $idempotency_key;
    public string $started_at;
    public ?string $deadline_at;
    public ?string $submitted_at;
    public ?string $reviewed_at;
    public ?string $paid_at;
    public ?string $ip_address;
    public ?string $user_agent;
    public ?string $device_fingerprint;
    public float $fraud_score;
    public ?string $behavior_data;
    public string $created_at;
    public ?string $updated_at;
    
    // JOIN fields
    public ?string $executor_name = null;
    public ?string $executor_email = null;
    public ?string $ad_title = null;
    public ?string $ad_platform = null;
    public ?string $ad_task_type = null;
    public ?string $social_username = null;
/**
     * پیدا کردن بر اساس ID
     */
    public function find(int $id): ?self
    {
        $stmt = $this->db->prepare("
            SELECT te.*, 
                   u.full_name as executor_name, u.email as executor_email,
                   a.title as ad_title, a.platform as ad_platform, a.task_type as ad_task_type,
                   sa.username as social_username
            FROM task_executions te
            JOIN users u ON u.id = te.executor_id
            JOIN advertisements a ON a.id = te.advertisement_id
            LEFT JOIN user_social_accounts sa ON sa.id = te.executor_social_account_id
            WHERE te.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }
    
    /**
     * بررسی آیا کاربر قبلاً این تسک را انجام داده
     */
    public function existsByAdAndExecutor(int $adId, int $executorId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM task_executions 
            WHERE advertisement_id = ? AND executor_id = ?
        ");
        $stmt->execute([$adId, $executorId]);
        return (int) $stmt->fetchColumn() > 0;
    }
    
    /**
     * بررسی Cooldown (فاصله زمانی بین تسک‌های همنوع)
     */
    public function getLastExecutionTime(int $executorId, string $taskType): ?string
    {
        $stmt = $this->db->prepare("
            SELECT te.started_at 
            FROM task_executions te
            JOIN advertisements a ON a.id = te.advertisement_id
            WHERE te.executor_id = ? AND a.task_type = ?
            ORDER BY te.started_at DESC
            LIMIT 1
        ");
        $stmt->execute([$executorId, $taskType]);
        $result = $stmt->fetchColumn();
        
        return $result ?: null;
    }
    
    /**
     * لیست تسک‌های انجام‌شده توسط کاربر
     */
    public function getByExecutor(int $executorId, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where = ['te.executor_id = ?'];
        $params = [$executorId];
        
        if (!empty($filters['status'])) {
            $where[] = 'te.status = ?';
            $params[] = $filters['status'];
        }
        
        $whereStr = \implode(' AND ', $where);
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare("
            SELECT te.*, 
                   a.title as ad_title, a.platform as ad_platform, 
                   a.task_type as ad_task_type, a.target_username
            FROM task_executions te
            JOIN advertisements a ON a.id = te.advertisement_id
            WHERE {$whereStr}
            ORDER BY te.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return \array_map([$this, 'hydrate'], $rows);
    }
    
    /**
     * تعداد تسک‌های کاربر
     */
    public function countByExecutor(int $executorId, array $filters = []): int
    {
        $where = ['te.executor_id = ?'];
        $params = [$executorId];
        
        if (!empty($filters['status'])) {
            $where[] = 'te.status = ?';
            $params[] = $filters['status'];
        }
        
        $whereStr = \implode(' AND ', $where);
        
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM task_executions te WHERE {$whereStr}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * لیست تسک‌های در انتظار بررسی برای تبلیغ‌دهنده
     */
    public function getPendingForAdvertiser(int $advertiserId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT te.*, 
                   u.full_name as executor_name,
                   a.title as ad_title, a.platform as ad_platform, a.task_type as ad_task_type,
                   sa.username as social_username, sa.profile_url as social_profile_url
            FROM task_executions te
            JOIN advertisements a ON a.id = te.advertisement_id
            JOIN users u ON u.id = te.executor_id
            LEFT JOIN user_social_accounts sa ON sa.id = te.executor_social_account_id
            WHERE a.advertiser_id = ? AND te.status = 'submitted'
            ORDER BY te.submitted_at ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$advertiserId, $limit, $offset]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return \array_map([$this, 'hydrate'], $rows);
    }
    
    /**
     * لیست همه (Admin) با فیلتر
     */
    public function getAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = 'te.status = ?';
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['advertisement_id'])) {
            $where[] = 'te.advertisement_id = ?';
            $params[] = $filters['advertisement_id'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = '(u.full_name LIKE ? OR a.title LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }
        
        $whereStr = \implode(' AND ', $where);
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare("
            SELECT te.*, 
                   u.full_name as executor_name, u.email as executor_email,
                   a.title as ad_title, a.platform as ad_platform, a.task_type as ad_task_type,
                   sa.username as social_username
            FROM task_executions te
            JOIN users u ON u.id = te.executor_id
            JOIN advertisements a ON a.id = te.advertisement_id
            LEFT JOIN user_social_accounts sa ON sa.id = te.executor_social_account_id
            WHERE {$whereStr}
            ORDER BY te.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return \array_map([$this, 'hydrate'], $rows);
    }
    
    /**
     * تعداد کل (Admin)
     */
    public function countAll(array $filters = []): int
    {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = 'te.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['advertisement_id'])) {
            $where[] = 'te.advertisement_id = ?';
            $params[] = $filters['advertisement_id'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(u.full_name LIKE ? OR a.title LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }
        
        $whereStr = \implode(' AND ', $where);
        
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM task_executions te
            JOIN users u ON u.id = te.executor_id
            JOIN advertisements a ON a.id = te.advertisement_id
            WHERE {$whereStr}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * ایجاد
     */
    public function create(array $data): ?self
    {
        $stmt = $this->db->prepare("
            INSERT INTO task_executions 
            (advertisement_id, executor_id, executor_social_account_id,
             reward_amount, reward_currency, idempotency_key,
             deadline_at, ip_address, user_agent, device_fingerprint,
             status, started_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'started', NOW())
        ");
        
        $result = $stmt->execute([
            $data['advertisement_id'],
            $data['executor_id'],
            $data['executor_social_account_id'] ?? null,
            $data['reward_amount'],
            $data['reward_currency'] ?? 'irt',
            $data['idempotency_key'] ?? str_random(32),
            $data['deadline_at'] ?? null,
            $data['ip_address'] ?? get_client_ip(),
            $data['user_agent'] ?? get_user_agent(),
            $data['device_fingerprint'] ?? generate_device_fingerprint(),
        ]);
        
        if ($result) {
            return $this->find((int) $this->db->lastInsertId());
        }
        
        return null;
    }
    
    /**
     * بروزرسانی
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];
        
        $allowed = [
            'proof_image', 'proof_metadata', 'status', 'rejection_reason',
            'reviewed_by', 'reviewer_id', 'reward_paid', 'commission_paid',
            'submitted_at', 'reviewed_at', 'paid_at',
            'fraud_score', 'behavior_data'
        ];
        
        foreach ($allowed as $field) {
            if (\array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $fields[] = "updated_at = NOW()";
        $params[] = $id;
        
        $fieldStr = \implode(', ', $fields);
        $stmt = $this->db->prepare("
            UPDATE task_executions SET {$fieldStr} WHERE id = ?
        ");
        
        return $stmt->execute($params);
    }
    
    /**
     * منقضی کردن تسک‌های بدون تحویل
     */
    public function expireOverdue(): int
    {
        $stmt = $this->db->prepare("
            UPDATE task_executions 
            SET status = 'expired', updated_at = NOW()
            WHERE status = 'started' 
              AND deadline_at IS NOT NULL 
              AND deadline_at < NOW()
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }
    
    /**
     * تسک‌های قابل بررسی مجدد (Re-check)
     * فقط follow/subscribe/join که 7+ روز گذشته
     */
    public function getRecheckCandidates(int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT te.*, a.task_type, a.target_url, a.target_username
            FROM task_executions te
            JOIN advertisements a ON a.id = te.advertisement_id
            WHERE te.status = 'approved'
              AND te.reward_paid = 1
              AND a.task_type IN ('follow','subscribe','join_channel','join_group')
              AND te.reviewed_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)
              AND te.id NOT IN (
                  SELECT original_execution_id FROM task_rechecks 
                  WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
              )
            ORDER BY RAND()
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return \array_map([$this, 'hydrate'], $rows);
    }
    
    /**
     * آمار کاربر
     */
    public function getUserStats(int $userId): object
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
                SUM(CASE WHEN reward_paid = 1 THEN reward_amount ELSE 0 END) as total_earned
            FROM task_executions WHERE executor_id = ?
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(\PDO::FETCH_OBJ);
    }
    
    /**
     * نام فارسی وضعیت
     */
    public function statusLabel(string $status): string
    {
        $labels = [
            'started'   => 'شروع شده',
            'submitted' => 'ارسال شده',
            'approved'  => 'تایید شده',
            'rejected'  => 'رد شده',
            'expired'   => 'منقضی شده',
            'disputed'  => 'اختلاف',
        ];
        return $labels[$status] ?? $status;
    }
    
    /**
     * کلاس CSS وضعیت
     */
    public function statusBadge(string $status): string
    {
        $badges = [
            'started'   => 'info',
            'submitted' => 'warning',
            'approved'  => 'success',
            'rejected'  => 'danger',
            'expired'   => 'secondary',
            'disputed'  => 'dark',
        ];
        return $badges[$status] ?? 'secondary';
    }
    
    /**
     * تبدیل ردیف به شیء
     */
    private function hydrate(array $row): self
    {
        $obj = new self();
        
        $obj->id = (int) $row['id'];
        $obj->advertisement_id = (int) $row['advertisement_id'];
        $obj->executor_id = (int) $row['executor_id'];
        $obj->executor_social_account_id = isset($row['executor_social_account_id']) ? (int) $row['executor_social_account_id'] : null;
        $obj->proof_image = $row['proof_image'] ?? null;
        $obj->proof_metadata = $row['proof_metadata'] ?? null;
        $obj->status = $row['status'];
        $obj->rejection_reason = $row['rejection_reason'] ?? null;
        $obj->reviewed_by = $row['reviewed_by'] ?? null;
        $obj->reviewer_id = isset($row['reviewer_id']) ? (int) $row['reviewer_id'] : null;
        $obj->reward_amount = (float) ($row['reward_amount'] ?? 0);
        $obj->reward_currency = $row['reward_currency'] ?? 'irt';
        $obj->reward_paid = (bool) ($row['reward_paid'] ?? false);
        $obj->commission_paid = (bool) ($row['commission_paid'] ?? false);
        $obj->idempotency_key = $row['idempotency_key'] ?? null;
        $obj->started_at = $row['started_at'];
        $obj->deadline_at = $row['deadline_at'] ?? null;
        $obj->submitted_at = $row['submitted_at'] ?? null;
        $obj->reviewed_at = $row['reviewed_at'] ?? null;
        $obj->paid_at = $row['paid_at'] ?? null;
        $obj->ip_address = $row['ip_address'] ?? null;
        $obj->user_agent = $row['user_agent'] ?? null;
        $obj->device_fingerprint = $row['device_fingerprint'] ?? null;
        $obj->fraud_score = (float) ($row['fraud_score'] ?? 0);
        $obj->behavior_data = $row['behavior_data'] ?? null;
        $obj->created_at = $row['created_at'];
        $obj->updated_at = $row['updated_at'] ?? null;
        
        // JOIN fields
        $obj->executor_name = $row['executor_name'] ?? null;
        $obj->executor_email = $row['executor_email'] ?? null;
        $obj->ad_title = $row['ad_title'] ?? null;
        $obj->ad_platform = $row['ad_platform'] ?? null;
        $obj->ad_task_type = $row['ad_task_type'] ?? null;
        $obj->social_username = $row['social_username'] ?? null;
        
        return $obj;
    }
}