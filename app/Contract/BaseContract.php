<?php

namespace App\Contract;

interface BaseContract
{
    public function all(
        $allowedFilters,
        $allowedSorts,
        bool|null $withPaginate = null,
        array $relation = [],
        int $perPage  = 10,
        string $orderColumn  = 'id',
        string $orderPosition = 'asc',
        array $conditions = [],
    );
    public function find($id, array $relation = []);
    public function create($payloads);
    public function insert($payloads);
    public function update($id, $payloads);
    public function destroy($id);
    public function getWithCondition(
        $conditions,
        $allowedFilters,
        $allowedSorts,
        bool|null $withPaginate = null,
        $relation = [],
        string $orderColumn  = 'id',
        string $orderPosition = 'asc',
        int $perPage  = 10,

    );
    public function updateWithCondition($conditions, $payloads);
    public function bulkDeleteByIds(array $ids);
    public function bulkUpdate(array $ids, $params);
}
