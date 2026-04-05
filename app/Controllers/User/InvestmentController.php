<?php
// app/Controllers/User/InvestmentController.php

namespace App\Controllers\User;

use App\Models\Investment;
use App\Models\TradingRecord;
use App\Models\InvestmentProfit;
use App\Models\InvestmentWithdrawal;
use App\Services\InvestmentService;
use Core\Validator;
use App\Services\ApiRateLimiter;
use App\Controllers\User\BaseUserController;

class InvestmentController extends BaseUserController
{
    private \App\Services\NotificationService $notificationService;
    private \App\Services\WalletService $walletService;
    private \App\Models\TradingRecord $tradingRecordModel;
    private \App\Models\InvestmentWithdrawal $investmentWithdrawalModel;
    private \App\Models\InvestmentProfit $investmentProfitModel;
    private \App\Models\Investment $investmentModel;
    private InvestmentService $investmentService;

    public function __construct(
        \App\Models\Investment $investmentModel,
        \App\Models\InvestmentProfit $investmentProfitModel,
        \App\Models\InvestmentWithdrawal $investmentWithdrawalModel,
        \App\Models\TradingRecord $tradingRecordModel,
        \App\Services\WalletService $walletService,
        \App\Services\NotificationService $notificationService,
        \App\Services\InvestmentService $investmentService)
    {
        parent::__construct();
        $this->investmentService = $investmentService;
        $this->investmentModel = $investmentModel;
        $this->investmentProfitModel = $investmentProfitModel;
        $this->investmentWithdrawalModel = $investmentWithdrawalModel;
        $this->tradingRecordModel = $tradingRecordModel;
        $this->walletService = $walletService;
        $this->notificationService = $notificationService;
    }

    /**
     * صفحه اصلی سرمایه‌گذاری (داشبورد)
     */
    public function index()
    {
        $userId = user_id();
        $user = auth();
        if (!$user) {
            $this->response->redirect(url('login'));
            exit;
        }

        $investModel = $this->investmentModel;
        $activeInvestment = $investModel->getActiveByUser($userId);

        return view('user.investment.index', [
            'user'             => $user,
            'activeInvestment' => $activeInvestment,
            'settings'         => $this->investmentService->getSettings(),
            'isDepositLocked'  => $investModel->isDepositLocked($userId),
        ]);
    }

    /**
     * صفحه ثبت سرمایه‌گذاری
     */
    public function create()
    {
        $investModel = $this->investmentModel;
        $userId = user_id();

        if ($investModel->hasActiveInvestment($userId)) {
                        $this->session->setFlash('error', 'شما یک پلن فعال دارید. امکان ایجاد پلن جدید نیست.');
            return redirect(url('/investment'));
        }

        $user = auth();
if (!$user) {
    $this->response->redirect(url('login'));
    exit;
}

        return view('user.investment.create', [
            'user' => $user,
            'riskWarning' => $this->investmentService->getRiskWarning(),
            'settings' => $this->investmentService->getSettings(),
            'isDepositLocked' => $investModel->isDepositLocked($userId),
        ]);
    }

    /**
     * ثبت سرمایه‌گذاری (POST - AJAX)
     */
    public function store()
    {
                $input = \json_decode(\file_get_contents('php://input'), true) ?? $_POST;

        $validator = new Validator($input, [
            'amount' => 'required|numeric|min:1',
            'risk_accepted' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false,
                'message' => 'اطلاعات ورودی نامعتبر است.',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->data();

// ✅ قبل از آرایه
ApiRateLimiter::enforce('investment_create', (int)user_id(), is_ajax());

$result = $this->investmentService->createInvestment((int)user_id(), [
    'amount' => (float)($data['amount'] ?? 0),
    'risk_accepted' => (int)($data['risk_accepted'] ?? 0),
]);

$this->response->json($result, $result['success'] ? 200 : 422);
return;
}
    /**
     * درخواست برداشت (POST - AJAX)
     */
    public function withdraw()
    {
                $input = \json_decode(\file_get_contents('php://input'), true) ?? $_POST;

        $validator = new Validator($input, [
            'withdrawal_type' => 'required|in:profit_only,full_close',
        ]);

        if ($validator->fails()) {
            return $this->response->json([
                'success' => false,
                'message' => 'نوع برداشت را انتخاب کنید.',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->data();
        $result = $this->investmentService->requestWithdrawal(user_id(), [
            'withdrawal_type' => $data['withdrawal_type'],
        ]);

        return $this->response->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * تاریخچه سود/ضرر
     */
    public function profitHistory()
    {
        $userId = user_id();
        $profitModel = $this->investmentProfitModel;

        $page = \max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $profits = $profitModel->getByUser($userId, $perPage, $offset);
        $total = $profitModel->countByUser($userId);
        $totalPages = \ceil($total / $perPage);

        $user = auth();
if (!$user) {
    $this->response->redirect(url('login'));
    exit;
}

        return view('user.investment.profit-history', [
            'user' => $user,
            'profits' => $profits,
            'total' => $total,
            'totalPages' => $totalPages,
            'currentPage' => $page,
        ]);
    }
}