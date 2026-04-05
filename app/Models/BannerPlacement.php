<?php

namespace App\Models;

use Core\Database;
use Core\Model;

class BannerPlacement extends Model
{
    protected static string $table = 'banner_placements';

    public ?int $id = null;
    public ?string $slug = null;
    public ?string $title = null;
    public ?string $description = null;
    public ?bool $is_active = true;
    public ?int $max_banners = 5;
    public ?int $rotation_speed = 5000;
    public ?int $max_width = null;
    public ?int $max_height = null;

    public function all(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $rows = $this->db->fetchAll("SELECT * FROM " . static::$table . " ORDER BY id ASC");
        return \array_map([$this, 'hydrate'], $rows);
    }

    public function findBySlug(string $slug): ?self
    {
        $row = $this->db->fetch(
            "SELECT * FROM " . static::$table . " WHERE slug = :slug",
            ['slug' => $slug]
        );
        return $row ? $this->hydrate($row) : null;
    }

    public function find(int $id): ?self
    {
        $row = $this->db->fetch(
            "SELECT * FROM " . static::$table . " WHERE id = :id",
            ['id' => $id]
        );
        return $row ? $this->hydrate($row) : null;
    }

    public function update(int $id, array $data): bool
    {
        $allowed = ['title', 'description', 'is_active', 'max_banners', 'rotation_speed', 'max_width', 'max_height'];
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
            "UPDATE " . static::$table . " SET {$setStr} WHERE id = :id",
            $params
        );
    }

    public function allWithBannerCount(): array
    {
        $now = \date('Y-m-d H:i:s');
        $rows = $this->db->fetchAll(
            "SELECT bp.*, 
                    (SELECT COUNT(*) FROM banners b 
                     WHERE b.placement = bp.slug AND b.is_active = 1 AND b.deleted_at IS NULL
                     AND (b.start_date IS NULL OR b.start_date <= :now1)
                     AND (b.end_date IS NULL OR b.end_date >= :now2)) as active_banners
             FROM " . static::$table . " bp ORDER BY bp.id ASC",
            ['now1' => $now, 'now2' => $now]
        );

        return \array_map(function ($row) {
            $obj = $this->hydrate($row);
            $obj->active_banners = (int)(is_array($row) ? ($row['active_banners'] ?? 0) : ($row->active_banners ?? 0));
            return $obj;
        }, $rows);
    }

    protected function hydrate($row): self
    {
        $obj = new self();
        if (\is_array($row)) {
            $row = (object)$row;
        }

        $obj->id = isset($row->id) ? (int)$row->id : null;
        $obj->slug = $row->slug ?? null;
        $obj->title = $row->title ?? null;
        $obj->description = $row->description ?? null;
        $obj->is_active = isset($row->is_active) ? (bool)$row->is_active : true;
        $obj->max_banners = isset($row->max_banners) ? (int)$row->max_banners : 5;
        $obj->rotation_speed = isset($row->rotation_speed) ? (int)$row->rotation_speed : 5000;
        $obj->max_width = isset($row->max_width) ? (int)$row->max_width : null;
        $obj->max_height = isset($row->max_height) ? (int)$row->max_height : null;

        return $obj;
    }
}
