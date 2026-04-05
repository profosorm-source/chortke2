<?php

namespace App\Services;

use App\Models\SEOExecution;

/**
 * Service لایه میانی برای SEOExecution Model
 */
class SEOExecutionService
{
    private SEOExecution $model;

    public function __construct(
        \App\Models\SEOExecution $model
    )
    {
        $this->model = $model;
    }

    public function find(int $id): ?object
    {
        return $this->model->find($id);
    }

    public function getUserStats(int $userId): object
    {
        return $this->model->getUserStats($userId);
    }

    public function countByExecutorToday(int $userId): int
    {
        return $this->model->countByExecutorToday($userId);
    }

    public function getByExecutor(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->model->getByExecutor($userId, $limit, $offset);
    }

    public function countByExecutor(int $userId): int
    {
        return $this->model->countByExecutor($userId);
    }
}
