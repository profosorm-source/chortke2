<?php
// app/Models/Advertisement.php

namespace App\Models;
use Core\Model;

use Core\Database;

class Advertisement extends Model {
    
    public int $id;
    public int $advertiser_id;
    public string $platform;
    public string $task_type;
    public string $target_url;
    public ?string $target_username;
    public string $title;
    public ?string $description;
    public ?string $sample_image;
    public string $currency;
    public float $price_per_task;
    public float $total_budget;
    public float $remaining_budget;
    public float $site_commission_percent;
    public float $tax_percent;
    public int $total_count;
    public int $remaining_count;
    public int $completed_count;
    public ?string $restrictions;
    public string $status;
    public ?string $rejection_reason;
    public ?string $start_date;
    public ?string $end_date;
    public ?int $created_by;
    public ?int $approved_by;
    public ?string $approved_at;
    public string $created_at;
    public ?string $updated_at;
    public ?string $deleted_at;
    
    // JOIN fields
    public ?string $advertiser_name = null;
    public ?string $advertiser_email = null;
/**
     * پیدا کردن بر اساس ID
     */
    public function find(int $id): ?self
    {
        $stmt = $this->db->prepare("
            SELECT a.*, u.full_name as advertiser_name, u.email as advertiser_email
            FROM advertisements a
            JOIN users u ON u.id = a.advertiser_id
            WHERE a.id = ? AND a.deleted_at IS NULL
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }
    
    /**
     * لیست تبلیغات فعال برای انجام‌دهنده‌ها
     * رندوم + mix پلتفرم‌ها
     */
    public function getActiveForExecutor(int $executorId, int $limit = 20): array
    {
        $stmt = $this->db->prepare("
            SELECT a.*, u.full_name as advertiser_name
            FROM advertisements a
            JOIN users u ON u.id = a.advertiser_id
            WHERE a.status = 'active'
              AND a.remaining_count > 0
              AND a.remaining_budget > 0
              AND a.deleted_at IS NULL
              AND (a.start_date IS NULL OR a.start_date <= NOW())
              AND (a.end_date IS NULL OR a.end_date >= NOW())
              AND a.advertiser_id != ?
              AND a.id NOT IN (
                  SELECT advertisement_id FROM task_executions 
                  WHERE executor_id = ?
              )
            ORDER BY RAND()
            LIMIT ?
        ");
        $stmt->execute([$executorId, $executorId, $limit]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return \array_map([$this, 'hydrate'], $rows);
    }
    
    /**
     * لیست تبلیغات یک تبلیغ‌دهنده
     */
    public function getByAdvertiser(int $advertiserId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM advertisements 
            WHERE advertiser_id = ? AND deleted_at IS NULL
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$advertiserId, $limit, $offset]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return \array_map([$this, 'hydrate'], $rows);
    }
    
    /**
     * تعداد تبلیغات تبلیغ‌دهنده
     */
    public function countByAdvertiser(int $advertiserId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM advertisements 
            WHERE advertiser_id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$advertiserId]);
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * لیست همه (Admin) با فیلتر
     */
    public function getAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ['a.deleted_at IS NULL'];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = 'a.status = ?';
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['platform'])) {
            $where[] = 'a.platform = ?';
            $params[] = $filters['platform'];
        }
        
        if (!empty($filters['task_type'])) {
            $where[] = 'a.task_type = ?';
            $params[] = $filters['task_type'];
        }
        
        if (!empty($filters['search'])) {
            $where[] = '(a.title LIKE ? OR a.target_username LIKE ? OR u.full_name LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }
        
        $whereStr = \implode(' AND ', $where);
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare("
            SELECT a.*, u.full_name as advertiser_name, u.email as advertiser_email
            FROM advertisements a
            JOIN users u ON u.id = a.advertiser_id
            WHERE {$whereStr}
            ORDER BY a.created_at DESC
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
        $where = ['a.deleted_at IS NULL'];
        $params = [];
        
        if (!empty($filters['status'])) {
            $where[] = 'a.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['platform'])) {
            $where[] = 'a.platform = ?';
            $params[] = $filters['platform'];
        }
        if (!empty($filters['task_type'])) {
            $where[] = 'a.task_type = ?';
            $params[] = $filters['task_type'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(a.title LIKE ? OR a.target_username LIKE ? OR u.full_name LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }
        
        $whereStr = \implode(' AND ', $where);
        
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM advertisements a
            JOIN users u ON u.id = a.advertiser_id
            WHERE {$whereStr}
        ");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * ایجاد تبلیغ جدید
     */
    public function create(array $data): ?self
    {
        $stmt = $this->db->prepare("
            INSERT INTO advertisements 
            (advertiser_id, platform, task_type, target_url, target_username,
             title, description, sample_image, currency,
             price_per_task, total_budget, remaining_budget,
             site_commission_percent, tax_percent,
             total_count, remaining_count,
             restrictions, status, start_date, end_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $data['advertiser_id'],
            $data['platform'],
            $data['task_type'],
            $data['target_url'],
            $data['target_username'] ?? null,
            $data['title'],
            $data['description'] ?? null,
            $data['sample_image'] ?? null,
            $data['currency'] ?? 'irt',
            $data['price_per_task'],
            $data['total_budget'],
            $data['total_budget'], // remaining = total
            $data['site_commission_percent'] ?? 10.00,
            $data['tax_percent'] ?? 0.00,
            $data['total_count'],
            $data['total_count'], // remaining = total
            isset($data['restrictions']) ? \json_encode($data['restrictions']) : null,
            $data['start_date'] ?? null,
            $data['end_date'] ?? null,
            $data['created_by'] ?? $data['advertiser_id'],
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
            'title', 'description', 'sample_image', 'target_url', 'target_username',
            'price_per_task', 'total_budget', 'remaining_budget',
            'site_commission_percent', 'tax_percent',
            'total_count', 'remaining_count', 'completed_count',
            'restrictions', 'status', 'rejection_reason',
            'start_date', 'end_date',
            'approved_by', 'approved_at'
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
            UPDATE advertisements SET {$fieldStr} WHERE id = ?
        ");
        
        return $stmt->execute($params);
    }
    
    /**
     * کاهش تعداد و بودجه باقیمانده
     */
    public function decrementRemaining(int $id, float $amount): bool
    {
        $stmt = $this->db->prepare("
            UPDATE advertisements 
            SET remaining_count = remaining_count - 1,
                remaining_budget = remaining_budget - ?,
                completed_count = completed_count + 1,
                updated_at = NOW()
            WHERE id = ? AND remaining_count > 0 AND remaining_budget >= ?
        ");
        return $stmt->execute([$amount, $id, $amount]);
    }
    
    /**
     * بازگشت تعداد و بودجه (در صورت رد یا اختلاف)
     */
    public function incrementRemaining(int $id, float $amount): bool
    {
        $stmt = $this->db->prepare("
            UPDATE advertisements 
            SET remaining_count = remaining_count + 1,
                remaining_budget = remaining_budget + ?,
                completed_count = GREATEST(0, completed_count - 1),
                updated_at = NOW()
            WHERE id = ?
        ");
        return $stmt->execute([$amount, $id]);
    }
    
    /**
     * Soft Delete
     */
    public function softDelete(int $id): bool
    {
        $stmt = $this->db->prepare("
            UPDATE advertisements 
            SET deleted_at = NOW(), status = 'cancelled', updated_at = NOW() 
            WHERE id = ?
        ");
        return $stmt->execute([$id]);
    }
    
    /**
     * تکمیل خودکار اگر تعداد باقیمانده صفر شد
     */
    public function checkAndComplete(int $id): bool
    {
        $stmt = $this->db->prepare("
            UPDATE advertisements 
            SET status = 'completed', updated_at = NOW()
            WHERE id = ? AND remaining_count <= 0 AND status = 'active'
        ");
        return $stmt->execute([$id]);
    }
    
    /**
     * آمار تبلیغات (Admin Dashboard)
     */
    public function getStats(): object
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(total_budget) as total_budget,
                SUM(total_budget - remaining_budget) as spent_budget
            FROM advertisements WHERE deleted_at IS NULL
        ");
        $stmt->execute();
        return $stmt->fetch(\PDO::FETCH_OBJ);
    }
    
    /**
     * نام فارسی نوع تسک
     */
    public function taskTypeLabel(string $type): string
    {
        $labels = [
            'follow'       => 'فالو',
            'subscribe'    => 'سابسکرایب',
            'join_channel' => 'عضویت کانال',
            'join_group'   => 'عضویت گروه',
            'like'         => 'لایک',
            'comment'      => 'کامنت',
            'view'         => 'بازدید ویدیو',
            'story_view'   => 'بازدید استوری',
        ];
        return $labels[$type] ?? $type;
    }
    
    /**
     * نام فارسی وضعیت
     */
    public function statusLabel(string $status): string
    {
        $labels = [
            'pending'   => 'در انتظار تایید',
            'active'    => 'فعال',
            'paused'    => 'متوقف',
            'completed' => 'تکمیل شده',
            'cancelled' => 'لغو شده',
            'rejected'  => 'رد شده',
        ];
        return $labels[$status] ?? $status;
    }
    
    /**
     * کلاس CSS وضعیت
     */
    public function statusBadge(string $status): string
    {
        $badges = [
            'pending'   => 'warning',
            'active'    => 'success',
            'paused'    => 'info',
            'completed' => 'primary',
            'cancelled' => 'secondary',
            'rejected'  => 'danger',
        ];
        return $badges[$status] ?? 'secondary';
    }
    
    /**
     * آیا این تسک نیاز به حساب اجتماعی تایید‌شده دارد؟
     */
    public function requiresSocialAccount(): bool
    {
        return \in_array($this->task_type, ['follow', 'subscribe', 'like', 'comment', 'story_view']);
    }
    
    /**
     * آیا این تسک قابل بررسی مجدد است؟ (فالو/سابسکرایب/عضویت)
     */
    public function isRecheckable(): bool
    {
        return \in_array($this->task_type, ['follow', 'subscribe', 'join_channel', 'join_group']);
    }
    
    /**
     * محدودیت زمانی بین تسک‌ها (دقیقه)
     */
    public function getCooldownMinutes(): int
    {
        $cooldowns = [
            'follow'       => 30,
            'subscribe'    => 60,
            'join_channel' => 30,
            'join_group'   => 30,
            'like'         => 5,
            'comment'      => 10,
            'view'         => 5,
            'story_view'   => 5,
        ];
        return $cooldowns[$this->task_type] ?? 10;
    }
    
    /**
     * دریافت محدودیت‌ها (parsed)
     */
    public function getRestrictions(): object
    {
        if ($this->restrictions) {
            $parsed = \json_decode($this->restrictions);
            return \is_object($parsed) ? $parsed : (object)[];
        }
        return (object)[];
    }
    
    /**
     * تبدیل ردیف به شیء
     */
    private function hydrate(array $row): self
    {
        $obj = new self();
        
        $obj->id = (int) $row['id'];
        $obj->advertiser_id = (int) $row['advertiser_id'];
        $obj->platform = $row['platform'];
        $obj->task_type = $row['task_type'];
        $obj->target_url = $row['target_url'];
        $obj->target_username = $row['target_username'] ?? null;
        $obj->title = $row['title'];
        $obj->description = $row['description'] ?? null;
        $obj->sample_image = $row['sample_image'] ?? null;
        $obj->currency = $row['currency'] ?? 'irt';
        $obj->price_per_task = (float) $row['price_per_task'];
        $obj->total_budget = (float) $row['total_budget'];
        $obj->remaining_budget = (float) $row['remaining_budget'];
        $obj->site_commission_percent = (float) $row['site_commission_percent'];
        $obj->tax_percent = (float) $row['tax_percent'];
        $obj->total_count = (int) $row['total_count'];
        $obj->remaining_count = (int) $row['remaining_count'];
        $obj->completed_count = (int) $row['completed_count'];
        $obj->restrictions = $row['restrictions'] ?? null;
        $obj->status = $row['status'];
        $obj->rejection_reason = $row['rejection_reason'] ?? null;
        $obj->start_date = $row['start_date'] ?? null;
        $obj->end_date = $row['end_date'] ?? null;
        $obj->created_by = isset($row['created_by']) ? (int) $row['created_by'] : null;
        $obj->approved_by = isset($row['approved_by']) ? (int) $row['approved_by'] : null;
        $obj->approved_at = $row['approved_at'] ?? null;
        $obj->created_at = $row['created_at'];
        $obj->updated_at = $row['updated_at'] ?? null;
        $obj->deleted_at = $row['deleted_at'] ?? null;
        
        // JOIN fields
        $obj->advertiser_name = $row['advertiser_name'] ?? null;
        $obj->advertiser_email = $row['advertiser_email'] ?? null;
        
        return $obj;
    }
}