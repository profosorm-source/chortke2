<?php

namespace App\Controllers\Admin;

use Core\Database;
use App\Services\UserScoreService;

class ScoreManagementController
{
    private Database $db;
    private UserScoreService $userScoreService;

    public function __construct(Database $db, UserScoreService $userScoreService)
    {
        $this->db = $db;
        $this->userScoreService = $userScoreService;
    }

    public function showUserScores($id): void
    {
        $this->ensureAdmin();
        $userId = (int)$id;

        $user = $this->getUser($userId);
        if (!$user) {
            http_response_code(404);
            exit('User not found');
        }

        $fraudRaw = (float)($user['fraud_score'] ?? 0);
        $fraudEffective = $this->userScoreService->getEffectiveScore($userId, 'fraud', $fraudRaw);

        $taskRaw = $this->getTaskRawRisk($userId);
        $taskEffective = $this->userScoreService->getEffectiveScore($userId, 'task', $taskRaw);

        $fraudAdjustments = $this->userScoreService->getActiveAdjustments($userId, 'fraud');
        $taskAdjustments = $this->userScoreService->getActiveAdjustments($userId, 'task');

        $recentEvents = $this->getRecentEvents($userId, 50);

        $this->render('admin/fraud/user-scores', [
            'user' => $user,
            'fraud_raw' => $fraudRaw,
            'fraud_effective' => $fraudEffective,
            'task_raw' => $taskRaw,
            'task_effective' => $taskEffective,
            'fraud_adjustments' => $fraudAdjustments,
            'task_adjustments' => $taskAdjustments,
            'events' => $recentEvents,
        ]);
    }

    public function adjustScore($id): void
    {
        $this->ensureAdmin();

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/admin/users/' . (int)$id . '/scores');
        }

        $userId = (int)$id;
        $domain = trim((string)($_POST['domain'] ?? 'fraud'));
        $operation = strtolower(trim((string)($_POST['operation'] ?? 'add')));
        $value = (float)($_POST['value'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $expiresAt = trim((string)($_POST['expires_at'] ?? ''));

        if (!in_array($domain, ['fraud', 'task'], true)) {
            $this->flash('error', 'دامنه امتیاز نامعتبر است.');
            $this->redirect('/admin/users/' . $userId . '/scores');
        }

        if (!in_array($operation, ['set', 'add', 'subtract'], true)) {
            $this->flash('error', 'عملیات نامعتبر است.');
            $this->redirect('/admin/users/' . $userId . '/scores');
        }

        if ($reason === '') {
            $this->flash('error', 'ثبت دلیل برای اصلاح امتیاز الزامی است.');
            $this->redirect('/admin/users/' . $userId . '/scores');
        }

        $adminId = $this->currentAdminId();

        $stmt = $this->db->prepare("
            INSERT INTO user_score_adjustments
                (user_id, domain, operation, value, reason, expires_at, created_by, created_at, is_active)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, NOW(), 1)
        ");

        $ok = $stmt->execute([
            $userId,
            $domain,
            $operation,
            $value,
            ($reason !== '' ? $reason : null),
            ($expiresAt !== '' ? $expiresAt : null),
            $adminId,
        ]);

        if ($ok) {
            // رویداد audit
            $ev = $this->db->prepare("
                INSERT INTO user_score_events (user_id, domain, source, delta, meta_json, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");

            $delta = 0.0;
            if ($operation === 'add') {
                $delta = $value;
            } elseif ($operation === 'subtract') {
                $delta = -1 * $value;
            }

            $ev->execute([
                $userId,
                $domain,
                'admin_adjustment',
                $delta,
                json_encode([
                    'operation' => $operation,
                    'value' => $value,
                    'reason' => $reason,
                    'admin_id' => $adminId,
                    'expires_at' => ($expiresAt !== '' ? $expiresAt : null),
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $this->flash('success', 'اصلاح امتیاز با موفقیت ثبت شد.');
        } else {
            $this->flash('error', 'خطا در ثبت اصلاح امتیاز.');
        }

        $this->redirect('/admin/users/' . $userId . '/scores');
    }

    public function revokeAdjustment($id): void
    {
        $this->ensureAdmin();

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->redirect('/admin/dashboard');
        }

        $adjustmentId = (int)$id;
        $reason = trim((string)($_POST['reason'] ?? 'revoke_by_admin'));
        $adminId = $this->currentAdminId();

        // برای ثبت event نیاز به user/domain داریم
        $find = $this->db->prepare("
            SELECT id, user_id, domain, operation, value
            FROM user_score_adjustments
            WHERE id = ?
            LIMIT 1
        ");
        $find->execute([$adjustmentId]);
        $adj = $find->fetch(\PDO::FETCH_ASSOC);

        if (!$adj) {
            $this->flash('error', 'رکورد اصلاح امتیاز یافت نشد.');
            $this->redirect('/admin/dashboard');
        }

        $stmt = $this->db->prepare("
            UPDATE user_score_adjustments
            SET is_active = 0
            WHERE id = ?
            LIMIT 1
        ");
        $ok = $stmt->execute([$adjustmentId]);

        if ($ok) {
            $ev = $this->db->prepare("
                INSERT INTO user_score_events (user_id, domain, source, delta, meta_json, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $ev->execute([
                (int)$adj['user_id'],
                (string)$adj['domain'],
                'admin_adjustment_revoke',
                0,
                json_encode([
                    'adjustment_id' => $adjustmentId,
                    'reason' => $reason,
                    'admin_id' => $adminId,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            $this->flash('success', 'اصلاح امتیاز غیرفعال شد.');
            $this->redirect('/admin/users/' . (int)$adj['user_id'] . '/scores');
        }

        $this->flash('error', 'خطا در غیرفعال‌سازی اصلاح امتیاز.');
        $this->redirect('/admin/dashboard');
    }

    public function history($id): void
    {
        $this->ensureAdmin();
        $userId = (int)$id;

        $user = $this->getUser($userId);
        if (!$user) {
            http_response_code(404);
            exit('User not found');
        }

        $events = $this->getRecentEvents($userId, 200);

        // اگر ویوی تاریخچه جدا نداری، همین view اصلی را با events بیشتر باز کن
        $this->render('admin/fraud/user-scores', [
            'user' => $user,
            'fraud_raw' => (float)($user['fraud_score'] ?? 0),
            'fraud_effective' => $this->userScoreService->getEffectiveScore($userId, 'fraud', (float)($user['fraud_score'] ?? 0)),
            'task_raw' => $this->getTaskRawRisk($userId),
            'task_effective' => $this->userScoreService->getEffectiveScore($userId, 'task', $this->getTaskRawRisk($userId)),
            'fraud_adjustments' => $this->userScoreService->getActiveAdjustments($userId, 'fraud'),
            'task_adjustments' => $this->userScoreService->getActiveAdjustments($userId, 'task'),
            'events' => $events,
        ]);
    }

    private function getUser(int $userId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, username, email, status, kyc_status, fraud_score
            FROM users
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function getTaskRawRisk(int $userId): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(AVG(fraud_score), 0) AS avg_score
            FROM task_executions
            WHERE executor_id = ?
        ");
        $stmt->execute([$userId]);

        return (float)$stmt->fetchColumn();
    }

    private function getRecentEvents(int $userId, int $limit = 50): array
    {
        $stmt = $this->db->prepare("
            SELECT id, domain, source, delta, meta_json, created_at
            FROM user_score_events
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT " . (int)$limit
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
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