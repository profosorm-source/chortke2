<?php

namespace App\Services;

use Core\Database;

/**
 * AuditTrail - ثبت خودکار تغییرات مهم
 *
 * نحوه استفاده در هر service:
 *
 *   AuditTrail::record('user.kyc_approved', $userId, [
 *       'kyc_id'     => $kycId,
 *       'admin_id'   => $adminId,
 *       'old_status' => 'pending',
 *       'new_status' => 'verified',
 *   ]);
 *
 * یا با before/after:
 *
 *   AuditTrail::diff('user.profile_updated', $userId, $oldData, $newData);
 */
class AuditTrail
{
    // ─────────────────────────────────────────────────────────
    //  رویدادهای از پیش تعریف‌شده
    // ─────────────────────────────────────────────────────────

    // Auth
    public const AUTH_LOGIN           = 'auth.login';
    public const AUTH_LOGOUT          = 'auth.logout';
    public const AUTH_FAILED          = 'auth.failed';
    public const AUTH_PASSWORD_RESET  = 'auth.password_reset';
    public const AUTH_EMAIL_VERIFIED  = 'auth.email_verified';

    // User
    public const USER_CREATED         = 'user.created';
    public const USER_UPDATED         = 'user.updated';
    public const USER_BANNED          = 'user.banned';
    public const USER_UNBANNED        = 'user.unbanned';
    public const USER_KYC_SUBMITTED   = 'user.kyc_submitted';
    public const USER_KYC_APPROVED    = 'user.kyc_approved';
    public const USER_KYC_REJECTED    = 'user.kyc_rejected';
    public const USER_LEVEL_CHANGED   = 'user.level_changed';
    public const USER_ROLE_CHANGED    = 'user.role_changed';

    // مالی
    public const WALLET_CREDITED      = 'wallet.credited';
    public const WALLET_DEBITED       = 'wallet.debited';
    public const DEPOSIT_APPROVED     = 'deposit.approved';
    public const DEPOSIT_REJECTED     = 'deposit.rejected';
    public const WITHDRAWAL_REQUESTED = 'withdrawal.requested';
    public const WITHDRAWAL_APPROVED  = 'withdrawal.approved';
    public const WITHDRAWAL_REJECTED  = 'withdrawal.rejected';

    // تسک
    public const TASK_CREATED         = 'task.created';
    public const TASK_APPROVED        = 'task.approved';
    public const TASK_REJECTED        = 'task.rejected';
    public const TASK_EXECUTION_APPROVED = 'task_exec.approved';
    public const TASK_EXECUTION_REJECTED = 'task_exec.rejected';

    // سرمایه‌گذاری
    public const INVESTMENT_CREATED   = 'investment.created';
    public const INVESTMENT_PROFIT    = 'investment.profit_applied';
    public const INVESTMENT_CLOSED    = 'investment.closed';

    // ادمین
    public const ADMIN_SETTINGS_CHANGED = 'admin.settings_changed';
    public const ADMIN_ROLE_CREATED     = 'admin.role_created';
    public const ADMIN_ROLE_UPDATED     = 'admin.role_updated';
    public const ADMIN_IMPERSONATE      = 'admin.impersonate';

    // ─────────────────────────────────────────────────────────
    //  ثبت رویداد
    // ─────────────────────────────────────────────────────────

