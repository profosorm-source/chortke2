<?php

namespace App\Services;
use Core\Database;

class ExportService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }
    /**
     * خروجی CSV
     */
    public function exportCsv(array $headers, array $rows, string $filename): void
    {
        $filename = \preg_replace('/[^a-zA-Z0-9_\-]/', '', $filename) . '_' . \date('Y-m-d_His') . '.csv';

        \header('Content-Type: text/csv; charset=UTF-8');
        \header('Content-Disposition: attachment; filename="' . $filename . '"');
        \header('Cache-Control: no-cache, no-store, must-revalidate');
        \header('Pragma: no-cache');
        \header('Expires: 0');

        // BOM for UTF-8 Excel compatibility
        echo "\xEF\xBB\xBF";

        $output = \fopen('php://output', 'w');

        // Header
        \fputcsv($output, $headers);

        // Rows
        foreach ($rows as $row) {
            if (\is_object($row)) {
                $row = (array)$row;
            }
            \fputcsv($output, \array_values($row));
        }

        \fclose($output);
        exit;
    }

    /**
     * خروجی JSON
     */
    public function exportJson(array $data, string $filename): void
    {
        $filename = \preg_replace('/[^a-zA-Z0-9_\-]/', '', $filename) . '_' . \date('Y-m-d_His') . '.json';

        \header('Content-Type: application/json; charset=UTF-8');
        \header('Content-Disposition: attachment; filename="' . $filename . '"');
        \header('Cache-Control: no-cache');

        echo \json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * آماده‌سازی داده‌ها برای خروجی کاربران
     */
    public function prepareUsersExport(array $filters = []): array
    {
        $where = ["u.deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = "u.created_at >= :df";
            $params['df'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = "u.created_at <= :dt";
            $params['dt'] = $filters['date_to'] . ' 23:59:59';
        }

        $whereStr = \implode(' AND ', $where);

        $rows = $db->fetchAll(
            "SELECT u.id, u.full_name, u.email, u.mobile, u.tier_level, u.status, 
                    u.created_at, u.last_login,
                    COALESCE(w.balance_irt, 0) as balance_irt,
                    COALESCE(w.balance_usdt, 0) as balance_usdt
             FROM users u
             LEFT JOIN wallets w ON w.user_id = u.id
             WHERE {$whereStr}
             ORDER BY u.id DESC",
            $params
        );

        $headers = ['شناسه', 'نام', 'ایمیل', 'موبایل', 'سطح', 'وضعیت', 'تاریخ ثبت‌نام', 'آخرین ورود', 'موجودی تومان', 'موجودی تتر'];

        $statusMap = [0 => 'غیرفعال', 1 => 'فعال', 2 => 'تعلیق', 3 => 'مسدود'];

        $formatted = [];
        foreach ($rows as $row) {
            $r = \is_array($row) ? (object)$row : $row;
            $formatted[] = [
                $r->id,
                $r->full_name,
                $r->email,
                $r->mobile ?? '',
                $r->tier_level ?? 'silver',
                $statusMap[(int)($r->status ?? 0)] ?? 'نامشخص',
                $r->created_at,
                $r->last_login ?? '',
                $r->balance_irt,
                $r->balance_usdt,
            ];
        }

        return ['headers' => $headers, 'rows' => $formatted];
    }

    /**
     * آماده‌سازی داده‌ها برای خروجی تراکنش‌ها
     */
    public function prepareTransactionsExport(array $filters = []): array
    {
        $where = ["t.deleted_at IS NULL"];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = "t.created_at >= :df";
            $params['df'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = "t.created_at <= :dt";
            $params['dt'] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['type'])) {
            $where[] = "t.type = :type";
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['status'])) {
            $where[] = "t.status = :status";
            $params['status'] = $filters['status'];
        }

        $whereStr = \implode(' AND ', $where);

        $rows = $db->fetchAll(
            "SELECT t.id, t.transaction_id, u.full_name, t.type, t.currency, 
                    t.amount, t.balance_before, t.balance_after, t.status, t.created_at
             FROM transactions t
             LEFT JOIN users u ON t.user_id = u.id
             WHERE {$whereStr}
             ORDER BY t.id DESC
             LIMIT 10000",
            $params
        );

        $headers = ['شناسه', 'شماره تراکنش', 'کاربر', 'نوع', 'ارز', 'مبلغ', 'قبل', 'بعد', 'وضعیت', 'تاریخ'];

        $formatted = [];
        foreach ($rows as $row) {
            $r = \is_array($row) ? (object)$row : $row;
            $formatted[] = [
                $r->id,
                $r->transaction_id,
                $r->full_name ?? '',
                $r->type,
                $r->currency,
                $r->amount,
                $r->balance_before,
                $r->balance_after,
                $r->status,
                $r->created_at,
            ];
        }

        return ['headers' => $headers, 'rows' => $formatted];
    }

    /**
     * Stream کردن query بزرگ به صورت CSV بدون OOM
     */
    public function streamQuery(string $sql, array $params, array $columns, string $filename): void
    {
        $date = date('Y-m-d');
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$filename}_{$date}.csv\"");
        header('Cache-Control: no-cache, no-store');

        echo "\xEF\xBB\xBF"; // BOM for Excel
        $out = fopen('php://output', 'w');
        fputcsv($out, array_values($columns));

        $pdo  = $db->getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $csvRow = [];
            foreach (array_keys($columns) as $col) {
                $val = $row[$col] ?? '';
                if (is_bool($val)) $val = $val ? 'بله' : 'خیر';
                // جلوگیری از formula injection
                if (is_string($val) && strlen($val) > 0 && in_array($val[0], ['=','+','-','@'])) {
                    $val = "'" . $val;
                }
                $csvRow[] = $val;
            }
            fputcsv($out, $csvRow);
        }
        fclose($out);
        exit();
    }

    /**
     * خروجی کاربران
     */
    public function exportUsers(array $filters = []): void
    {
        $where = 'WHERE deleted_at IS NULL'; $params = [];
        if (!empty($filters['from']))       { $where .= ' AND DATE(created_at) >= ?'; $params[] = $filters['from']; }
        if (!empty($filters['to']))         { $where .= ' AND DATE(created_at) <= ?'; $params[] = $filters['to']; }
        if (!empty($filters['kyc_status'])) { $where .= ' AND kyc_status = ?';        $params[] = $filters['kyc_status']; }
        if (!empty($filters['tier_level'])) { $where .= ' AND tier_level = ?';        $params[] = $filters['tier_level']; }

        $this->streamQuery(
            "SELECT id, full_name, email, mobile, kyc_status, tier_level, referral_code, is_banned, created_at FROM users $where ORDER BY id",
            $params,
            ['id'=>'#','full_name'=>'نام','email'=>'ایمیل','mobile'=>'موبایل','kyc_status'=>'KYC','tier_level'=>'سطح','referral_code'=>'کد معرف','is_banned'=>'مسدود','created_at'=>'تاریخ'],
            'users_export'
        );
    }

    /**
     * خروجی تراکنش‌ها (streaming)
     */
    public function exportTransactionsStream(array $filters = []): void
    {
        $where = 'WHERE 1=1'; $params = [];
        if (!empty($filters['from']))     { $where .= ' AND DATE(t.created_at) >= ?'; $params[] = $filters['from']; }
        if (!empty($filters['to']))       { $where .= ' AND DATE(t.created_at) <= ?'; $params[] = $filters['to']; }
        if (!empty($filters['type']))     { $where .= ' AND t.type = ?';              $params[] = $filters['type']; }
        if (!empty($filters['currency'])){ $where .= ' AND t.currency = ?';          $params[] = $filters['currency']; }
        if (!empty($filters['status']))   { $where .= ' AND t.status = ?';            $params[] = $filters['status']; }

        $this->streamQuery(
            "SELECT t.id, u.full_name AS user_name, u.email AS user_email, t.type, t.amount, t.currency, t.status, t.description, t.reference_id, t.created_at FROM transactions t LEFT JOIN users u ON u.id = t.user_id $where ORDER BY t.id DESC",
            $params,
            ['id'=>'#','user_name'=>'نام','user_email'=>'ایمیل','type'=>'نوع','amount'=>'مبلغ','currency'=>'ارز','status'=>'وضعیت','description'=>'توضیح','reference_id'=>'مرجع','created_at'=>'تاریخ'],
            'transactions_export'
        );
    }

    /**
     * خروجی برداشت‌ها (streaming)
     */
    public function exportWithdrawalsStream(array $filters = []): void
    {
        $where = 'WHERE 1=1'; $params = [];
        if (!empty($filters['status']))   { $where .= ' AND w.status = ?';           $params[] = $filters['status']; }
        if (!empty($filters['from']))     { $where .= ' AND DATE(w.created_at) >= ?'; $params[] = $filters['from']; }
        if (!empty($filters['to']))       { $where .= ' AND DATE(w.created_at) <= ?'; $params[] = $filters['to']; }
        if (!empty($filters['currency'])){ $where .= ' AND w.currency = ?';          $params[] = $filters['currency']; }

        $this->streamQuery(
            "SELECT w.id, w.tracking_code, u.full_name AS user_name, u.email AS user_email, w.amount, w.fee, w.final_amount, w.currency, w.status, COALESCE(w.crypto_network, 'بانکی') AS method, w.created_at FROM withdrawals w LEFT JOIN users u ON u.id = w.user_id $where ORDER BY w.id DESC",
            $params,
            ['id'=>'#','tracking_code'=>'کد پیگیری','user_name'=>'نام','user_email'=>'ایمیل','amount'=>'مبلغ','fee'=>'کارمزد','final_amount'=>'مبلغ نهایی','currency'=>'ارز','status'=>'وضعیت','method'=>'روش','created_at'=>'تاریخ'],
            'withdrawals_export'
        );
    }

    /**
     * خروجی AuditTrail
     */
    public function exportAuditTrail(array $filters = []): void
    {
        $where = 'WHERE 1=1'; $params = [];
        if (!empty($filters['event']))    { $where .= ' AND at.event = ?';             $params[] = $filters['event']; }
        if (!empty($filters['from']))     { $where .= ' AND DATE(at.created_at) >= ?'; $params[] = $filters['from']; }
        if (!empty($filters['to']))       { $where .= ' AND DATE(at.created_at) <= ?'; $params[] = $filters['to']; }
        if (!empty($filters['user_id'])){ $where .= ' AND at.user_id = ?';           $params[] = (int)$filters['user_id']; }

        $this->streamQuery(
            "SELECT at.id, at.event, u.email AS user_email, a.email AS actor_email, at.context, at.ip_address, at.created_at FROM audit_trail at LEFT JOIN users u ON u.id = at.user_id LEFT JOIN users a ON a.id = at.actor_id $where ORDER BY at.id DESC",
            $params,
            ['id'=>'#','event'=>'رویداد','user_email'=>'کاربر','actor_email'=>'انجام‌دهنده','context'=>'جزئیات','ip_address'=>'IP','created_at'=>'زمان'],
            'audit_trail_export'
        );
    }

}// NOTE: Methods below added in Phase 2 upgrade

