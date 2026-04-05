<?php

namespace App\Models;

use Core\Model;
use Core\Database;

class ContentSubmission extends Model {
    // وضعیت‌ها
    public const STATUS_PENDING      = 'pending';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED     = 'approved';
    public const STATUS_REJECTED     = 'rejected';
    public const STATUS_PUBLISHED    = 'published';
    public const STATUS_SUSPENDED    = 'suspended';

    // پلتفرم‌ها
    public const PLATFORM_APARAT  = 'aparat';
    public const PLATFORM_YOUTUBE = 'youtube';

    public const ALLOWED_PLATFORMS = [
        self::PLATFORM_APARAT,
        self::PLATFORM_YOUTUBE,
    ];

    public const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_PUBLISHED,
        self::STATUS_SUSPENDED,
    ];

    // حداقل ماه‌های فعالیت برای دریافت سود
    public const MIN_MONTHS_FOR_REVENUE = 2;
/**
     * ایجاد ثبت محتوا
     * خروجی: id یا null
     */
    public function create(array $data): ?int
    {
        $now = \date('Y-m-d H:i:s');

        $fields = [
            'user_id'                => (int)$data['user_id'],
            'platform'               => (string)$data['platform'],
            'video_url'              => (string)$data['video_url'],
            'title'                  => (string)$data['title'],
            'description'            => $data['description'] ?? null,
            'category'               => $data['category'] ?? null,

            'status'                 => self::STATUS_PENDING,

            'agreement_accepted'     => (int)($data['agreement_accepted'] ?? 0),
            'agreement_accepted_at'  => $data['agreement_accepted_at'] ?? null,
            'agreement_ip'           => $data['agreement_ip'] ?? null,
            'agreement_fingerprint'  => $data['agreement_fingerprint'] ?? null,

            'is_deleted'             => 0,
            'created_at'             => $now,
            'updated_at'             => $now,
        ];

        // INSERT داینامیک
        $columns = \array_keys($fields);
        $values  = \array_values($fields);

        $placeholders = \array_fill(0, \count($columns), '?');
        $colsSql = '`' . \implode('`,`', $columns) . '`';

        $sql = "INSERT INTO `content_submissions` ({$colsSql}) VALUES (" . \implode(',', $placeholders) . ")";

        $stmt = $this->db->prepare($sql);
        $ok = $stmt->execute($values);

        if (!$ok) {
            return null;
        }

        $id = (int)$this->db->lastInsertId();
        return $id > 0 ? $id : null;
    }

    public function find(int $id): ?object
    {
        $stmt = $this->db->query(
            "SELECT * FROM content_submissions WHERE id = ? AND is_deleted = 0 LIMIT 1",
            [$id]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    public function findWithUser(int $id): ?object
    {
        $stmt = $this->db->query(
            "SELECT cs.*, u.full_name as user_name, u.email as user_email
             FROM content_submissions cs
             JOIN users u ON cs.user_id = u.id
             WHERE cs.id = ? AND cs.is_deleted = 0
             LIMIT 1",
            [$id]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : false;
        return $row ?: null;
    }

    /**
     * لیست محتواهای کاربر
     */
    public function getByUser(int $userId, ?string $status = null, int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT * FROM content_submissions WHERE user_id = ? AND is_deleted = 0";
        $params = [$userId];

        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->query($sql, $params);
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    public function countByUser(int $userId, ?string $status = null): int
    {
        $sql = "SELECT COUNT(*) as total FROM content_submissions WHERE user_id = ? AND is_deleted = 0";
        $params = [$userId];

        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }

        $stmt = $this->db->query($sql, $params);
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;

        return (int)($row->total ?? 0);
    }

    /**
     * لیست تمام محتواها (ادمین)
     */
    public function getAll(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $limit  = \max(1, (int)$limit);
        $offset = \max(0, (int)$offset);

        $sql = "SELECT cs.*, u.full_name as user_name, u.email as user_email
                FROM content_submissions cs
                JOIN users u ON cs.user_id = u.id
                WHERE cs.is_deleted = 0";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND cs.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['platform'])) {
            $sql .= " AND cs.platform = ?";
            $params[] = $filters['platform'];
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND cs.user_id = ?";
            $params[] = (int)$filters['user_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (cs.title LIKE ? OR cs.video_url LIKE ? OR u.full_name LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $sql .= " ORDER BY cs.created_at DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->db->query($sql, $params);
        return $stmt ? $stmt->fetchAll(\PDO::FETCH_OBJ) : [];
    }

    public function countAll(array $filters = []): int
    {
        $sql = "SELECT COUNT(*) as total
                FROM content_submissions cs
                JOIN users u ON cs.user_id = u.id
                WHERE cs.is_deleted = 0";

        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND cs.status = ?";
            $params[] = $filters['status'];
        }

        if (!empty($filters['platform'])) {
            $sql .= " AND cs.platform = ?";
            $params[] = $filters['platform'];
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND cs.user_id = ?";
            $params[] = (int)$filters['user_id'];
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (cs.title LIKE ? OR cs.video_url LIKE ? OR u.full_name LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $stmt = $this->db->query($sql, $params);
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;

        return (int)($row->total ?? 0);
    }

    /**
     * بروزرسانی (بدون db->update)
     */
    public function update(int $id, array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        $data['updated_at'] = \date('Y-m-d H:i:s');

        $fields = [];
        $values = [];

        foreach ($data as $k => $v) {
            $fields[] = "`{$k}` = ?";
            $values[] = $v;
        }

        $values[] = $id;

        $sql = "UPDATE content_submissions
                SET " . \implode(', ', $fields) . "
                WHERE id = ? AND is_deleted = 0";

        $stmt = $this->db->query($sql, $values);

        if ($stmt instanceof \PDOStatement) {
            return $stmt->rowCount() >= 0;
        }

        return (bool)$stmt;
    }

    public function softDelete(int $id): bool
    {
        return $this->update($id, ['is_deleted' => 1]);
    }

    public function hasPendingSubmission(int $userId): bool
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as total FROM content_submissions
             WHERE user_id = ? AND status IN (?, ?) AND is_deleted = 0",
            [$userId, self::STATUS_PENDING, self::STATUS_UNDER_REVIEW]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return (int)($row->total ?? 0) > 0;
    }

    public function isUrlExists(string $videoUrl, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) as total FROM content_submissions WHERE video_url = ? AND is_deleted = 0";
        $params = [$videoUrl];

        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = (int)$excludeId;
        }

        $stmt = $this->db->query($sql, $params);
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;

        return (int)($row->total ?? 0) > 0;
    }

    /**
     * تعداد ماه‌های فعالیت کاربر (از اولین محتوای تأیید شده)
     */
    public function getActiveMonths(int $userId): int
    {
        $stmt = $this->db->query(
            "SELECT MIN(approved_at) as first_approved
             FROM content_submissions
             WHERE user_id = ?
               AND status IN (?, ?)
               AND is_deleted = 0
               AND approved_at IS NOT NULL",
            [$userId, self::STATUS_APPROVED, self::STATUS_PUBLISHED]
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        if (!$row || empty($row->first_approved)) {
            return 0;
        }

        $firstApproved = new \DateTime((string)$row->first_approved);
        $now = new \DateTime();
        $diff = $now->diff($firstApproved);

        return ($diff->y * 12) + $diff->m;
    }

    public function getStats(): object
    {
        $stmt = $this->db->query(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as review_count,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published_count,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_count
             FROM content_submissions
             WHERE is_deleted = 0"
        );

        $row = $stmt ? $stmt->fetch(\PDO::FETCH_OBJ) : null;
        return $row ?: (object)[];
    }
}