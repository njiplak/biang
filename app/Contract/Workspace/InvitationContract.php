<?php

namespace App\Contract\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\AddonPrice;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;

interface InvitationContract
{
    /** Throws SeatLimitReached when the workspace has no seat to give. */
    public function invite(Workspace $workspace, string $email, WorkspaceRole $role, User $invitedBy): WorkspaceInvitation;

    /**
     * Section 7: "We offer a paid seat add-on... right there in the invite
     * flow. If they take it, the invite goes out." One unit of work - a seat
     * is never bought for an invitation that then fails.
     */
    public function inviteWithAddedSeat(Workspace $workspace, string $email, WorkspaceRole $role, User $invitedBy): WorkspaceInvitation;

    /** The seat add-on sold on this workspace's plan, or null if there is none to sell. */
    public function seatAddonPrice(Workspace $workspace): ?AddonPrice;

    public function resend(WorkspaceInvitation $invitation): WorkspaceInvitation;

    public function revoke(WorkspaceInvitation $invitation, User $revokedBy): WorkspaceInvitation;

    /** Section 5 Path C: joins an existing workspace. No new workspace, no trial. */
    public function accept(string $plainToken, User $user): WorkspaceMember;
}
