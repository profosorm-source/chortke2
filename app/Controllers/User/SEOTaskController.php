<?php

namespace App\Controllers\User;

use App\Services\SEOTaskService;
use App\Services\SEOKeywordService;
use App\Services\SEOExecutionService;

class SEOTaskController extends BaseUserController
{
    private SEOTaskService      $service;
    private SEOKeywordService   $keywordService;
    private SEOExecutionService $executionService;

    public function __construct(
        
        \App\Services\SEOTaskService $sEOTaskService,
        \App\Services\SEOKeywordService $sEOKeywordService,
        \App\Services\SEOExecutionService $sEOExecutionService)
    {
parent::__construct();
$this->service             = $sEOTaskService;
$this->keywordService      = $sEOKeywordService;
$this->executionService    = $sEOExecutionService;
    }

    public function index()
    {
        $userId     = user_id();
        $keywords   = $this->keywordService->getActiveForExecutor($userId, 15);
        $stats      = $this->executionService->getUserStats($userId);
        $todayCount = $this->executionService->countByExecutorToday($userId);

        return view('user.seo-tasks.index', [
            'keywords'   => $keywords,
            'stats'      => $stats,
            'todayCount' => $todayCount,
        ]);
    }

    public function start(): void
    {
        $body      = $this->request->body();
        $keywordId = (int)($body['keyword_id'] ?? 0);

        if ($keywordId <= 0) {
            $this->response->json(['success' => false, 'message' => 'کلمه نامعتبر.']);
            return;
        }

        $result = $this->service->start($keywordId, user_id());
        $this->response->json($result);
    }

    public function showExecute()
    {
        $id        = (int)$this->request->param('id');
        $execution = $this->executionService->find($id);

        if (!$execution || $execution->executor_id !== user_id()) {
            $this->session->setFlash('error', 'تسک یافت نشد.');
            return redirect(url('/seo-tasks'));
        }

        if (!in_array($execution->status, ['started', 'browsing'])) {
            $this->session->setFlash('error', 'این تسک دیگر قابل انجام نیست.');
            return redirect(url('/seo-tasks'));
        }

        $keyword = $this->keywordService->find($execution->keyword_id);

        return view('user.seo-tasks.execute', [
            'execution' => $execution,
            'keyword'   => $keyword,
        ]);
    }

    public function complete(): void
    {
        $id   = (int)$this->request->param('id');
        $body = $this->request->body();

        $result = $this->service->complete($id, user_id(), [
            'total_duration'  => (int)($body['total_duration']  ?? 0),
            'scroll_duration' => (int)($body['scroll_duration'] ?? 0),
            'browse_duration' => (int)($body['browse_duration'] ?? 0),
            'scroll_data'     => $body['scroll_data']     ?? [],
            'behavior_data'   => $body['behavior_data']   ?? [],
        ]);

        $this->response->json($result);
    }

    public function history()
    {
        $userId     = user_id();
        $page       = (int)($_GET['page'] ?? 1);
        $limit      = 20;
        $offset     = ($page - 1) * $limit;
        $total      = $this->executionService->countByExecutor($userId);
        $executions = $this->executionService->getByExecutor($userId, $limit, $offset);
        $stats      = $this->executionService->getUserStats($userId);
        $totalPages = (int)ceil($total / $limit);

        return view('user.seo-tasks.history', [
            'executions' => $executions,
            'stats'      => $stats,
            'page'       => $page,
            'totalPages' => $totalPages,
            'total'      => $total,
        ]);
    }
}
