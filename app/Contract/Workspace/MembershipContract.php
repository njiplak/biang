<?php

namespace App\Contract\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

interface MembershipContract
{
    public function changeRole(WorkspaceMember $member, WorkspaceRole $role): WorkspaceMember;

    public function remove(WorkspaceMember $member): void;

    /** Recount seats and re-evaluate the hard block. Safe to call at any time. */
    public function syncSeats(Workspace $workspace): void;
}
