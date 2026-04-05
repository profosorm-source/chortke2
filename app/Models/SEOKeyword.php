<?php
// app/Models/SEOKeyword.php

namespace App\Models;
use Core\Model;

use Core\Database;

class SEOKeyword extends Model {

    public int $id;
    public string $keyword;
    public string $target_url;
    public int $target_position;
    public int $scroll_min_seconds;
    public int $scroll_max_seconds;
    public int $pause_min_seconds;
    public int $pause_max_seconds;
    public int $total_browse_seconds;
    public int $max_per_hour;
    public int $max_per_day;
    public int $daily_budget;
    public float $reward_amount;
    public string $currency;
    public bool $is_active;
    public int $priority;
    public int $total_executions;
    public int $today_executions;
    public ?string $last_reset_date;
    public ?int $created_by;
    public string $created_at;
    public ?string $updated_at;
    public ?string $deleted_at;
public function find(int $id): ?self
    {
        $stmt = $this->db->prepare("
            SELECT * FROM seo_keywords WHERE id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * کلمات فعال برای انجام‌دهنده
     */
    public function getActiveForExecutor(int $executorId, int $limit = 10): array
    {
        $today = \date('Y-m-d');

        // ریست شمارنده روزانه
        $this->resetDailyCounters($today);

        $stmt = $this->db->prepare("
            SELECT k.* FROM seo_keywords k
            WHERE k.is_active = 1
              AND k.deleted_at IS NULL
              AND k.today_executions < k.daily_budget
              AND k.id NOT IN (
                  SELECT se.keyword_id FROM seo_executions se
                  WHERE se.executor_id = ? 
                    AND DATE(se.started_at) = ?
                    AND se.status IN ('completed','started','browsing')
              )
            ORDER BY k.priority DESC, RAND()
            LIMIT ?
        ");
        $stmt->execute([$executorId, $today, $limit]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return \array_map([$this, 'hydrate'], $rows);
    }

    /**
     * ریست شمارنده‌های روزانه
     */
    private function resetDailyCounters(string $today): void
    {
        $this->db->prepare("
            UPDATE seo_keywords 
            SET today_executions = 0, last_reset_date = ?
            WHERE last_reset_date IS NULL OR last_reset_date < ?
        ")->execute([$today, $today]);
    }

    /**
     * لیست همه (Admin)
     */
    public function getAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['deleted_at IS NULL'];
        $params = [];

        if (isset($filters['is_active'])) {
            $where[] = 'is_active = ?';
            $params[] = (int) $filters['is_active'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(keyword LIKE ? OR target_url LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }

        $whereStr = \implode(' AND ', $where);
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare("
            SELECT * FROM seo_keywords WHERE {$whereStr}
            ORDER BY priority DESC, created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return \array_map([$this, 'hydrate'], $rows);
    }

    public function countAll(array $filters = []): int
    {
        $where = ['deleted_at IS NULL'];
        $params = [];

        if (isset($filters['is_active'])) {
            $where[] = 'is_active = ?';
            $params[] = (int) $filters['is_active'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(keyword LIKE ? OR target_url LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }

        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM seo_keywords WHERE {$whereStr}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function create(array $data): ?self
    {
        $stmt = $this->db->prepare("
            INSERT INTO seo_keywords 
            (keyword, target_url, target_position,
             scroll_min_seconds, scroll_max_seconds,
             pause_min_seconds, pause_max_seconds,
             total_browse_seconds, max_per_hour, max_per_day, daily_budget,
             reward_amount, currency, is_active, priority, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $result = $stmt->execute([
            $data['keyword'],
            $data['target_url'],
            (int) ($data['target_position'] ?? 0),
            (int) ($data['scroll_min_seconds'] ?? 25),
            (int) ($data['scroll_max_seconds'] ?? 40),
            (int) ($data['pause_min_seconds'] ?? 3),
            (int) ($data['pause_max_seconds'] ?? 8),
            (int) ($data['total_browse_seconds'] ?? 60),
            (int) ($data['max_per_hour'] ?? 3),
            (int) ($data['max_per_day'] ?? 15),
            (int) ($data['daily_budget'] ?? 100),
            (float) ($data['reward_amount'] ?? 0),
            $data['currency'] ?? 'irt',
            (int) ($data['is_active'] ?? 1),
            (int) ($data['priority'] ?? 0),
            $data['created_by'] ?? null,
        ]);

        return $result ? $this->find((int) $this->db->lastInsertId()) : null;
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        $allowed = [
            'keyword', 'target_url', 'target_position',
            'scroll_min_seconds', 'scroll_max_seconds',
            'pause_min_seconds', 'pause_max_seconds',
            'total_browse_seconds', 'max_per_hour', 'max_per_day', 'daily_budget',
            'reward_amount', 'currency', 'is_active', 'priority',
            'total_executions', 'today_executions',
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
        $stmt = $this->db->prepare("UPDATE seo_keywords SET {$fieldStr} WHERE id = ?");
        return $stmt->execute($params);
    }

    public function incrementExecution(int $id): bool
    {
        $stmt = $this->db->prepare("
            UPDATE seo_keywords 
            SET total_executions = total_executions + 1,
                today_executions = today_executions + 1,
                updated_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$id]);
    }

    public function softDelete(int $id): bool
    {
        $stmt = $this->db->prepare("
            UPDATE seo_keywords SET deleted_at = NOW(), is_active = 0 WHERE id = ?
        ");
        return $stmt->execute([$id]);
    }

    public function getStats(): object
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                SUM(total_executions) as total_executions,
                SUM(today_executions) as today_executions
            FROM seo_keywords WHERE deleted_at IS NULL
        ");
        $stmt->execute();
        return $stmt->fetch(\PDO::FETCH_OBJ);
    }

    private function hydrate(array $row): self
    {
        $obj = new self();
        $obj->id = (int) $row['id'];
        $obj->keyword = $row['keyword'];
        $obj->target_url = $row['target_url'];
        $obj->target_position = (int) ($row['target_position'] ?? 0);
        $obj->scroll_min_seconds = (int) $row['scroll_min_seconds'];
        $obj->scroll_max_seconds = (int) $row['scroll_max_seconds'];
        $obj->pause_min_seconds = (int) $row['pause_min_seconds'];
        $obj->pause_max_seconds = (int) $row['pause_max_seconds'];
        $obj->total_browse_seconds = (int) $row['total_browse_seconds'];
        $obj->max_per_hour = (int) $row['max_per_hour'];
        $obj->max_per_day = (int) $row['max_per_day'];
        $obj->daily_budget = (int) $row['daily_budget'];
        $obj->reward_amount = (float) $row['reward_amount'];
        $obj->currency = $row['currency'] ?? 'irt';
        $obj->is_active = (bool) $row['is_active'];
        $obj->priority = (int) $row['priority'];
        $obj->total_executions = (int) $row['total_executions'];
        $obj->today_executions = (int) $row['today_executions'];
        $obj->last_reset_date = $row['last_reset_date'] ?? null;
        $obj->created_by = isset($row['created_by']) ? (int) $row['created_by'] : null;
        $obj->created_at = $row['created_at'];
        $obj->updated_at = $row['updated_at'] ?? null;
        $obj->deleted_at = $row['deleted_at'] ?? null;
        return $obj;
    }
}