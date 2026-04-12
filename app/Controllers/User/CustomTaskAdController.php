<?php

namespace App\Controllers\User;

use Core\Controller;
use App\Services\CustomTaskService;
use App\Validators\CustomTaskValidator;
use App\Validators\CustomTaskValidator;
use App\Services\AntiFraud\IPQualityService;
use App\Services\AntiFraud\BrowserFingerprintService;

class CustomTaskAdController extends Controller
{
    private CustomTaskService $service;
	private IPQualityService $ipQualityService;
private BrowserFingerprintService $fingerprintService;

    public function __construct
	(
	
	CustomTaskService $service
    \App\Services\CustomTaskDisputeService $disputeService,
    IPQualityService $ipQualityService,
    BrowserFingerprintService $fingerprintService
	
	)
    {
       
		$this->service = $service;
    $this->disputeService = $disputeService;
    $this->ipQualityService = $ipQualityService;
    $this->fingerprintService = $fingerprintService;
    }

    public function index(): void
    {
        $userId = auth()->id();
        $tasks = $this->service->getAdvertiserTasks($userId);

        $this->view('user.custom-tasks.ad.index', [
            'tasks' => $tasks,
        ]);
    }

    public function create(): void
    {
        $this->view('user.custom-tasks.ad.create');
    }

    public function store(): void
    {
        $userId = auth()->id();

        $payload = [
            'title' => trim((string) ($_POST['title'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'category' => trim((string) ($_POST['category'] ?? 'other')),
            'reward_amount' => (float) ($_POST['reward_amount'] ?? 0),
            'worker_limit' => (int) ($_POST['worker_limit'] ?? 0),
            'expires_at' => (string) ($_POST['expires_at'] ?? ''),
            'proof_rules' => trim((string) ($_POST['proof_rules'] ?? '')),
        ];
		$errors = CustomTaskValidator::validateCreate($payload);
if (!empty($errors)) {
    flash('error', $errors[array_key_first($errors)][0] ?? 'داده ها نامعتبر است.');
    $this->redirect('/custom-tasks/ad/create');
    return;
}
        $result = $this->service->createTask($userId, $payload);

        if (!$result['ok']) {
            flash('error', $result['message'] ?? 'ثبت تسک ناموفق بود.');
            $this->redirect('/custom-tasks/ad/create');
            return;
        }

        flash('success', 'تسک با موفقیت ایجاد شد.');
        $this->redirect('/custom-tasks/ad');
    }

    public function show(int $taskId): void
    {
        $userId = auth()->id();
        $task = $this->service->getAdvertiserTaskById($userId, $taskId);
        $submissions = $this->service->getTaskSubmissions($userId, $taskId);

        if (!$task) {
            abort(404);
            return;
        }

        $this->view('user.custom-tasks.ad.show', [
            'task' => $task,
            'submissions' => $submissions,
        ]);
    }

    public function publish(int $taskId): void
    {
        $userId = auth()->id();
        $result = $this->service->publishTask($userId, $taskId);

        flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('/custom-tasks/ad/' . $taskId);
    }

    public function pause(int $taskId): void
    {
        $userId = auth()->id();
        $result = $this->service->pauseTask($userId, $taskId);

        flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('/custom-tasks/ad/' . $taskId);
    }

    public function cancel(int $taskId): void
    {
        $userId = auth()->id();
        $result = $this->service->cancelTask($userId, $taskId);

        flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('/custom-tasks/ad/' . $taskId);
    }

    public function approveSubmission(int $submissionId): void
    {
        $userId = auth()->id();
        $note = trim((string) ($_POST['note'] ?? ''));

        $result = $this->service->approveSubmission($userId, $submissionId, $note);
$proofPayload = [
    'proof_text' => (string)($_POST['proof_text'] ?? ''),
    'proof_image' => $_FILES['proof_image'] ?? null,
];

$errors = CustomTaskValidator::validateProof($proofPayload);
if (!empty($errors)) {
    flash('error', $errors[array_key_first($errors)][0] ?? 'مدرک نامعتبر است.');
    $this->redirectBack();
    return;
}

// AntiFraud موجود پروژه
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if ($this->ipQualityService->isIPBlacklisted($ip)) {
    flash('error', 'دسترسی از این IP مجاز نیست.');
    $this->redirectBack();
    return;
}

$ipCheck = $this->ipQualityService->check($ip);
$this->ipQualityService->logIPCheck(auth()->id(), $ip, $ipCheck);
if (!empty($ipCheck['is_suspicious'])) {
    flash('error', 'ارسال شما مشکوک تشخیص داده شد. لطفا با پشتیبانی تماس بگیرید.');
    $this->redirectBack();
    return;
}

$fingerprint = (string)($_POST['fingerprint'] ?? '');
if ($fingerprint !== '') {
    if ($this->fingerprintService->isFingerprintBlacklisted($fingerprint)) {
        flash('error', 'این دستگاه مجاز نیست.');
        $this->redirectBack();
        return;
    }

    $analysis = $this->fingerprintService->analyze(auth()->id(), $fingerprint);
    $this->fingerprintService->logAnalysis(auth()->id(), $fingerprint, $analysis);

    if (!empty($analysis['suspicious'])) {
        flash('error', 'الگوی دستگاه مشکوک است.');
        $this->redirectBack();
        return;
    }
}
        flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirectBack();
    }

    public function rejectSubmission(int $submissionId): void
    {
        $userId = auth()->id();
        $reason = trim((string) ($_POST['reason'] ?? ''));

        $result = $this->service->rejectSubmission($userId, $submissionId, $reason);

        flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirectBack();
    }
}