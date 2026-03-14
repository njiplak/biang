<?php

namespace App\Service\Setting;

use App\Contract\Setting\PermissionContract;
use App\Service\BaseService;
use Spatie\Permission\Models\Permission;

class PermissionService extends BaseService implements PermissionContract
{
    public function __construct(Permission $model)
    {
        parent::__construct($model);
    }
}
