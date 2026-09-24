<?php

namespace App\Service\Workspace;

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\MembershipContract;
use App\Contract\Workspace\WorkspaceContract;
use App\Enums\AccessStatus;
use App\Enums\BillingStatus;
use App\Enums\WorkspaceRole;
use App\Models\AdminUser;
use App\Models\User;
use App\Models\User as UserModel;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkspaceService implements WorkspaceContract
{
    public function __construct(
        private readonly EntitlementContract $entitlements,
        private readonly MembershipContract $memberships,
        private readonly SubscriptionContract $subscriptions,
    ) {}

    /**
     * Section 5 Path A: sign up, name your workspace, you are in on the free
     * tier.
     *
     * Everything here is one unit of work. A workspace with no owner is
     * unreachable, and a workspace with no entitlement snapshot is one whose
     * hard block reads an empty table - so a failure at any step must leave
     * nothing behind at all.
     */
    public function create(User $owner, string $name): Workspace
    {
        return DB::transaction(function () use ($owner, $name) {
            $workspace = Workspace::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'billing_status' => BillingStatus::Unpaid,
                'access_status' => AccessStatus::Active,
                'settings' => [],
            ]);

            WorkspaceMember::create([
                'workspace_id' => $workspace->id,
                'user_id' => $owner->id,
                'role' => WorkspaceRole::Owner,
                'joined_at' => now(),
            ]);

            $owner->update(['current_workspace_id' => $workspace->id]);

            // Throws NoFloorPlanConfigured if the catalog is not set up, which
            // takes the workspace and its membership down with it.
            $this->entitlements->rebuild($workspace);
            $this->memberships->syncSeats($workspace);

            return $workspace->refresh();
        });
    }

    /**
     * Section 3: the last owner cannot leave until they have handed ownership
     * over, so this is the escape hatch - and it must never leave the workspace
     * ownerless in between.
     */
    public function transferOwnership(Workspace $workspace, WorkspaceMember $to): void
    {
        DB::transaction(function () use ($workspace, $to) {
            $workspace->owners()->where('id', '!=', $to->id)
                ->update(['role' => WorkspaceRole::Admin]);

            $to->update(['role' => WorkspaceRole::Owner]);
        });
    }

    public function suspend(Workspace $workspace, AdminUser $admin, string $reason): Workspace
    {
        return DB::transaction(function () use ($workspace, $admin, $reason) {
            $workspace->update([
                'access_status' => AccessStatus::Suspended,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
                'suspended_by_admin_id' => $admin->id,
            ]);

            return $workspace->refresh();
        });
    }

    public function unsuspend(Workspace $workspace): Workspace
    {
        return DB::transaction(function () use ($workspace) {
            $workspace->update([
                'access_status' => AccessStatus::Active,
                'suspended_at' => null,
                'suspension_reason' => null,
                'suspended_by_admin_id' => null,
            ]);

            return $workspace->refresh();
        });
    }

    /**
     * Section 6: "Deleted workspaces are recoverable for 30 days
     * (configurable), then anonymised rather than hard-deleted, so revenue
     * history survives."
     *
     * Nothing is destroyed here. The row is soft-deleted, billing stops, and a
     * purge deadline is stamped for a later job to act on - which is why the
     * members and their data are still all present afterwards.
     */
    public function closeWorkspace(Workspace $workspace): Workspace
    {
        return DB::transaction(function () use ($workspace) {
            // Section 6: a deleted workspace is no longer billed.
            $this->subscriptions->cancel($workspace);

            $workspace->update([
                'access_status' => AccessStatus::Deleted,
                'purge_after' => now()->addDays(config('workspace.retention_days')),
            ]);

            // Nobody should be left pointing at a workspace they can no longer
            // open; ResolveWorkspace would otherwise fall back silently.
            UserModel::where('current_workspace_id', $workspace->id)
                ->update(['current_workspace_id' => null]);

            $workspace->delete();

            return $workspace->refresh();
        });
    }

    public function reopenWorkspace(Workspace $workspace): Workspace
    {
        return DB::transaction(function () use ($workspace) {
            $workspace->restore();

            // Closing overwrites access_status but leaves suspended_at alone,
            // so a suspension survives the round trip instead of being lifted
            // by closing and reopening.
            $workspace->update([
                'access_status' => $workspace->suspended_at !== null
                    ? AccessStatus::Suspended
                    : AccessStatus::Active,
                'purge_after' => null,
            ]);

            // Entitlements were resolved against the free plan on cancellation;
            // re-resolve in case the catalogue moved during the window.
            $this->entitlements->rebuild($workspace);
            $this->memberships->syncSeats($workspace);

            return $workspace->refresh();
        });
    }

    /**
     * Slugs are public (they address the workspace in the switcher), so two
     * customers with the same company name must not collide.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $suffix = 1;

        while (Workspace::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
