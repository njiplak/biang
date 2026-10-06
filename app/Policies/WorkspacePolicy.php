<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

/**
 * Spec section 2: "am I allowed to do this" is always answered per workspace,
 * never by who the person is. Every ability here starts by asking what role
 * this user holds in THIS workspace.
 */
class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace) !== null
            && $workspace->canRead();
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->canManageMembers() === true
            && $workspace->canWrite();
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->canAdministerWorkspace() === true;
    }

    /**
     * Section 6: a closed workspace is recoverable until its purge date. Only
     * an owner, the same person who could close it, and never once it has been
     * anonymised - there is nothing left to give back.
     */
    public function restore(User $user, Workspace $workspace): bool
    {
        return $workspace->trashed()
            && $workspace->anonymized_at === null
            && $workspace->purge_after?->isFuture() === true
            && $this->role($user, $workspace)?->canAdministerWorkspace() === true;
    }

    public function transferOwnership(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->canAdministerWorkspace() === true;
    }

    /**
     * Deliberately NOT gated on canWrite(). A workspace that is over its limit
     * or past due must still be able to reach billing - otherwise the customer
     * cannot buy the upgrade that fixes the very thing blocking them
     * (sections 7 and 9).
     */
    public function manageBilling(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->canManageBilling() === true
            && $workspace->canRead();
    }

    public function inviteMembers(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->canManageMembers() === true
            && $workspace->canWrite();
    }

    /**
     * Section 7's hard block, enforced in authorisation rather than only in the
     * UI. Both halves must pass: the person's role permits writing AND the
     * workspace itself is in a state that permits writing.
     */
    public function write(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->canWrite() === true
            && $workspace->canWrite();
    }

    private function role(User $user, Workspace $workspace): ?WorkspaceRole
    {
        return $user->roleIn($workspace);
    }
}
