<?php

namespace App\Contract\Project;

use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;

interface ProjectContract
{
    /** Throws LimitReached when the plan's project limit is used up. */
    public function create(Workspace $workspace, User $creator, string $name, ?string $description): Project;

    public function update(Project $project, string $name, ?string $description): Project;

    public function delete(Project $project): void;
}
