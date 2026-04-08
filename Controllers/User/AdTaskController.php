<?php
namespace App\Controllers\User;

use App\Services\CustomTaskService;
use App\Models\CustomTask;
use App\Services\UploadService;

class AdtaskController extends BaseUserController
{
    private CustomTaskService $service;
    private UploadService $upload;

    public function __construct(CustomTask $taskModel, CustomTaskService $service, UploadService $upload)
    {
        parent::__construct();
        $this->service = $service;
        $this->upload  = $upload;
    }

    public function available(): void {
        $userId = (int)user_id();
        $tasks = $this->service->getAvailableForUser($userId, 30, 0);
        view('user.adtask.available', ['title'=>'Adtask — تسک‌های موجود','tasks'=>$tasks]);
    }

    public function mySubmissions(): void {
        $page = max(1,(int)($this->request->get('page')??1));
        $subs = $this->service->getUserSubmissions((int)user_id(), 20, ($page-1)*20);
        view('user.adtask.my-submissions', ['title'=>'اجراهای Adtask من','submissions'=>$subs,'page'=>$page]);
    }

    public function myTasks(): void {
        $tasks = $this->service->getByCreator((int)user_id(), null, 30, 0);
        view('user.adtask.my-tasks', ['title'=>'تسک‌های ساخته‌ام','tasks'=>$tasks,'statusLabels'=>$this->service->statusLabels()]);
    }

    public function create(): void {
        view('user.adtask.create', ['title'=>'ثبت Adtask جدید','taskTypes'=>$this->service->taskTypes(),'proofTypes'=>$this->service->proofTypes(),'feePercent'=>setting('custom_task_site_fee_percent',10)]);
    }

    public function store(): void {
        $userId = (int)user_id();

        // Rate limiting
        try {
            rate_limit('adtask', 'create', "user_{$userId}");
        } catch (\Exception $e) {
            if ($e->getCode() === 429) {
                $this->session->setFlash('error', $e->getMessage());
                redirect(url('/adtask/advertise/create')); return;
            }
        }

        $body = $this->request->body();

        // اعتبارسنجی
        if (empty($body['title']) || mb_strlen($body['title']) < 3) {
            $this->session->setFlash('error', 'عنوان تسک حداقل ۳ کاراکتر باید باشد.');
            redirect(url('/adtask/advertise/create')); return;
        }
        if (empty($body['description']) || mb_strlen($body['description']) < 10) {
            $this->session->setFlash('error', 'توضیحات تسک حداقل ۱۰ کاراکتر باید باشد.');
            redirect(url('/adtask/advertise/create')); return;
        }
        if (empty($body['reward_per_user']) || (float)$body['reward_per_user'] < 100) {
            $this->session->setFlash('error', 'پاداش هر نفر حداقل ۱۰۰ تومان باید باشد.');
            redirect(url('/adtask/advertise/create')); return;
        }
        if (empty($body['max_slots']) || (int)$body['max_slots'] < 1) {
            $this->session->setFlash('error', 'تعداد کاربر مورد نیاز معتبر نیست.');
            redirect(url('/adtask/advertise/create')); return;
        }

        $data = array_merge($body, ['user_id' => $userId]);

        if (!empty($_FILES['sample_image']['name'])) {
            $up = $this->upload->upload($_FILES['sample_image'], 'adtask');
            if ($up['success']) $data['sample_image'] = $up['path'];
        }

        $r = $this->service->create($data);
        $this->session->setFlash($r['success']?'success':'error', $r['success']?'Adtask ثبت شد.':($r['message']??'خطا'));
        redirect($r['success']?url('/adtask/advertise'):url('/adtask/advertise/create'));
    }

    public function show(): void {
        $id = (int)$this->request->param('id');
        $task = $this->service->getByCreator((int)user_id(), $id, 1, 0);
        if (!$task) { redirect(url('/adtask/advertise')); return; }
        view('user.adtask.show', ['title'=>'مدیریت Adtask','task'=>$task[0]??$task,'submissions'=>$this->service->getSubmissions($id,20,0)]);
    }

    public function showTask(): void {
        $id = (int)$this->request->param('id');
        $task = $this->service->findPublic($id, user_id());
        if (!$task) { redirect(url('/adtask')); return; }
        view('user.adtask.task-detail', ['title'=>'جزئیات Adtask','task'=>$task]);
    }

    public function start(): void {
        $r = $this->service->start((int)($this->request->body()['task_id']??0), (int)user_id());
        $this->response->json($r);
    }

    public function submitProof(): void {
        $id = (int)$this->request->param('id');
        $file = null;
        if (!empty($_FILES['proof_file']['name'])) {
            $up = $this->upload->upload($_FILES['proof_file'], 'adtask-proof');
            if ($up['success']) $file = $up['path'];
        }
        $r = $this->service->submitProof($id, (int)user_id(), $this->request->post('proof_text')??'', $file);
        if (is_ajax()) { $this->response->json($r); return; }
        $this->session->setFlash($r['success']?'success':'error', $r['message']);
        redirect(url('/adtask/my-submissions'));
    }

    public function review(): void {
        $r = $this->service->review($this->request->body(), (int)user_id());
        if (is_ajax()) { $this->response->json($r); return; }
        $this->session->setFlash($r['success']?'success':'error', $r['message']);
        redirect(url('/adtask/advertise'));
    }

    public function dispute(): void {
        $r = $this->service->dispute($this->request->body(), (int)user_id());
        if (is_ajax()) { $this->response->json($r); return; }
        $this->session->setFlash($r['success']?'success':'error', $r['message']);
        redirect(url('/adtask/my-submissions'));
    }
}
