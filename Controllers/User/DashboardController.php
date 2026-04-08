<?php

namespace App\Controllers\User;

use App\Services\UserDashboardService;
use App\Models\TaskExecution;
use App\Models\Ticket;
use Core\Container;

/**
 * DashboardController
 *
 * وابستگی‌ها از طریق constructor injection (Container auto-wire):
 *   UserDashboardService → inject می‌شود
 *   BaseUserController   → parent::__construct() از Container می‌گیرد
 */
class DashboardController extends BaseUserController
{
    private UserDashboardService $dashboardService;

    public function __construct(UserDashboardService $dashboardService)
    {
        parent::__construct();
        $this->dashboardService = $dashboardService;
    }

    public function index(): void
    {
        $userId = $this->userId();
        if (!$userId) {
            $this->response->redirect(url('/login'));
            return;
        }

        try {
            $data = $this->dashboardService->getDashboardData($userId);
        } catch (\Throwable $e) {
            // fallback اگه سرویس خطا داد
            error_log('[Dashboard] ' . $e->getMessage());
            $data = [
                'wallet'        => (object)['balance_irt'=>0,'balance_usdt'=>0,'locked_irt'=>0],
                'tasks'         => (object)['completed'=>0,'pending'=>0,'rejected'=>0,'total'=>0,'earned'=>0],
                'transactions'  => (object)['total_deposits_irt'=>0,'total_withdraws_irt'=>0,'pending_count'=>0,'recent'=>[]],
                'campaigns'     => (object)['total'=>0,'recent'=>[]],
                'level'         => (object)['name'=>'SILVER','slug'=>'silver','progress'=>0,'is_max'=>false,'current'=>null,'next'=>null,'details'=>[]],
                'referral'      => (object)['referred_count'=>0,'total_earned_irt'=>0,'pending_irt'=>0,'paid_count'=>0],
                'notifications' => (object)['unread_count'=>0,'latest'=>[]],
                'charts'        => (object)['earnings'=>['labels'=>[],'values'=>[]],'platforms'=>['labels'=>[],'values'=>[]]],
            ];
        }

        $wallet        = $data['wallet'];
        $tasks         = $data['tasks'];
        $transactions  = $data['transactions'];
        $campaigns     = $data['campaigns'];
        $level         = $data['level'];
        $referral      = $data['referral'];
        $notifications = $data['notifications'];
        $charts        = $data['charts'];

        // تاریخچه تسک‌های اخیر کاربر
        $recentTaskExecutions = [];
        try {
            $taskExecutionModel   = Container::make(TaskExecution::class);
            $recentTaskExecutions = $taskExecutionModel->getByExecutor($userId, [], 5, 0);
        } catch (\Throwable $e) {
            error_log('[Dashboard] TaskExecution fetch failed: ' . $e->getMessage());
        }

        // تعداد تیکت‌های باز کاربر
        $openTicketCount = 0;
        try {
            $ticketModel     = Container::make(Ticket::class);
            $openTicketCount = $ticketModel->countUserTickets($userId, 'open')
                             + $ticketModel->countUserTickets($userId, 'pending');
        } catch (\Throwable $e) {
            error_log('[Dashboard] Ticket count failed: ' . $e->getMessage());
        }

        view('user/dashboard', [
            'title'              => 'داشبورد',
            // کیف پول
            'walletBalance'      => $wallet->balance_irt      ?? 0,
            'walletBalanceUsdt'  => $wallet->balance_usdt     ?? 0,
            'lockedBalance'      => $wallet->locked_irt       ?? 0,
            // تسک‌ها
            'tasksCompleted'     => $tasks->completed         ?? 0,
            'tasksPending'       => $tasks->pending           ?? 0,
            'tasksRejected'      => $tasks->rejected          ?? 0,
            'tasksTotal'         => $tasks->total             ?? 0,
            'tasksEarned'        => $tasks->earned            ?? 0,
            // تراکنش‌ها
            'totalDeposits'      => $transactions->total_deposits_irt  ?? 0,
            'totalWithdraws'     => $transactions->total_withdraws_irt ?? 0,
            'pendingTxCount'     => $transactions->pending_count       ?? 0,
            'recentTransactions' => $transactions->recent             ?? [],
            // کمپین‌ها
            'activeCampaigns'    => $campaigns->total  ?? 0,
            'recentAds'          => $campaigns->recent ?? [],
            // سطح
            'currentLevel'       => $level->name     ?? 'SILVER',
            'levelSlug'          => $level->slug     ?? 'silver',
            'levelProgress'      => $level->progress ?? 0,
            'levelIsMax'         => $level->is_max   ?? false,
            'levelCurrent'       => $level->current  ?? null,
            'levelNext'          => $level->next     ?? null,
            'levelDetails'       => $level->details  ?? [],
            // ارجاع
            'referralCount'      => $referral->referred_count   ?? 0,
            'referralEarnings'   => $referral->total_earned_irt ?? 0,
            'referralPending'    => $referral->pending_irt      ?? 0,
            // اعلان‌ها
            'notifCount'         => $notifications->unread_count ?? 0,
            'topNotifications'   => $notifications->latest       ?? [],
            // نمودارها
            'chartLabels'        => $charts->earnings['labels']  ?? [],
            'chartData'          => $charts->earnings['values']  ?? [],
            'platformLabels'     => $charts->platforms['labels'] ?? [],
            'platformData'       => $charts->platforms['values'] ?? [],
            // مالی
            'totalEarnings'          => $tasks->earned ?? 0,
            // تسک‌های اخیر برای داشبورد
            'recentTaskExecutions'   => $recentTaskExecutions,
            // تیکت‌های باز
            'openTicketCount'        => $openTicketCount,
        ]);
    }
}
