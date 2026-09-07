<?php

namespace App\Contract\Billing;

use App\Models\Workspace;

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
}
