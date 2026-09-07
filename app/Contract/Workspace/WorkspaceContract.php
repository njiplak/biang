<?php

namespace App\Contract\Workspace;

use App\Models\AdminUser;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

interface WorkspaceContract
{
    /** Create a workspace with its owner, entitlements and seat count, atomically. */
    public function create(User $owner, string $name): Workspace;

    public function transferOwnership(Workspace $workspace, WorkspaceMember $to): void;

    public function suspend(Workspace $workspace, AdminUser $admin, string $reason): Workspace;

    public function unsuspend(Workspace $workspace): Workspace;

    /** Section 6: recoverable for a retention window, then anonymised. Deletes nothing now. */
    public function closeWorkspace(Workspace $workspace): Workspace;

    public function reopenWorkspace(Workspace $workspace): Workspace;
}
