<?php

namespace App\Controllers\Admin;

use Core\Database;

class RiskPolicyController
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function index(): void
    {
        $this->ensureAdmin();

        $stmt = $this->db->prepare("
            SELECT id, domain, key_name, value, value_type, description, updated_by, updated_at
            FROM risk_policies
            ORDER BY domain ASC, key_name ASC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $defaults = $this->defaultPolicies();

        // اگر policyای هنوز در DB نیست، برای نمایش از default استفاده شود
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['domain'] . '::' . $row['key_name']] = $row;
        }

        $display = [];
        foreach ($defaults as $item) {
            $k = $item['domain'] . '::' . $item['key_name'];
            if (isset($indexed[$k])) {
                $display[] = $indexed[$k];
            } else {
                $display[] = [
                    'id' => null,
                    'domain' => $item['domain'],
                    'key_name' => $item['key_name'],
                    'value' => (string)$item['value'],
                    'value_type' => $item['value_type'],
                    'description' => $item['description'],
                    'updated_by' => null,
                    'updated_at' => null,
                ];
            }
        }

        $this->render('admin/risk-policies/index', [
            'policies' => $display,
        ]);
    }

    public function update(): void
    {
        $this->ensureAdmin();

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/admin/risk-policies');
        }

        $domain = trim((string)($_POST['domain'] ?? ''));
        $keyName = trim((string)($_POST['key_name'] ?? ''));
        $value = $_POST['value'] ?? '';
        $valueType = strtolower(trim((string)($_POST['value_type'] ?? 'string')));
        $description = trim((string)($_POST['description'] ?? ''));

        if ($domain === '' || $keyName === '') {
            $this->flash('error', 'دامنه و کلید الزامی است.');
            $this->redirect('/admin/risk-policies');
        }

        if (!in_array($valueType, ['int', 'float', 'bool', 'string', 'json'], true)) {
            $valueType = 'string';
        }

        $storedValue = $this->normalizeValueForStore($value, $valueType);
        $adminId = $this->currentAdminId();

        $stmt = $this->db->prepare("
            INSERT INTO risk_policies (domain, key_name, value, value_type, description, updated_by, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                value = VALUES(value),
                value_type = VALUES(value_type),
                description = VALUES(description),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ");

        $ok = $stmt->execute([
            $domain,
            $keyName,
            $storedValue,
            $valueType,
            $description,
            $adminId,
        ]);

        if ($ok) {
            $this->flash('success', 'تنظیمات با موفقیت ذخیره شد.');
        } else {
            $this->flash('error', 'خطا در ذخیره تنظیمات.');
        }

        $this->redirect('/admin/risk-policies');
    }

    private function normalizeValueForStore($value, string $type): string
    {
        if ($type === 'int') {
            return (string)((int)$value);
        }
        if ($type === 'float') {
            return (string)((float)$value);
        }
        if ($type === 'bool') {
            $v = strtolower((string)$value);
            return in_array($v, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
        }
        if ($type === 'json') {
            $decoded = json_decode((string)$value, true);
            return json_last_error() === JSON_ERROR_NONE ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : '{}';
        }

        return (string)$value;
    }

    private function defaultPolicies(): array
    {
        return [
            ['domain' => 'fraud', 'key_name' => 'block_threshold', 'value' => 80, 'value_type' => 'int', 'description' => 'آستانه مسدودسازی'],
            ['domain' => 'fraud', 'key_name' => 'challenge_threshold', 'value' => 60, 'value_type' => 'int', 'description' => 'آستانه چالش امنیتی'],
            ['domain' => 'fraud', 'key_name' => 'limit_threshold', 'value' => 40, 'value_type' => 'int', 'description' => 'آستانه محدودسازی'],
            ['domain' => 'task', 'key_name' => 'risk_to_fraud_bridge_enabled', 'value' => 1, 'value_type' => 'bool', 'description' => 'اتصال ریسک تسک به fraud'],
            ['domain' => 'task', 'key_name' => 'high_risk_threshold', 'value' => 70, 'value_type' => 'int', 'description' => 'آستانه ریسک بالا'],
            ['domain' => 'task', 'key_name' => 'medium_risk_threshold', 'value' => 40, 'value_type' => 'int', 'description' => 'آستانه ریسک متوسط'],
            ['domain' => 'task', 'key_name' => 'high_risk_delta', 'value' => 8, 'value_type' => 'int', 'description' => 'افزایش fraud در ریسک بالا'],
            ['domain' => 'task', 'key_name' => 'medium_risk_delta', 'value' => 3, 'value_type' => 'int', 'description' => 'افزایش fraud در ریسک متوسط'],
            ['domain' => 'kyc', 'key_name' => 'rejected_veto_financial', 'value' => 1, 'value_type' => 'bool', 'description' => 'مسدودسازی مالی در KYC ردشده'],
        ];
    }

    private function ensureAdmin(): void
    {
        if (method_exists($this, 'requireAdmin')) {
            $this->requireAdmin();
            return;
        }

        $role = $_SESSION['role'] ?? $_SESSION['user_role'] ?? null;
        if (!in_array($role, ['admin', 'super_admin', 'support'], true)) {
            http_response_code(403);
            exit('Forbidden');
        }
    }

    private function currentAdminId(): ?int
    {
        $id = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;
        return $id ? (int)$id : null;
    }

    private function render(string $viewPath, array $data = []): void
    {
        if (function_exists('view')) {
            echo view($viewPath, $data);
            return;
        }

        extract($data, EXTR_SKIP);
        $full = dirname(__DIR__, 3) . '/views/' . $viewPath . '.php';
        if (is_file($full)) {
            include $full;
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function redirect(string $url): void
    {
        if (function_exists('redirect')) {
            redirect($url);
            return;
        }
        header('Location: ' . $url);
        exit;
    }

    private function flash(string $type, string $message): void
    {
        if (!isset($_SESSION)) {
            @session_start();
        }

        $_SESSION['flash'][$type] = $message;
    }
}