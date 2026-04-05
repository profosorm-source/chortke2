<?php

namespace App\Models;

use Core\Database;
use Core\Model;

class Banner extends Model
{
    protected static string $table = 'banners';

    public ?int $id = null;
    public ?string $title = null;
    public ?string $image_path = null;
    public ?string $link = null;
    public ?string $placement = null;
    public ?string $type = null;
    public ?string $custom_code = null;
    public ?int $sort_order = 0;
    public ?bool $is_active = true;
    public ?string $start_date = null;
    public ?string $end_date = null;
    public ?int $clicks = 0;
    public ?int $impressions = 0;
    public ?float $ctr = 0.00;
    public ?string $target = '_blank';
    public ?string $alt_text = null;
    public ?int $created_by = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
    public ?string $deleted_at = null;

    /**
     * پیدا کردن بنر با ID
     */
    public function find(int $id): ?self
    {
        $row = $this->db->fetch(
            "SELECT * FROM " . static::$table . " WHERE id = :id AND deleted_at IS NULL",
            ['id' => $id]
        );
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * دریافت همه بنرها (با فیلتر)
     */
    public function all(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = ["b.deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['placement'])) {
            $where[] = "b.placement = :placement";
            $params['placement'] = $filters['placement'];
        }
        if (isset($filters['is_active'])) {
            $where[] = "b.is_active = :is_active";
            $params['is_active'] = (int)$filters['is_active'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(b.title LIKE :search OR b.link LIKE :search2)";
            $params['search'] = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
        }

        $whereStr = \implode(' AND ', $where);
        $sql = "SELECT b.*, u.full_name as creator_name 
                FROM " . static::$table . " b 
                LEFT JOIN users u ON b.created_by = u.id 
                WHERE {$whereStr} 
                ORDER BY b.sort_order ASC, b.created_at DESC 
                LIMIT :limit OFFSET :offset";

        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $rows = $this->db->fetchAll($sql, $params);
        return \array_map([$this, 'hydrate'], $rows);
    }

    /**
     * شمارش بنرها
     */
    public function count(array $filters = []): int
    {
        $where = ["deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['placement'])) {
            $where[] = "placement = :placement";
            $params['placement'] = $filters['placement'];
        }
        if (isset($filters['is_active'])) {
            $where[] = "is_active = :is_active";
            $params['is_active'] = (int)$filters['is_active'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(title LIKE :search OR link LIKE :search2)";
            $params['search'] = '%' . $filters['search'] . '%';
            $params['search2'] = '%' . $filters['search'] . '%';
        }

        $whereStr = \implode(' AND ', $where);
        return (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM " . static::$table . " WHERE {$whereStr}",
            $params
        );
    }

    /**
     * دریافت بنرهای فعال برای یک جایگاه
     */
    public function getActiveByPlacement(string $placement): array
    {
        $now = \date('Y-m-d H:i:s');
        $sql = "SELECT * FROM " . static::$table . " 
                WHERE placement = :placement 
                AND is_active = 1 
                AND deleted_at IS NULL
                AND (start_date IS NULL OR start_date <= :now1) 
                AND (end_date IS NULL OR end_date >= :now2) 
                ORDER BY sort_order ASC, (RAND() * id) 
                LIMIT 10";

        $rows = $this->db->fetchAll($sql, [
            'placement' => $placement,
            'now1' => $now,
            'now2' => $now,
        ]);
        return \array_map([$this, 'hydrate'], $rows);
    }

    /**
     * ایجاد بنر جدید
     */
    public function create(array $data): ?int
    {
        $fields = [
            'title', 'image_path', 'link', 'placement', 'type',
            'custom_code', 'sort_order', 'is_active', 'start_date',
            'end_date', 'target', 'alt_text', 'created_by'
        ];

        $insertData = [];
        foreach ($fields as $field) {
            if (\array_key_exists($field, $data)) {
                $insertData[$field] = $data[$field];
            }
        }
        $insertData['created_at'] = \date('Y-m-d H:i:s');

        $columns = \implode(', ', \array_keys($insertData));
        $placeholders = ':' . \implode(', :', \array_keys($insertData));

        $this->db->query(
            "INSERT INTO " . static::$table . " ({$columns}) VALUES ({$placeholders})",
            $insertData
        );

        return (int)$this->db->lastInsertId() ?: null;
    }

    /**
     * بروزرسانی بنر
     */
    public function update(int $id, array $data): bool
    {
        $allowed = [
            'title', 'image_path', 'link', 'placement', 'type',
            'custom_code', 'sort_order', 'is_active', 'start_date',
            'end_date', 'target', 'alt_text', 'clicks', 'impressions', 'ctr'
        ];

        $sets = [];
        $params = ['id' => $id];

        foreach ($allowed as $field) {
            if (\array_key_exists($field, $data)) {
                $sets[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($sets)) {
            return false;
        }

        $sets[] = "updated_at = :updated_at";
        $params['updated_at'] = \date('Y-m-d H:i:s');
        $setStr = \implode(', ', $sets);

        return $this->db->query(
            "UPDATE " . static::$table . " SET {$setStr} WHERE id = :id AND deleted_at IS NULL",
            $params
        );
    }

    /**
     * حذف نرم
     */
    public function softDelete(int $id): bool
    {
        return $this->db->query(
            "UPDATE " . static::$table . " SET deleted_at = :now, is_active = 0 WHERE id = :id",
            ['id' => $id, 'now' => \date('Y-m-d H:i:s')]
        );
    }

    /**
     * افزایش تعداد نمایش
     */
    public function incrementImpression(int $id): bool
    {
        return $this->db->query(
            "UPDATE " . static::$table . " SET impressions = impressions + 1, 
             ctr = CASE WHEN impressions > 0 THEN ROUND((clicks / (impressions + 1)) * 100, 2) ELSE 0 END
             WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * ثبت کلیک
     */
    public function registerClick(int $id, ?int $userId, string $ip, ?string $userAgent, ?string $referer, ?string $fingerprint): bool
    {
        $recentClick = $this->db->fetchColumn(
            "SELECT COUNT(*) FROM banner_clicks 
             WHERE banner_id = :bid AND ip_address = :ip AND clicked_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            ['bid' => $id, 'ip' => $ip]
        );

        if ((int)$recentClick > 0) {
            return false;
        }

        $this->db->query(
            "INSERT INTO banner_clicks (banner_id, user_id, ip_address, user_agent, referer, device_fingerprint, clicked_at)
             VALUES (:bid, :uid, :ip, :ua, :ref, :fp, NOW())",
            [
                'bid' => $id, 'uid' => $userId, 'ip' => $ip,
                'ua' => $userAgent ? \mb_substr($userAgent, 0, 500) : null,
                'ref' => $referer ? \mb_substr($referer, 0, 500) : null,
                'fp' => $fingerprint,
            ]
        );

        $this->db->query(
            "UPDATE " . static::$table . " SET clicks = clicks + 1,
             ctr = CASE WHEN impressions > 0 THEN ROUND(((clicks + 1) / impressions) * 100, 2) ELSE 0 END
             WHERE id = :id",
            ['id' => $id]
        );

        return true;
    }

    /**
     * بنرهای منقضی‌شده را غیرفعال کن
     */
    public function deactivateExpired(): int
    {
        $now = \date('Y-m-d H:i:s');
        $this->db->query(
            "UPDATE " . static::$table . " SET is_active = 0, updated_at = :now 
             WHERE is_active = 1 AND end_date IS NOT NULL AND end_date < :now2 AND deleted_at IS NULL",
            ['now' => $now, 'now2' => $now]
        );
        return $this->db->rowCount();
    }

    /**
     * آمار بنرها
     */
    public function getStats(): array
    {
        $table = static::$table;
        $total = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM {$table} WHERE deleted_at IS NULL");
        $active = (int)$this->db->fetchColumn("SELECT COUNT(*) FROM {$table} WHERE is_active = 1 AND deleted_at IS NULL");
        $totalClicks = (int)$this->db->fetchColumn("SELECT COALESCE(SUM(clicks), 0) FROM {$table} WHERE deleted_at IS NULL");
        $totalImpressions = (int)$this->db->fetchColumn("SELECT COALESCE(SUM(impressions), 0) FROM {$table} WHERE deleted_at IS NULL");
        $avgCtr = (float)$this->db->fetchColumn("SELECT COALESCE(AVG(ctr), 0) FROM {$table} WHERE deleted_at IS NULL AND impressions > 0");
        $expiringSoon = (int)$this->db->fetchColumn(
            "SELECT COUNT(*) FROM {$table} 
             WHERE is_active = 1 AND end_date IS NOT NULL 
             AND end_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY) 
             AND deleted_at IS NULL"
        );

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'total_clicks' => $totalClicks,
            'total_impressions' => $totalImpressions,
            'avg_ctr' => \round($avgCtr, 2),
            'expiring_soon' => $expiringSoon,
        ];
    }

    /**
     * آمار کلیک بر اساس روز
     */
    public function getClickStats(int $bannerId, int $days = 30): array
    {
        return $this->db->fetchAll(
            "SELECT DATE(clicked_at) as date, COUNT(*) as click_count 
             FROM banner_clicks 
             WHERE banner_id = :bid AND clicked_at > DATE_SUB(NOW(), INTERVAL :days DAY)
             GROUP BY DATE(clicked_at) 
             ORDER BY date ASC",
            ['bid' => $bannerId, 'days' => $days]
        );
    }

    /**
     * تبدیل آرایه DB به شیء
     */
    protected function hydrate($row): self
    {
        $obj = new self();
        if (\is_array($row)) {
            $row = (object)$row;
        }

        $obj->id = isset($row->id) ? (int)$row->id : null;
        $obj->title = $row->title ?? null;
        $obj->image_path = $row->image_path ?? null;
        $obj->link = $row->link ?? null;
        $obj->placement = $row->placement ?? null;
        $obj->type = $row->type ?? null;
        $obj->custom_code = $row->custom_code ?? null;
        $obj->sort_order = isset($row->sort_order) ? (int)$row->sort_order : 0;
        $obj->is_active = isset($row->is_active) ? (bool)$row->is_active : true;
        $obj->start_date = $row->start_date ?? null;
        $obj->end_date = $row->end_date ?? null;
        $obj->clicks = isset($row->clicks) ? (int)$row->clicks : 0;
        $obj->impressions = isset($row->impressions) ? (int)$row->impressions : 0;
        $obj->ctr = isset($row->ctr) ? (float)$row->ctr : 0.00;
        $obj->target = $row->target ?? '_blank';
        $obj->alt_text = $row->alt_text ?? null;
        $obj->created_by = isset($row->created_by) ? (int)$row->created_by : null;
        $obj->created_at = $row->created_at ?? null;
        $obj->updated_at = $row->updated_at ?? null;
        $obj->deleted_at = $row->deleted_at ?? null;

        if (isset($row->creator_name)) {
            $obj->creator_name = $row->creator_name;
        }

        return $obj;
    }
}
