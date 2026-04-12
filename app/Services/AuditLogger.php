<?php

namespace App\Services;

use Core\Database;

/**
 * AuditLogger Service - ثبت تغییرات مهم و حیاتی
 * 
 * جایگزین کامل AuditTrail
 */
class AuditLogger
{
    private Database $db;

    // Event Constants
    public const AUTH_LOGIN = 'auth.login';
    public const AUTH_LOGOUT = 'auth.logout';
    public const AUTH_FAILED = 'auth.failed';
    public const AUTH_PASSWORD_RESET = 'auth.password_reset';
    public const AUTH_EMAIL_VERIFIED = 'auth.email_verified';

    public const USER_CREATED = 'user.created';
    public const USER_UPDATED = 'user.updated';
    public const USER_DELETED = 'user.deleted';
    public const USER_BANNED = 'user.banned';
    public const USER_UNBANNED = 'user.unbanned';
    public const USER_KYC_SUBMITTED = 'user.kyc_submitted';
    public const USER_KYC_APPROVED = 'user.kyc_approved';
    public const USER_KYC_REJECTED = 'user.kyc_rejected';
    public const USER_LEVEL_CHANGED = 'user.level_changed';
    public const USER_ROLE_CHANGED = 'user.role_changed';

    public const WALLET_CREDITED = 'wallet.credited';
    public const WALLET_DEBITED = 'wallet.debited';
    public const DEPOSIT_CREATED = 'deposit.created';
    public const DEPOSIT_APPROVED = 'deposit.approved';
    public const DEPOSIT_REJECTED = 'deposit.rejected';
    public const WITHDRAWAL_REQUESTED = 'withdrawal.requested';
    public const WITHDRAWAL_APPROVED = 'withdrawal.approved';
    public const WITHDRAWAL_REJECTED = 'withdrawal.rejected';

    public const TASK_CREATED = 'task.created';
    public const TASK_APPROVED = 'task.approved';
    public const TASK_REJECTED = 'task.rejected';
    public const TASK_EXECUTION_SUBMITTED = 'task_exec.submitted';
    public const TASK_EXECUTION_APPROVED = 'task_exec.approved';
    public const TASK_EXECUTION_REJECTED = 'task_exec.rejected';

    public const INVESTMENT_CREATED = 'investment.created';
    public const INVESTMENT_PROFIT = 'investment.profit_applied';
    public const INVESTMENT_CLOSED = 'investment.closed';

    public const ADMIN_SETTINGS_CHANGED = 'admin.settings_changed';
    public const ADMIN_ROLE_CREATED = 'admin.role_created';
    public const ADMIN_ROLE_UPDATED = 'admin.role_updated';
    public const ADMIN_ROLE_DELETED = 'admin.role_deleted';
    public const ADMIN_IMPERSONATE = 'admin.impersonate';
    public const ADMIN_EXPORT = 'admin.export';

    public const SECURITY_SUSPICIOUS_LOGIN = 'security.suspicious_login';
    public const SECURITY_MULTIPLE_FAILED_LOGINS = 'security.multiple_failed_logins';
    public const SECURITY_IP_BLOCKED = 'security.ip_blocked';

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * ثبت رویداد
     */
    public function record(
        string $event,
        ?int $userId = null,
        array $context = [],
        ?int $actorId = null
    ): bool {
        try {
            if ($actorId === null) {
                $actorId = $this->getActorId();
            }

            $stmt = $this->db->query(
                "INSERT INTO audit_trail (event, user_id, actor_id, context, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [
                    $event,
                    $userId,
                    $actorId,
                    json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300),
                ]
            );

