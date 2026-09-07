<?php

namespace App\Contract\Billing;

use App\Models\AdminUser;
use App\Models\Feature;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlementOverride;
use DateTimeInterface;

interface EntitlementContract
{
    /**
     * Recompute the workspace's entitlement snapshot from plan + add-ons +
     * staff overrides. Call after anything that changes what they are owed.
     */
    public function rebuild(Workspace $workspace): void;

    /** Null means unlimited, or that no entitlement exists - use allows() to decide. */
    public function limitFor(Workspace $workspace, string $featureKey): ?int;

    public function allows(Workspace $workspace, string $featureKey, int $usage): bool;

    /**
     * Section 7's pre-flight check, for the write that would breach a limit
     * rather than the state of having breached one. Throws LimitReached.
     *
     * This is the seam a metered feature plugs into: one call before creating
     * the thing, and every limit, add-on grant and staff override is already
     * resolved behind it.
     */
    public function assertAllows(Workspace $workspace, string $featureKey, int $wouldBe): void;

    /**
     * Section 10: "Override a limit for one specific customer." A null value is
     * unlimited. Replaces any override already live on the same feature.
     */
    public function override(
        Workspace $workspace,
        Feature $feature,
        ?int $value,
        AdminUser $admin,
        string $reason,
        ?DateTimeInterface $expiresAt = null,
    ): WorkspaceEntitlementOverride;

    /** Puts the workspace back on whatever its plan and add-ons say. */
    public function revokeOverride(WorkspaceEntitlementOverride $override): void;
}
