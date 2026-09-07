<?php

namespace App\Service\Workspace;

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\InvitationContract;
use App\Contract\Workspace\MembershipContract;
use App\Enums\AddonKind;
use App\Enums\WorkspaceRole;
use App\Exceptions\Domain\InvitationNotAcceptable;
use App\Exceptions\Domain\NoActiveSubscription;
use App\Exceptions\Domain\SeatLimitReached;
use App\Models\AddonPrice;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMember;
use App\Support\Features;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvitationService implements InvitationContract
{
    public function __construct(
        private readonly EntitlementContract $entitlements,
        private readonly MembershipContract $memberships,
        private readonly SubscriptionContract $subscriptions,
    ) {}

    /**
     * Buy one seat, then invite - in a single transaction, so a seat is never
     * paid for against an invitation that fails validation or the duplicate
     * check a moment later.
     */
    public function inviteWithAddedSeat(Workspace $workspace, string $email, WorkspaceRole $role, User $invitedBy): WorkspaceInvitation
    {
        return DB::transaction(function () use ($workspace, $email, $role, $invitedBy) {
            $price = $this->seatAddonPrice($workspace);

            if ($price === null) {
                // Section 12: no payment account on the free tier, so there is
                // no seat to sell - the offer there is an upgrade.
                throw new NoActiveSubscription($workspace);
            }

            $this->subscriptions->purchaseAddon($workspace, $price, 1);

            return $this->invite($workspace, $email, $role, $invitedBy);
        });
    }

    /**
     * The seat add-on sold on this workspace's current plan, if any. Null on
     * the free tier, or when the plan simply does not sell extra seats.
     */
    public function seatAddonPrice(Workspace $workspace): ?AddonPrice
    {
        $plan = $workspace->subscription()->with('plan')->first()?->plan;

        if ($plan === null) {
            return null;
        }

        $addon = $plan->addons()
            ->whereNull('addons.archived_at')
            ->where('kind', AddonKind::Quantity->value)
            ->whereHas('feature', fn ($query) => $query->where('key', Features::SEATS))
            ->first();

        return $addon?->prices()->whereNull('archived_at')->first();
    }

    /**
     * Section 7: a pending invitation reserves a seat, so the check happens
     * here rather than at acceptance. Checking on acceptance would let ten
     * invitations all pass a five seat limit and blow past it later.
     */
    public function invite(Workspace $workspace, string $email, WorkspaceRole $role, User $invitedBy): WorkspaceInvitation
    {
        return DB::transaction(function () use ($workspace, $email, $role, $invitedBy) {
            $this->assertSeatAvailable($workspace);

            [$plain, $hash] = $this->makeToken();

            $invitation = WorkspaceInvitation::create([
                'workspace_id' => $workspace->id,
                'email' => Str::lower($email),
                'role' => $role,
                'token_hash' => $hash,
                'invited_by_user_id' => $invitedBy->id,
                'expires_at' => now()->addDays(7),
                'last_sent_at' => now(),
                'send_count' => 1,
            ]);

            $this->memberships->syncSeats($workspace);

            return $invitation->withPlainToken($plain);
        });
    }

    public function resend(WorkspaceInvitation $invitation): WorkspaceInvitation
    {
        return DB::transaction(function () use ($invitation) {
            // A resend issues a NEW token: the old email may have gone to the
            // wrong address, and the old link should stop working.
            [$plain, $hash] = $this->makeToken();

            $invitation->update([
                'token_hash' => $hash,
                'last_sent_at' => now(),
                'send_count' => $invitation->send_count + 1,
                'expires_at' => now()->addDays(7),
            ]);

            return $invitation->refresh()->withPlainToken($plain);
        });
    }

    public function revoke(WorkspaceInvitation $invitation, User $revokedBy): WorkspaceInvitation
    {
        return DB::transaction(function () use ($invitation, $revokedBy) {
            $invitation->update([
                'revoked_at' => now(),
                'revoked_by_user_id' => $revokedBy->id,
            ]);

            // The reserved seat goes back to the pool.
            $this->memberships->syncSeats($invitation->workspace);

            return $invitation->refresh();
        });
    }

    public function accept(string $plainToken, User $user): WorkspaceMember
    {
        return DB::transaction(function () use ($plainToken, $user) {
            $invitation = WorkspaceInvitation::where('token_hash', $this->hash($plainToken))->first();

            if ($invitation === null) {
                throw new InvitationNotAcceptable('unknown token');
            }

            if (! $invitation->isPending()) {
                throw new InvitationNotAcceptable('not pending');
            }

            if ($user->belongsToWorkspace($invitation->workspace)) {
                throw new InvitationNotAcceptable('already a member');
            }

            $membership = WorkspaceMember::create([
                'workspace_id' => $invitation->workspace_id,
                'user_id' => $user->id,
                'role' => $invitation->role,
                'invited_by_user_id' => $invitation->invited_by_user_id,
                'joined_at' => now(),
            ]);

            $invitation->update([
                'accepted_at' => now(),
                'accepted_by_user_id' => $user->id,
            ]);

            // Section 12: accepting does NOT consume the joiner's trial - that
            // is spent by starting one, and this is somebody else's workspace.
            $this->memberships->syncSeats($invitation->workspace);

            return $membership;
        });
    }

    private function assertSeatAvailable(Workspace $workspace): void
    {
        $limit = $this->entitlements->limitFor($workspace, Features::SEATS);

        // Unlimited seats: nothing to enforce.
        if ($limit === null) {
            return;
        }

        $used = $workspace->seatsUsed();

        if ($used + 1 > $limit) {
            throw new SeatLimitReached($workspace, $used, $limit);
        }
    }

    /** @return array{0: string, 1: string} plaintext for the email, hash for storage */
    private function makeToken(): array
    {
        $plain = Str::random(48);

        return [$plain, $this->hash($plain)];
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