            return (bool)$stmt;
        } catch (\Throwable $e) {
            error_log("AuditLogger::record failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ثبت تغییرات (diff)
     */
    public function diff(
        string $event,
        ?int $userId,
        array $before,
        array $after,
        array $ignore = ['updated_at', 'created_at', 'password', 'remember_token']
    ): bool {
        $changes = [];

        foreach ($after as $key => $newVal) {
            if (in_array($key, $ignore, true)) {
                continue;
            }
            
            $oldVal = $before[$key] ?? null;
            if ($oldVal !== $newVal) {
                $changes[$key] = [
                    'from' => $oldVal,
                    'to' => $newVal,
                ];
            }
        }

        if (empty($changes)) {
            return false;
        }

        return $this->record($event, $userId, ['changes' => $changes]);
    }

    /**
     * تاریخچه کاربر
     */
    public function getForUser(int $userId, int $limit = 50): array
    {
        try {
            $stmt = $this->db->query(
                "SELECT at.*, u.full_name, u.email,
                        a.full_name as actor_name, a.email as actor_email
                 FROM audit_trail at
                 LEFT JOIN users u ON at.user_id = u.id
                 LEFT JOIN users a ON at.actor_id = a.id
                 WHERE at.user_id = ?
                 ORDER BY at.created_at DESC
                 LIMIT ?",
                [$userId, $limit]
            );

            if (!$stmt instanceof \PDOStatement) {
                return [];
            }

            return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * همه رویدادها با فیلتر
     */
    public function getAll(
        int $page = 1,
        int $perPage = 50,
        ?string $event = null,
        ?int $userId = null,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): array {
        try {
            $where = ['1=1'];
            $params = [];

            if ($event) {
                $where[] = 'at.event = ?';
                $params[] = $event;
            }
            if ($userId) {
                $where[] = '(at.user_id = ? OR at.actor_id = ?)';
                $params[] = $userId;
                $params[] = $userId;
            }
            if ($search) {
                $where[] = '(at.event LIKE ? OR at.context LIKE ? OR u.email LIKE ?)';
                $like = "%{$search}%";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
            if ($dateFrom) {
                $where[] = 'at.created_at >= ?';
                $params[] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo) {
                $where[] = 'at.created_at <= ?';
                $params[] = $dateTo . ' 23:59:59';
            }

            $whereClause = implode(' AND ', $where);
            $offset = ($page - 1) * $perPage;

            // Count
            $countStmt = $this->db->query(
                "SELECT COUNT(*) FROM audit_trail at 
                 LEFT JOIN users u ON u.id = at.user_id 
                 WHERE {$whereClause}",
                $params
            );
            $total = $countStmt instanceof \PDOStatement ? (int)$countStmt->fetchColumn() : 0;

            // Data
            $dataStmt = $this->db->query(
                "SELECT at.*, u.full_name AS user_name, u.email AS user_email,
                        a.full_name AS actor_name, a.email AS actor_email
                 FROM audit_trail at
                 LEFT JOIN users u ON u.id = at.user_id
                 LEFT JOIN users a ON a.id = at.actor_id
                 WHERE {$whereClause}
                 ORDER BY at.created_at DESC
                 LIMIT ? OFFSET ?",
                [...$params, $perPage, $offset]
            );

            $rows = $dataStmt instanceof \PDOStatement ? $dataStmt->fetchAll(\PDO::FETCH_OBJ) : [];

            return [
                'rows' => $rows,
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => (int)ceil($total / $perPage),
            ];
        } catch (\Throwable $e) {
            error_log("AuditLogger::getAll failed: " . $e->getMessage());
            return ['rows' => [], 'total' => 0, 'page' => 1, 'perPage' => $perPage, 'totalPages' => 1];
        }
    }

    /**
     * لیست نوع رویدادها
     */
    public function getEventTypes(): array
    {
        try {
            $stmt = $this->db->query(
                "SELECT DISTINCT event, COUNT(*) as count
                 FROM audit_trail
                 GROUP BY event
                 ORDER BY count DESC"
            );

            if (!$stmt instanceof \PDOStatement) {
                return [];
            }

            return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * آمار
     */
    public function getStats(?string $dateFrom = null, ?string $dateTo = null): array
    {
        try {
            $where = [];
            $params = [];

            if ($dateFrom) {
                $where[] = 'created_at >= ?';
                $params[] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo) {
                $where[] = 'created_at <= ?';
                $params[] = $dateTo . ' 23:59:59';
            }

            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

            $stmt = $this->db->query(
                "SELECT 
                    COUNT(*) as total,
                    COUNT(DISTINCT user_id) as unique_users,
                    COUNT(DISTINCT event) as unique_events,
                    DATE(created_at) as date
                 FROM audit_trail
                 {$whereClause}
                 GROUP BY DATE(created_at)
                 ORDER BY date DESC",
                $params
            );

            if (!$stmt instanceof \PDOStatement) {
                return [];
            }

            return $stmt->fetchAll(\PDO::FETCH_OBJ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * دریافت Actor ID
     */
    private function getActorId(): ?int
    {
        try {
            if (function_exists('user_id')) {
                return user_id();
            }
            $session = \Core\Session::getInstance();
            return $session->get('user_id') ? (int)$session->get('user_id') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
