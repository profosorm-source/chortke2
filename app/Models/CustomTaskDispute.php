<?php

namespace App\Models;

use Core\Model;

class CustomTaskDispute extends Model
{
    public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("
            SELECT d.*,
                   ct.title AS task_title,
                   u.full_name AS raised_by_name
            FROM task_disputes d
            LEFT JOIN custom_tasks ct ON ct.id = d.task_id
            LEFT JOIN users u ON u.id = d.raised_by
            WHERE d.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_OBJ);
        return $row ?: null;
    }

    public function create(array $data): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO task_disputes
            (task_id, submission_id, raised_by, reason, evidence_image, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'open', NOW(), NOW())
        ");

        $ok = $stmt->execute([
            (int) $data['task_id'],
            (int) $data['submission_id'],
            (int) $data['raised_by'],
            (string) $data['reason'],
            $data['evidence_image'] ?? null,
        ]);

        if (!$ok) {
            return null;
        }

        return $this->find((int) $this->db->lastInsertId());
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        $allowed = [
            'status',
            'admin_decision',
            'admin_id',
            'admin_note',
            'penalty_amount',
            'penalty_currency',
            'penalty_target',
            'site_tax_amount',
            'resolved_at',
        ];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_at = NOW()";
        $params[] = $id;

        $stmt = $this->db->prepare("UPDATE task_disputes SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($params);
    }

    public function hasOpenDispute(int $submissionId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM task_disputes
            WHERE submission_id = ?
              AND status IN ('open', 'under_review')
        ");
        $stmt->execute([$submissionId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where = ["d.task_id IS NOT NULL"];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "d.status = ?";
            $params[] = $filters['status'];
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT d.*,
                   ct.title AS task_title,
                   raiser.full_name AS raiser_name
            FROM task_disputes d
            LEFT JOIN custom_tasks ct ON ct.id = d.task_id
            LEFT JOIN users raiser ON raiser.id = d.raised_by
            WHERE {$whereStr}
            ORDER BY d.created_at DESC
            LIMIT ? OFFSET ?
        ");

        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function adminCount(array $filters = []): int
    {
        $where = ["d.task_id IS NOT NULL"];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "d.status = ?";
            $params[] = $filters['status'];
        }

        $whereStr = implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM task_disputes d WHERE {$whereStr}");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function statusLabel(string $status): string
    {
        $labels = [
            'open' => 'باز',
            'under_review' => 'در حال بررسی',
            'resolved_for_executor' => 'حل شده (به نفع انجام‌دهنده)',
            'resolved_for_advertiser' => 'حل شده (به نفع تبلیغ‌دهنده)',
            'closed' => 'بسته شده',
        ];
        return $labels[$status] ?? $status;
    }
}