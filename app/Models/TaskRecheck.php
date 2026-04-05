<?php
// app/Models/TaskRecheck.php

namespace App\Models;
use Core\Model;

class TaskRecheck extends Model {
    
    public int $id;
    public int $original_execution_id;
    public int $advertisement_id;
    public int $executor_id;
    public string $status;
    public float $penalty_amount;
    public string $penalty_currency;
    public bool $refunded_to_advertiser;
    public ?string $checked_at;
    public string $created_at;
public function find(int $id): ?self
    {
        $stmt = $this->db->prepare("SELECT * FROM task_rechecks WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }
    
    public function create(array $data): ?self
    {
        $stmt = $this->db->prepare("
            INSERT INTO task_rechecks 
            (original_execution_id, advertisement_id, executor_id, penalty_currency)
            VALUES (?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $data['original_execution_id'],
            $data['advertisement_id'],
            $data['executor_id'],
            $data['penalty_currency'] ?? 'irt',
        ]);
        
        if ($result) {
            return $this->find((int) $this->db->lastInsertId());
        }
        return null;
    }
    
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];
        
        $allowed = ['status', 'penalty_amount', 'refunded_to_advertiser', 'checked_at'];
        
        foreach ($allowed as $field) {
            if (\array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($fields)) return false;
        
        $params[] = $id;
        $fieldStr = \implode(', ', $fields);
        $stmt = $this->db->prepare("UPDATE task_rechecks SET {$fieldStr} WHERE id = ?");
        return $stmt->execute($params);
    }
    
    public function getByExecutor(int $executorId, int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT tr.*, a.title as ad_title, a.platform, a.task_type, a.target_username
            FROM task_rechecks tr
            JOIN advertisements a ON a.id = tr.advertisement_id
            WHERE tr.executor_id = ?
            ORDER BY tr.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$executorId, $limit]);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }
    
    public function getAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = 'tr.status = ?';
            $params[] = $filters['status'];
        }
        
        $whereStr = \implode(' AND ', $where);
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare("
            SELECT tr.*, u.full_name as executor_name, a.title as ad_title
            FROM task_rechecks tr
            JOIN users u ON u.id = tr.executor_id
            JOIN advertisements a ON a.id = tr.advertisement_id
            WHERE {$whereStr}
            ORDER BY tr.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }
    
    private function hydrate(array $row): self
    {
        $obj = new self();
        $obj->id = (int) $row['id'];
        $obj->original_execution_id = (int) $row['original_execution_id'];
        $obj->advertisement_id = (int) $row['advertisement_id'];
        $obj->executor_id = (int) $row['executor_id'];
        $obj->status = $row['status'];
        $obj->penalty_amount = (float) ($row['penalty_amount'] ?? 0);
        $obj->penalty_currency = $row['penalty_currency'] ?? 'irt';
        $obj->refunded_to_advertiser = (bool) ($row['refunded_to_advertiser'] ?? false);
        $obj->checked_at = $row['checked_at'] ?? null;
        $obj->created_at = $row['created_at'];
        return $obj;
    }
}