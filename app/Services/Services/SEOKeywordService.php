<?php

namespace App\Services;

use App\Models\SEOKeyword;

/**
 * Service لایه میانی بین SEOKeywordController و SEOKeyword Model
 */
class SEOKeywordService
{
    private SEOKeyword $model;

    public function __construct(
        \App\Models\SEOKeyword $model
    )
    {
        $this->model = $model;
    }

    public function getAll(array $filters = [], int $limit = 30, int $offset = 0): array
    {
        return $this->model->getAll($filters, $limit, $offset);
    }

    public function countAll(array $filters = []): int
    {
        return $this->model->countAll($filters);
    }

    public function getStats(): object
    {
        return $this->model->getStats();
    }

    public function find(int $id): ?object
    {
        return $this->model->find($id);
    }

    public function create(array $data): ?object
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): bool
    {
        return (bool)$this->model->update($id, $data);
    }

    public function toggleActive(int $id): array
    {
        $keyword = $this->model->find($id);
        if (!$keyword) {
            return ['success' => false, 'message' => 'یافت نشد.'];
        }

        $newStatus = $keyword->is_active ? 0 : 1;
        $this->model->update($id, ['is_active' => $newStatus]);

        return [
            'success' => true,
            'message' => 'وضعیت تغییر کرد.',
            'was_active' => (bool)$keyword->is_active,
        ];
    }

    public function delete(int $id): bool
    {
        return $this->model->softDelete($id);
    }

    // متدهای مورد نیاز User/SEOTaskController
    public function getActiveForExecutor(int $userId, int $limit = 15): array
    {
        return $this->model->getActiveForExecutor($userId, $limit);
    }
}
