<?php

namespace App\Models;
use Core\Model;

use Core\Database;

class InfluencerProfile extends Model {
public function find(int $id): ?object
    {
        $stmt = $this->db->prepare("
            SELECT ip.*, u.full_name, u.email
            FROM influencer_profiles ip
            LEFT JOIN users u ON u.id = ip.user_id
            WHERE ip.id = ? AND ip.deleted_at IS NULL
        ");
        $stmt->execute([$id]);
        $r = $stmt->fetch(\PDO::FETCH_OBJ);
        return $r ?: null;
    }

    public function findByUserId(int $userId): ?object
    {
        $stmt = $this->db->prepare("
            SELECT ip.*, u.full_name, u.email
            FROM influencer_profiles ip
            LEFT JOIN users u ON u.id = ip.user_id
            WHERE ip.user_id = ? AND ip.deleted_at IS NULL
        ");
        $stmt->execute([$userId]);
        $r = $stmt->fetch(\PDO::FETCH_OBJ);
        return $r ?: null;
    }

    public function create(array $d): ?object
    {
        $stmt = $this->db->prepare("
            INSERT INTO influencer_profiles
            (user_id, platform, username, page_url, profile_image, follower_count,
             engagement_rate, category, bio, story_price_24h, post_price_24h,
             post_price_48h, post_price_72h, currency, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $result = $stmt->execute([
            $d['user_id'], $d['platform'] ?? 'instagram', $d['username'],
            $d['page_url'], $d['profile_image'] ?? null, $d['follower_count'] ?? 0,
            $d['engagement_rate'] ?? 0, $d['category'] ?? null, $d['bio'] ?? null,
            $d['story_price_24h'] ?? 0, $d['post_price_24h'] ?? 0,
            $d['post_price_48h'] ?? 0, $d['post_price_72h'] ?? 0,
            $d['currency'] ?? 'irt', $d['status'] ?? 'pending',
        ]);
        if (!$result) return null;
        return $this->find((int) $this->db->lastInsertId());
    }

    public function update(int $id, array $data): bool
    {
        $fields = []; $values = [];
        $allowed = [
            'username','page_url','profile_image','follower_count','engagement_rate',
            'category','bio','story_price_24h','post_price_24h','post_price_48h',
            'post_price_72h','currency','total_orders','completed_orders','average_rating',
            'status','rejection_reason','verified_by','verified_at','is_active','priority',
        ];
        foreach ($allowed as $f) {
            if (\array_key_exists($f, $data)) {
                $fields[] = "{$f} = ?"; $values[] = $data[$f];
            }
        }
        if (empty($fields)) return false;
        $values[] = $id;
        $stmt = $this->db->prepare("UPDATE influencer_profiles SET " . \implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($values);
    }

    /**
     * لیست اینفلوئنسرهای تأیید‌شده (برای کاربر تبلیغ‌دهنده)
     */
    public function getVerified(array $filters = [], string $sort = 'priority', int $limit = 20, int $offset = 0): array
    {
        $where = ["ip.status = 'verified'", "ip.is_active = 1", "ip.deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['category'])) {
            $where[] = "ip.category = ?"; $params[] = $filters['category'];
        }
        if (!empty($filters['min_followers'])) {
            $where[] = "ip.follower_count >= ?"; $params[] = (int) $filters['min_followers'];
        }
        if (!empty($filters['max_price'])) {
            $where[] = "ip.story_price_24h <= ?"; $params[] = (float) $filters['max_price'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(ip.username LIKE ? OR ip.bio LIKE ? OR u.full_name LIKE ?)";
            $s = '%' . $filters['search'] . '%'; $params[] = $s; $params[] = $s; $params[] = $s;
        }

        $whereStr = \implode(' AND ', $where);

        $orderBy = match ($sort) {
            'followers' => 'ip.follower_count DESC',
            'price_low' => 'ip.story_price_24h ASC',
            'price_high' => 'ip.story_price_24h DESC',
            'rating' => 'ip.average_rating DESC',
            'orders' => 'ip.completed_orders DESC',
            default => 'ip.priority DESC, ip.completed_orders DESC',
        };

        $stmt = $this->db->prepare("
            SELECT ip.*, u.full_name, u.email
            FROM influencer_profiles ip
            LEFT JOIN users u ON u.id = ip.user_id
            WHERE {$whereStr}
            ORDER BY {$orderBy}
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit; $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function countVerified(array $filters = []): int
    {
        $where = ["ip.status = 'verified'", "ip.is_active = 1", "ip.deleted_at IS NULL"];
        $params = [];
        if (!empty($filters['category'])) { $where[] = "ip.category = ?"; $params[] = $filters['category']; }
        if (!empty($filters['min_followers'])) { $where[] = "ip.follower_count >= ?"; $params[] = (int) $filters['min_followers']; }
        if (!empty($filters['max_price'])) { $where[] = "ip.story_price_24h <= ?"; $params[] = (float) $filters['max_price']; }
        if (!empty($filters['search'])) {
            $where[] = "(ip.username LIKE ? OR ip.bio LIKE ? OR u.full_name LIKE ?)";
            $s = '%' . $filters['search'] . '%'; $params[] = $s; $params[] = $s; $params[] = $s;
        }
        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM influencer_profiles ip LEFT JOIN users u ON u.id = ip.user_id WHERE {$whereStr}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * لیست ادمین
     */
    public function adminList(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        $where = ["ip.deleted_at IS NULL"]; $params = [];
        if (!empty($filters['status'])) { $where[] = "ip.status = ?"; $params[] = $filters['status']; }
        if (!empty($filters['search'])) {
            $where[] = "(ip.username LIKE ? OR u.full_name LIKE ?)";
            $s = '%' . $filters['search'] . '%'; $params[] = $s; $params[] = $s;
        }
        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("
            SELECT ip.*, u.full_name, u.email
            FROM influencer_profiles ip LEFT JOIN users u ON u.id = ip.user_id
            WHERE {$whereStr} ORDER BY ip.created_at DESC LIMIT ? OFFSET ?
        ");
        $params[] = $limit; $params[] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_OBJ);
    }

    public function adminCount(array $filters = []): int
    {
        $where = ["ip.deleted_at IS NULL"]; $params = [];
        if (!empty($filters['status'])) { $where[] = "ip.status = ?"; $params[] = $filters['status']; }
        if (!empty($filters['search'])) {
            $where[] = "(ip.username LIKE ? OR u.full_name LIKE ?)";
            $s = '%' . $filters['search'] . '%'; $params[] = $s; $params[] = $s;
        }
        $whereStr = \implode(' AND ', $where);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM influencer_profiles ip LEFT JOIN users u ON u.id = ip.user_id WHERE {$whereStr}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function categories(): array
    {
        $cats = setting('story_categories', '');
        return $cats ? \explode(',', $cats) : [];
    }
}