<?php

namespace App\Service\Admin;

use App\Contract\Admin\PageContract;
use App\Models\Page;
use App\Service\BaseService;

class PageService extends BaseService implements PageContract
{
    public function __construct(Page $model)
    {
        parent::__construct($model);
    }
}
