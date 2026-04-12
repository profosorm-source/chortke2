<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class Role extends Model {
/**
     * یافتن نقش با ID
     */
    public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM roles WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);

        return $result ?: null;
    }

    /**
     * یافتن نقش با slug
     */
    public function findBySlug(string $slug): ?object
    {
        $stmt = $this->db->prepare("SELECT * FROM roles WHERE slug = ? AND deleted_at IS NULL");
        $stmt->execute([$slug]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);

        return $result ?: null;
    }

    /**
     * دریافت تمام نقش‌ها (سازگار با Core\Model)
     */
    public function all(int $limit = 100, int $offset = 0): array
    {
        return $this->allRoles(true);
    }

    /**
     * دریافت تمام نقش‌ها با فیلتر فعال/غیرفعال
     */
    public function allRoles(bool $onlyActive = true): array
    {
        $sql = "SELECT * FROM roles WHERE deleted_at IS NULL";
        if ($onlyActive) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * ایجاد نقش جدید
     */
    public function create(array $data): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO roles (name, slug, description, is_system, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");

        $ok = $stmt->execute([
            (string)($data['name'] ?? ''),
            (string)($data['slug'] ?? ''),
            $data['description'] ?? null,
            (int)($data['is_system'] ?? 0),
            (int)($data['is_active'] ?? 1),
        ]);

        if (!$ok) {
            return null;
        }

        // نیازمند Database::lastInsertId()
        $newId = (int)$this->db->lastInsertId();
        return $newId > 0 ? $this->find($newId) : null;
    }

    /**
     * بروزرسانی نقش
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $values = [];

        $allowed = ['name', 'description', 'is_active'];

        foreach ($allowed as $field) {
            if (\array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $values[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        // updated_at هم آپدیت شود
        $fields[] = "updated_at = NOW()";

        $values[] = $id;

        $stmt = $this->db->prepare("
            UPDATE roles SET " . \implode(', ', $fields) . "
            WHERE id = ? AND deleted_at IS NULL
        ");

        return $stmt->execute($values);
    }

    /**
     * حذف نرم نقش (فقط غیر سیستمی)
     */
    public function delete(int $id): bool
    {
        $role = $this->find($id);
        if (!$role || (int)$role->is_system === 1) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE roles SET deleted_at = NOW(), updated_at = NOW()
            WHERE id = ? AND is_system = 0
        ");

        return $stmt->execute([$id]);
    }

    /**
     * دریافت دسترسی‌های یک نقش
     */
    public function getPermissions(int $roleId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.* FROM permissions p
            INNER JOIN role_permissions rp ON rp.permission_id = p.id
            WHERE rp.role_id = ?
            ORDER BY p.group_name ASC, p.name ASC
        ");
        $stmt->execute([$roleId]);

        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    /**
     * دریافت slug های دسترسی‌های یک نقش
     */
    public function getPermissionSlugs(int $roleId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.slug FROM permissions p
            INNER JOIN role_permissions rp ON rp.permission_id = p.id
            WHERE rp.role_id = ?
        ");
        $stmt->execute([$roleId]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * همگام‌سازی دسترسی‌های نقش
     * (حذف/درج روی pivot طبیعی است؛ چون role_permissions ستون deleted_at ندارد)
     */
    public function syncPermissions(int $roleId, array $permissionIds): bool
    {
        try {
            $this->db->beginTransaction();

            // حذف قبلی‌ها
            $stmt = $this->db->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $stmt->execute([$roleId]);

            // درج جدیدها
            $permissionIds = \array_values(\array_unique(\array_map('intval', $permissionIds)));

            if (!empty($permissionIds)) {
                $placeholders = \rtrim(\str_repeat('(?, ?),', \count($permissionIds)), ',');
                $values = [];

                foreach ($permissionIds as $permId) {
                    $values[] = $roleId;
                    $values[] = $permId;
                }

                $stmt = $this->db->prepare("
                    INSERT INTO role_permissions (role_id, permission_id) VALUES {$placeholders}
                ");
                $stmt->execute($values);
            }

            $this->db->commit();
            return true;

        } catch (\Throwable $e) {
            $this->db->rollBack();

            if (\function_exists('logger')) {
                logger('Role syncPermissions failed: ' . \json_encode([
                    'role_id' => $roleId,
                    'error' => $e->getMessage(),
                ], JSON_UNESCAPED_UNICODE));
            }

            return false;
        }
    }

    /**
     * تعداد کاربران هر نقش
     */
    public function getUserCount(int $roleId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM users WHERE role_id = ? AND deleted_at IS NULL");
        $stmt->execute([$roleId]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * بررسی وجود slug
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM roles WHERE slug = ? AND deleted_at IS NULL";
        $params = [$slug];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn() > 0;
    }
}