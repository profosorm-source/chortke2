<?php

namespace App\Models;
use Core\Model;

class CustomTaskSubmission extends Model {
    
    public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("
            SELECT s.*, ct.title AS task_title, ct.creator_id, ct.proof_type,
                   w.full_name AS worker_name, w.email AS worker_email
            FROM custom_task_submissions s
            LEFT JOIN custom_tasks ct ON ct.id = s.task_id
            LEFT JOIN users w ON w.id = s.worker_id
            WHERE s.id = ?
        ");
        $stmt->execute([$id]);
        $r = $stmt->fetch(\PDO::FETCH_OBJ);
        return $r ?: null;
    }

    public function create(array $d): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO custom_task_submissions
            (task_id, worker_id, deadline_at, status, reward_amount, reward_currency,
             idempotency_key, worker_ip, worker_device, worker_fingerprint)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $result = $stmt->execute([
            $d['task_id'], 
            $d['worker_id'], 
            $d['deadline_at'],
            $d['status'] ?? 'in_progress', 
            $d['reward_amount'] ?? 0,
            $d['reward_currency'] ?? 'irt', 
            $d['idempotency_key'],
            $d['worker_ip'] ?? get_client_ip(),
            $d['worker_device'] ?? get_user_agent(),
            $d['worker_fingerprint'] ?? generate_device_fingerprint(),
        ]);
        
        if (!$result) return null;
        return $this->find((int) $this->db->lastInsertId());
    }

    public function update(int $id, array $data): bool
    {
        $fields = []; 
        $values = [];
        
        $allowed = [
            'proof_text', 'proof_file', 'proof_file_hash', 'submitted_at', 'reviewed_at',
            'status', 'rejection_reason', 'reward_paid', 'reward_transaction_id', 'metadata'
        ];
        
        foreach ($allowed as $f) {
            if (\array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?";
                $values[] = $data[$f];
            }
        }
        
        if (empty($fields)) return false;
        
        $values[] = $id;
        $stmt = $this->db->prepare("UPDATE custom_task_submissions SET " . \implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($values);
    }

    /**
     * بررسی آیا کاربر قبلاً این تسک را انجام داده
     */
    public function hasWorkerDone(int $taskId, int $workerId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM custom_task_submissions
            WHERE task_id = ? AND worker_id = ? AND status NOT IN ('expired','rejected')
        ");
        $stmt->execute([$taskId, $workerId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * تعداد تسک‌های انجام‌شده کاربر امروز
     */
    public function todayCount(int $workerId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM custom_task_submissions
            WHERE worker_id = ? AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute([$workerId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * بررسی هش تکراری تصویر
     */
    public function isDuplicateImage(string $hash, int $taskId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM custom_task_submissions
            WHERE proof_file_hash = ? AND task_id = ? AND status != 'rejected'
        ");
        $stmt->execute([$hash, $taskId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * submission‌های یک تسک (برای تبلیغ‌دهنده)
     */
    public function getByTask(int $taskId, ?string $status = null, int $limit = 30, int $offset = 0): array
    {
        $where = ["s.task_id = ?"];
        $params = [$taskId];
        
        if ($status) { 
            $where[] = "s.status = ?"; 
            $params[] = $status; 
        }
        
        $whereStr = \implode(' AND ', $where);
        
        $stmt = $this->db->prepare("
            SELECT s.*, w.full_name AS worker_name, w.email AS worker_email
            FROM custom_task_submissions s
            LEFT JOIN users w ON w.id = s.worker_id
            WHERE {$whereStr} 
            ORDER BY s.created_at DESC 
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit; 
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * submission‌های کاربر (تاریخچه)
     */
    public function getByWorker(int $workerId, ?string $status = null, int $limit = 20, int $offset = 0): array
    {
        $where = ["s.worker_id = ?"];
        $params = [$workerId];
        
        if ($status) { 
            $where[] = "s.status = ?"; 
            $params[] = $status; 
        }
        
        $whereStr = \implode(' AND ', $where);
        
        $stmt = $this->db->prepare("
            SELECT s.*, ct.title AS task_title, ct.price_per_task, ct.currency
            FROM custom_task_submissions s
            LEFT JOIN custom_tasks ct ON ct.id = s.task_id
            WHERE {$whereStr} 
            ORDER BY s.created_at DESC 
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit; 
        $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * submission‌های منقضی‌نشده (CronJob)
     */
    public function getExpiredSubmissions(): array
    {
        $stmt = $this->db->prepare("
            SELECT id, task_id FROM custom_task_submissions
            WHERE status = 'in_progress' AND deadline_at <= NOW()
        ");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * submission‌های بررسی‌نشده (auto-approve/expire)
     */
    public function getUnreviewedSubmissions(int $hours = 48): array
    {
        $stmt = $this->db->prepare("
            SELECT id, task_id FROM custom_task_submissions
            WHERE status = 'submitted' AND submitted_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$hours]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function statusLabels(): array
    {
        return [
            'in_progress' => 'در حال انجام', 
            'submitted' => 'ارسال شده',
            'approved' => 'تأیید شده', 
            'rejected' => 'رد شده',
            'expired' => 'منقضی', 
            'disputed' => 'در اختلاف',
        ];
    }

    public function statusClasses(): array
    {
        return [
            'in_progress' => 'badge-info', 
            'submitted' => 'badge-warning',
            'approved' => 'badge-success', 
            'rejected' => 'badge-danger',
            'expired' => 'badge-secondary', 
            'disputed' => 'badge-danger',
        ];
    }
}