    /**
     * ثبت یک رویداد با metadata دلخواه
     *
     * @param string   $event    نام رویداد (مثلاً 'user.kyc_approved')
     * @param int|null $userId   کاربر تحت‌تأثیر
     * @param array    $context  داده‌های اضافی
     * @param int|null $actorId  کسی که این عمل را انجام داده (ادمین، سیستم)
     */
    public static function record(
        string $event,
        ?int   $userId  = null,
        array  $context = [],
        ?int   $actorId = null
    ): void {
        try {
            // actor را از session می‌گیریم اگر داده نشده
            if ($actorId === null) {
                try {
                    $actorId = (int)\Core\Session::getInstance()->get('user_id') ?: null;
                } catch (\Throwable) {
                    $actorId = null;
                }
            }

            $db = Database::getInstance();
            
            $db->query(
                "INSERT INTO audit_trail
                    (event, user_id, actor_id, context, ip_address, user_agent, created_at)
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
        } catch (\Throwable $e) {
            // Audit trail هرگز نباید flow اصلی را خراب کند
            error_log("AuditTrail::record failed: " . $e->getMessage());
        }
    }

    /**
     * ثبت تغییرات before/after (diff خودکار)
     *
     * @param string $event
     * @param int    $userId
     * @param array  $before  داده‌های قبل از تغییر
     * @param array  $after   داده‌های بعد از تغییر
     * @param array  $ignore  فیلدهایی که نباید مقایسه شوند (مثل updated_at)
     */
    public static function diff(
        string $event,
        ?int   $userId,
        array  $before,
        array  $after,
        array  $ignore = ['updated_at', 'created_at', 'password', 'remember_token']
    ): void {
        $changes = [];

        foreach ($after as $key => $newVal) {
            if (in_array($key, $ignore, true)) continue;
            $oldVal = $before[$key] ?? null;
            if ($oldVal !== $newVal) {
                $changes[$key] = [
                    'from' => $oldVal,
                    'to'   => $newVal,
                ];
            }
        }

        if (empty($changes)) return;

        static::record($event, $userId, ['changes' => $changes]);
    }

    /**
     * دریافت تاریخچه یک کاربر
     */
    public static function getForUser(int $userId, int $limit = 50): array
    {
        try {
            $db = Database::getInstance();
            return $db->fetchAll(
                "SELECT * FROM audit_trail
                 WHERE user_id = ?
                 ORDER BY created_at DESC
                 LIMIT ?",
                [$userId, $limit]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * دریافت همه رویدادها با فیلتر (admin)
     */
    public static function getAll(
        int     $page    = 1,
        int     $perPage = 50,
        ?string $event   = null,
        ?int    $userId  = null,
        ?string $search  = null
    ): array {
        try {
            $db = Database::getInstance();
            $where  = 'WHERE 1=1';
            $params = [];

            if ($event) {
                $where    .= ' AND at.event = ?';
                $params[]  = $event;
            }
            if ($userId) {
                $where    .= ' AND (at.user_id = ? OR at.actor_id = ?)';
                $params[]  = $userId;
                $params[]  = $userId;
            }
            if ($search) {
                $where    .= ' AND (at.event LIKE ? OR at.context LIKE ? OR u.email LIKE ?)';
                $like      = "%{$search}%";
                $params[]  = $like;
                $params[]  = $like;
                $params[]  = $like;
            }

            $offset = ($page - 1) * $perPage;
            $total  = (int)$db->fetchColumn(
                "SELECT COUNT(*) FROM audit_trail at LEFT JOIN users u ON u.id = at.user_id $where",
                $params
            );

            $rows = $db->fetchAll(
                "SELECT at.*,
                        u.full_name  AS user_name,  u.email  AS user_email,
                        a.full_name  AS actor_name, a.email  AS actor_email
                 FROM audit_trail at
                 LEFT JOIN users u ON u.id = at.user_id
                 LEFT JOIN users a ON a.id = at.actor_id
                 $where
                 ORDER BY at.created_at DESC
                 LIMIT ? OFFSET ?",
                [...$params, $perPage, $offset]
            );

            return [
                'rows'       => $rows,
                'total'      => $total,
                'page'       => $page,
                'totalPages' => (int)ceil($total / $perPage),
            ];
        } catch (\Throwable $e) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'totalPages' => 1];
        }
    }

    /**
     * لیست رویدادهای موجود برای فیلتر
     */
    public static function getEventTypes(): array
    {
        try {
            $db = Database::getInstance();
            $rows = $db->fetchAll(
                "SELECT DISTINCT event, COUNT(*) as cnt
                 FROM audit_trail
                 GROUP BY event
                 ORDER BY cnt DESC"
            );
            return array_map(fn($r) => ['event' => $r->event, 'count' => $r->cnt], $rows);
        } catch (\Throwable) {
            return [];
        }
    }
}
