<?php

namespace App\Controllers\Admin;

use Core\Database;
use Core\Scheduler;
use App\Services\EmailService;
use App\Models\EmailQueue;

// CronController — مدیریت Cron Jobs
class CronController extends BaseAdminController
{
    private Scheduler $scheduler;

    public function __construct(Scheduler $scheduler)
    {
        parent::__construct();
        $this->scheduler = $scheduler;
    }

    public function index(): void
    {
        view('admin/cron/index', ['title' => 'مدیریت Cron Jobs']);
    }

    public function run(): void
    {
        ob_start();
        $_SERVER['argv'] = ['cron.php'];
        if (file_exists(BASE_PATH . '/cron.php')) {
            require BASE_PATH . '/cron.php';
        }
        ob_get_clean();

        $results = $this->scheduler->run();
        $this->response->json(['success' => true, 'results' => $results]);
    }
}