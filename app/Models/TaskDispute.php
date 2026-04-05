<?php
// app/Models/TaskDispute.php

namespace App\Models;
use Core\Model;

class TaskDispute extends Model {
    
    public int $id;
    public int $execution_id;
    public int $advertisement_id;
    public string $opened_by;
    public int $opener_id;
    public string $reason;
    public ?string $evidence_image;
    public string $status;
    public ?string $admin_decision;
    public ?int $admin_id;
    public float $penalty_amount;
    public string $penalty_currency;
    public ?string $penalty_target;
    public float $site_tax_amount;
    public ?string $resolved_at;
    public string $created_at;
    public ?string $updated_at;

    // JOIN fields
    public ?string $opener_name = null;
    public ?string $ad_title = null;
public function find(int $id): ?self
    {
        $stmt = $this->db->prepare("
            SELECT td.*, u.full_name as opener_name, a.title as ad_title
            FROM task_disputes td
            JOIN users u ON u.id = td.opener_id
            JOIN advertisements a ON a.id = td.advertisement_id
            WHERE td.id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }
    
    public function findByExecution(int $executionId): ?self
    {
        $stmt = $this->db->prepare("
            SELECT td.*, u.full_name as opener_name, a.title as ad_title
            FROM task_disputes td
            JOIN users u ON u.id = td.opener_id
            JOIN advertisements a ON a.id = td.advertisement_id
            WHERE td.execution_id = ? AND td.status IN ('open','under_review')
            ORDER BY td.created_at DESC LIMIT 1
        ");
        $stmt->execute([$executionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }
    
    public function getAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = 'td.status = ?';
            $params[] = $filters['status'];
        }
        
        $whereStr = \implode(' AND ', $where);
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare("
            SELECT td.*, 
                   u.full_name as opener_name,
                   a.title as ad_title, a.platform as ad_platform
            FROM task_disputes td
            JOIN users u ON u.id = td.opener_id
            JOIN advertisements a ON a.id = td.advertisement_id
            WHERE {$whereStr}
            ORDER BY td.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return \array_map([$this, 'hydrate'], $rows);
    }
    
    public function countAll(array $filters = []): int
    {
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = 'td.status = ?';
            $params[] = $filters['status'];
        }
        
        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM task_disputes td WHERE {$whereStr}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
    
    public function create(array $data): ?self
    {
        $stmt = $this->db->prepare("
            INSERT INTO task_disputes 
            (execution_id, advertisement_id, opened_by, opener_id, reason, evidence_image, penalty_currency)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $data['execution_id'],
            $data['advertisement_id'],
            $data['opened_by'],
            $data['opener_id'],
            $data['reason'],
            $data['evidence_image'] ?? null,
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
        
        $allowed = [
            'status', 'admin_decision', 'admin_id',
            'penalty_amount', 'penalty_target', 'site_tax_amount',
            'resolved_at'
        ];
        
        foreach ($allowed as $field) {
            if (\array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($fields)) return false;
        
        $fields[] = "updated_at = NOW()";
        $params[] = $id;
        
        $fieldStr = \implode(', ', $fields);
        $stmt = $this->db->prepare("UPDATE task_disputes SET {$fieldStr} WHERE id = ?");
        return $stmt->execute($params);
    }
    
	
    public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where = ["1=1"];
        $params = [];
        if (!empty($filters['status'])) { $where[] = "d.status = ?"; $params[] = $filters['status']; }
        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("
            SELECT d.*, ct.title AS task_title, raiser.full_name AS raiser_name
            FROM task_disputes d
            LEFT JOIN custom_tasks ct ON ct.id = d.task_id
            LEFT JOIN users raiser ON raiser.id = d.raised_by
            WHERE {$whereStr} ORDER BY d.created_at DESC LIMIT ? OFFSET ?
        ");
        $params[] = $limit; $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function adminCount(array $filters = []): int
    {
        $where = ["1=1"];
        $params = [];
        if (!empty($filters['status'])) { $where[] = "d.status = ?"; $params[] = $filters['status']; }
        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM task_disputes d WHERE {$whereStr}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function hasOpenDispute(int $submissionId): bool
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM task_disputes WHERE submission_id = ? AND status IN ('open','under_review')");
        $stmt->execute([$submissionId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function statusLabel(string $status): string
    {
        $labels = [
            'open'                       => 'باز',
            'under_review'               => 'در حال بررسی',
            'resolved_for_executor'      => 'حل شده (به نفع انجام‌دهنده)',
            'resolved_for_advertiser'    => 'حل شده (به نفع تبلیغ‌دهنده)',
            'closed'                     => 'بسته شده',
        ];
        return $labels[$status] ?? $status;
    }
    
    public function statusBadge(string $status): string
    {
        $badges = [
            'open'                       => 'warning',
            'under_review'               => 'info',
            'resolved_for_executor'      => 'success',
            'resolved_for_advertiser'    => 'primary',
            'closed'                     => 'secondary',
        ];
        return $badges[$status] ?? 'secondary';
    }
    
    private function hydrate(array $row): self
    {
        $obj = new self();
        
        $obj->id = (int) $row['id'];
        $obj->execution_id = (int) $row['execution_id'];
        $obj->advertisement_id = (int) $row['advertisement_id'];
        $obj->opened_by = $row['opened_by'];
        $obj->opener_id = (int) $row['opener_id'];
        $obj->reason = $row['reason'];
        $obj->evidence_image = $row['evidence_image'] ?? null;
        $obj->status = $row['status'];
        $obj->admin_decision = $row['admin_decision'] ?? null;
        $obj->admin_id = isset($row['admin_id']) ? (int) $row['admin_id'] : null;
        $obj->penalty_amount = (float) ($row['penalty_amount'] ?? 0);
        $obj->penalty_currency = $row['penalty_currency'] ?? 'irt';
        $obj->penalty_target = $row['penalty_target'] ?? null;
        $obj->site_tax_amount = (float) ($row['site_tax_amount'] ?? 0);
        $obj->resolved_at = $row['resolved_at'] ?? null;
        $obj->created_at = $row['created_at'];
        $obj->updated_at = $row['updated_at'] ?? null;
        
        $obj->opener_name = $row['opener_name'] ?? null;
        $obj->ad_title = $row['ad_title'] ?? null;
        
        return $obj;
    }
}