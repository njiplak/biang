<?php

namespace App\Exceptions\Domain;

use App\Models\User;

/** One workspace per customer for now (config workspace.max_owned). */
class WorkspaceLimitReached extends DomainException
{
    public function __construct(public readonly User $user)
    {
        parent::__construct("User {$user->id} already owns the maximum number of workspaces.");
    }

    public function userMessage(): string
    {
        return 'You already have a workspace. Each account has one workspace for now.';
    }
}
