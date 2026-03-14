<?php

namespace App\Service\Setting;

use App\Contract\Setting\RoleContract;
use App\Service\BaseService;
use Exception;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class RoleService extends BaseService implements RoleContract
{
    protected array $relation = ['permissions'];

    public function __construct(Role $model)
    {
        parent::__construct($model);
    }

    public function create($payloads)
    {
        try {
            $permissions = $payloads['permissions'] ?? [];
            unset($payloads['permissions']);

            DB::beginTransaction();
            $model = $this->model->create($payloads);
            $model->syncPermissions($permissions);
            DB::commit();

            return $model->fresh($this->relation);
        } catch (Exception $e) {
            DB::rollBack();
            return $e;
        }
    }

    public function update($id, $payloads)
    {
        try {
            $permissions = $payloads['permissions'] ?? [];
            unset($payloads['permissions']);

            DB::beginTransaction();
            $model = $this->model->findOrFail($id);
            $model->update($payloads);
            $model->syncPermissions($permissions);
            DB::commit();

            return $model->fresh($this->relation);
        } catch (Exception $e) {
            DB::rollBack();
            return $e;
        }
    }
}
