<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceMember;

class WorkspaceMemberPolicy
{
    public function view(User $user, WorkspaceMember $member): bool
    {
        return $user->roleIn($member->workspace) !== null;
    }

    /**
     * Section 3: the last owner cannot leave or be removed - they have to hand
     * ownership over first. Checked before role, because it applies even to the
     * owner acting on themselves.
     */
    public function delete(User $user, WorkspaceMember $member): bool
    {
        if ($this->isLastOwner($member)) {
            return false;
        }

        $actorRole = $user->roleIn($member->workspace);

        if ($actorRole === null) {
            return false;
        }

        // anyone may leave of their own accord, subject to the rule above
        if ($user->id === $member->user_id) {
            return true;
        }

        return $actorRole->canManageMembers();
    }

    /**
     * Changing a membership's role. Demoting the last owner is the same problem.
     *
     * Gated on the workspace being writable, unlike delete() above. Section 6
     * says read-only means no writing and a role change is a write - it is not
     * one of the escape hatches. Removing somebody frees a seat and is how a
     * workspace gets back under its limit; transferring ownership is how the
     * last owner leaves. Reshuffling roles fixes nothing, so it waits for a
     * plan like renaming and inviting already do.
     */
    public function update(User $user, WorkspaceMember $member): bool
    {
        if ($this->isLastOwner($member)) {
            return false;
        }

        return $user->roleIn($member->workspace)?->canManageMembers() === true
            && $member->workspace->canWrite();
    }

    /**
     * Only an owner may create another owner. Otherwise an admin - who cannot
     * see billing - could mint an owner who can, and escalate sideways into it.
     */
    public function assignRole(User $user, WorkspaceMember $member, WorkspaceRole $role): bool
    {
        if (! $this->update($user, $member)) {
            return false;
        }

        if ($role === WorkspaceRole::Owner) {
            return $user->roleIn($member->workspace)?->canAdministerWorkspace() === true;
        }

        return true;
    }

    private function isLastOwner(WorkspaceMember $member): bool
    {
        return $member->role === WorkspaceRole::Owner
            && $member->workspace->owners()->count() === 1;
    }
}
