<?php

namespace App\Controllers\Admin;

use App\Services\KpiService;
use App\Services\ExportService;
use App\Controllers\Admin\BaseAdminController;

class KpiController extends BaseAdminController
{
    private ExportService $exportService;

    private KpiService $kpiService;

    public function __construct(
        \App\Services\ExportService $exportService,
        \App\Services\KpiService $kpiService)
    {
        parent::__construct();
        $this->exportService = $exportService;
        $this->kpiService = $kpiService;
    }

    /**
     * داشبورد KPI اصلی
     */
    public function index()
    {
        $userStats = $this->kpiService->getUserStats();
        $financialStats = $this->kpiService->getFinancialStats();
        $taskStats = $this->kpiService->getTaskStats();
        $ticketStats = $this->kpiService->getTicketStats();
        $fraudStats = $this->kpiService->getFraudStats();
        $churnRate = $this->kpiService->getChurnRate();
        $conversionRate = $this->kpiService->getConversionRate();

        return view('admin.kpi.index', [
            'userStats' => $userStats,
            'financialStats' => $financialStats,
            'taskStats' => $taskStats,
            'ticketStats' => $ticketStats,
            'fraudStats' => $fraudStats,
            'churnRate' => $churnRate,
            'conversionRate' => $conversionRate,
        ]);
    }

    /**
     * داده‌های نمودار (AJAX)
     */
    public function chartData()
    {
                
        $type = $this->request->get('type') ?: 'revenue';
        $days = (int)($this->request->get('days') ?: 30);
        $days = \min($days, 365);

        $data = match ($type) {
            'revenue' => $this->kpiService->getDailyRevenue($days),
            'registrations' => $this->kpiService->getDailyRegistrations($days),
            'tasks' => $this->kpiService->getDailyCompletedTasks($days),
            'deposits_withdrawals' => $this->kpiService->getDailyDepositsWithdrawals($days),
            'platforms' => $this->kpiService->getTasksByPlatform(),
            'hourly' => $this->kpiService->getHourlyActivity(\min($days, 30)),
            default => [],
        };

        return $this->response->json(['success' => true, 'type' => $type, 'data' => $data]);
    }

    /**
     * جزئیات مالی
     */
    public function financial()
    {
        $financialStats = $this->kpiService->getFinancialStats();
        $dailyRevenue = $this->kpiService->getDailyRevenue(30);
        $dailyDW = $this->kpiService->getDailyDepositsWithdrawals(30);
        $investmentStats = $this->kpiService->getInvestmentStats();
        $referralStats = $this->kpiService->getReferralStats();

        return view('admin.kpi.financial', [
            'financialStats' => $financialStats,
            'dailyRevenue' => $dailyRevenue,
            'dailyDW' => $dailyDW,
            'investmentStats' => $investmentStats,
            'referralStats' => $referralStats,
        ]);
    }

    /**
     * جزئیات کاربران
     */
    public function users()
    {
        $userStats = $this->kpiService->getUserStats();
        $dailyReg = $this->kpiService->getDailyRegistrations(30);
        $topUsers = $this->kpiService->getTopUsers(20);
        $lotteryStats = $this->kpiService->getLotteryStats();

        return view('admin.kpi.users', [
            'userStats' => $userStats,
            'dailyReg' => $dailyReg,
            'topUsers' => $topUsers,
            'lotteryStats' => $lotteryStats,
        ]);
    }

    /**
     * خروجی CSV کاربران
     */
    public function exportUsers()
    {
                $filters = [
            'date_from' => $this->request->get('date_from'),
            'date_to' => $this->request->get('date_to'),
        ];

        $data = $this->exportService->prepareUsersExport($filters);
        $this->exportService->exportCsv($data['headers'], $data['rows'], 'users_export');
    }

    /**
     * خروجی CSV تراکنش‌ها
     */
    public function exportTransactions()
    {
                $filters = [
            'date_from' => $this->request->get('date_from'),
            'date_to' => $this->request->get('date_to'),
            'type' => $this->request->get('type'),
            'status' => $this->request->get('status'),
        ];

        $data = $this->exportService->prepareTransactionsExport($filters);
        $this->exportService->exportCsv($data['headers'], $data['rows'], 'transactions_export');
    }

    /**
     * خروجی JSON خلاصه
     */
    public function exportSummary()
    {
        $summary = $this->kpiService->getDashboardSummary();

        $this->exportService->exportJson($summary, 'kpi_summary');
    }
}