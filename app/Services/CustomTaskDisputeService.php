<?php

namespace App\Services;

use Core\Database;
use App\Models\CustomTaskDispute;
use App\Models\CustomTaskSubmission;

class CustomTaskDisputeService
{
    public function __construct(
        private Database $db,
        private CustomTaskDispute $disputeModel,
        private CustomTaskSubmission $submissionModel,
        private CustomTaskService $taskService
    ) {}

    public function openByExecutor(int $userId, int $submissionId, string $reason, ?string $evidenceImage = null): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'message' => 'دلیل اختلاف الزامی است.'];
        }

        $submission = $this->submissionModel->find($submissionId);
        if (!$submission) {
            return ['ok' => false, 'message' => 'درخواست انجام پیدا نشد.'];
        }

        if ((int)$submission->user_id !== $userId) {
            return ['ok' => false, 'message' => 'شما اجازه ثبت اختلاف برای این مورد را ندارید.'];
        }

        if (!in_array((string)$submission->status, ['rejected', 'disputed'], true)) {
            return ['ok' => false, 'message' => 'اختلاف فقط برای موارد رد شده قابل ثبت است.'];
        }

        if ($this->disputeModel->hasOpenDispute((int)$submission->id)) {
            return ['ok' => false, 'message' => 'برای این مورد قبلا اختلاف باز ثبت شده است.'];
        }

        $this->db->beginTransaction();
        try {
            $dispute = $this->disputeModel->create([
                'task_id' => (int)$submission->task_id,
                'submission_id' => (int)$submission->id,
                'raised_by' => $userId,
                'reason' => $reason,
                'evidence_image' => $evidenceImage,
            ]);

            if (!$dispute) {
                $this->db->rollBack();
                return ['ok' => false, 'message' => 'ثبت اختلاف ناموفق بود.'];
            }

            $this->submissionModel->update((int)$submission->id, [
                'status' => 'disputed',
            ]);

            $this->db->commit();
            return ['ok' => true, 'message' => 'اختلاف با موفقیت ثبت شد.', 'data' => $dispute];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'message' => 'خطای سیستمی در ثبت اختلاف.'];
        }
    }

    public function resolveByAdmin(
        int $adminId,
        int $disputeId,
        string $decision,
        string $adminNote = ''
    ): array {
        $dispute = $this->disputeModel->find($disputeId);
        if (!$dispute) {
            return ['ok' => false, 'message' => 'اختلاف پیدا نشد.'];
        }

        if (!in_array((string)$dispute->status, ['open', 'under_review'], true)) {
            return ['ok' => false, 'message' => 'این اختلاف قبلا بسته شده است.'];
        }

        if (!in_array($decision, ['executor', 'advertiser'], true)) {
            return ['ok' => false, 'message' => 'تصمیم نامعتبر است.'];
        }

        $this->db->beginTransaction();
        try {
            if ($decision === 'executor') {
                $result = $this->taskService->forceApproveSubmissionByAdmin((int)$dispute->submission_id, $adminId);
                if (!$result['ok']) {
                    $this->db->rollBack();
                    return $result;
                }

                $status = 'resolved_for_executor';
                $adminDecision = 'executor';
            } else {
                $result = $this->taskService->forceRejectSubmissionByAdmin((int)$dispute->submission_id, $adminId);
                if (!$result['ok']) {
                    $this->db->rollBack();
                    return $result;
                }

                $status = 'resolved_for_advertiser';
                $adminDecision = 'advertiser';
            }

            $this->disputeModel->update($disputeId, [
                'status' => $status,
                'admin_decision' => $adminDecision,
                'admin_id' => $adminId,
                'admin_note' => trim($adminNote),
                'resolved_at' => date('Y-m-d H:i:s'),
            ]);

            $this->db->commit();
            return ['ok' => true, 'message' => 'اختلاف با موفقیت تعیین تکلیف شد.'];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return ['ok' => false, 'message' => 'خطای سیستمی در بررسی اختلاف.'];
        }
    }

    public function listForAdmin(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        return [
            'items' => $this->disputeModel->adminList($filters, $limit, $offset),
            'total' => $this->disputeModel->adminCount($filters),
        ];
    }
}