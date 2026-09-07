<?php

namespace App\Service\Workspace;

use App\Contract\Billing\UsageContract;
use App\Contract\Workspace\MembershipContract;
use App\Enums\WorkspaceRole;
use App\Exceptions\Domain\LastOwnerCannotLeave;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\Features;
use Illuminate\Support\Facades\DB;

class MembershipService implements MembershipContract
{
    public function __construct(private readonly UsageContract $usage) {}

    public function changeRole(WorkspaceMember $member, WorkspaceRole $role): WorkspaceMember
    {
        return DB::transaction(function () use ($member, $role) {
            if ($role !== WorkspaceRole::Owner && $this->isLastOwner($member)) {
                throw new LastOwnerCannotLeave;
            }

            $member->update(['role' => $role]);

            return $member->refresh();
        });
    }

    public function remove(WorkspaceMember $member): void
    {
        DB::transaction(function () use ($member) {
            if ($this->isLastOwner($member)) {
                throw new LastOwnerCannotLeave;
            }

            $workspace = $member->workspace;
            $member->delete();

            // Section 7: removing people is how a workspace gets back under its
            // seat limit, so writing has to be restored in the same breath.
            $this->syncSeats($workspace);
        });
    }

    public function syncSeats(Workspace $workspace): void
    {
        DB::transaction(function () use ($workspace) {
            $this->usage->setGauge($workspace, Features::SEATS, $workspace->fresh()->seatsUsed());
            $this->usage->evaluate($workspace);
        });
    }

    /**
     * Counted with a fresh query rather than a loaded relation: a stale
     * collection here would let the last owner delete themselves.
     */
    private function isLastOwner(WorkspaceMember $member): bool
    {
        return $member->role === WorkspaceRole::Owner
            && $member->workspace->owners()->count() === 1;
    }
}
