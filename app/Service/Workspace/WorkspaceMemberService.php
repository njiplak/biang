<?php

namespace App\Service\Workspace;

use App\Contract\Workspace\WorkspaceMemberContract;
use App\Models\WorkspaceMember;
use App\Service\BaseService;

class WorkspaceMemberService extends BaseService implements WorkspaceMemberContract
{
    protected array $relation = ['user'];

    public function __construct(WorkspaceMember $model)
    {
        parent::__construct($model);
    }
}
