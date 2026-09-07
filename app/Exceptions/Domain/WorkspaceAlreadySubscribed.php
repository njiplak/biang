<?php

namespace App\Exceptions\Domain;

use App\Models\Workspace;

/** Section 12: one payment account per workspace, so ownership transfers cleanly. */
class WorkspaceAlreadySubscribed extends DomainException
{
    public function __construct(public readonly Workspace $workspace)
    {
        parent::__construct("Workspace {$workspace->id} already has a live subscription.");
    }

    public function userMessage(): string
    {
        return 'This workspace already has an active plan. Change the existing plan instead.';
    }
}
