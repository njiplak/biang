<?php

namespace App\Service\Billing;

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\UsageContract;
use App\Enums\AddonKind;
use App\Enums\EntitlementSource;
use App\Exceptions\Domain\LimitReached;
use App\Exceptions\Domain\NoFreePlanConfigured;
use App\Models\AdminUser;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Models\WorkspaceEntitlementOverride;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Resolves what a workspace is allowed to do, and materialises it.
 *
 * Section 7's hard block runs on every write request, so it must not pay for
 * four joins each time - it reads one indexed row from workspace_entitlements.
 * This service is the only thing that writes that table.
 *
 * Every method takes the workspace explicitly and queries without the tenancy
 * scope, so the admin console and queued jobs can resolve a workspace that is
 * not the ambient one.
 */
class EntitlementService implements EntitlementContract
{
    public function __construct(private readonly UsageContract $usage) {}

    public function rebuild(Workspace $workspace): void
    {
        DB::transaction(function () use ($workspace) {
            $resolved = $this->resolve($workspace);

            // Replace the snapshot wholesale: a feature removed from a plan has
            // to disappear, not linger as a stale allowance.
            WorkspaceEntitlement::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->delete();

            $now = now();

            foreach ($resolved as $featureKey => $entitlement) {
                WorkspaceEntitlement::withoutWorkspaceScope()->create([
                    'workspace_id' => $workspace->id,
                    'feature_key' => $featureKey,
                    'value' => $entitlement['value'],
                    'source' => $entitlement['source'],
                    'computed_at' => $now,
                ]);
            }
        });
    }

    public function limitFor(Workspace $workspace, string $featureKey): ?int
    {
        return $this->entitlement($workspace, $featureKey)?->value;
    }

    public function allows(Workspace $workspace, string $featureKey, int $usage): bool
    {
        $entitlement = $this->entitlement($workspace, $featureKey);

        // No entitlement is a denial, not a free pass. Treating an unknown key
        // as unlimited would turn a typo into unmetered capacity.
        if ($entitlement === null) {
            return false;
        }

        return $entitlement->allows($usage);
    }

    public function assertAllows(Workspace $workspace, string $featureKey, int $wouldBe): void
    {
        if ($this->allows($workspace, $featureKey, $wouldBe)) {
            return;
        }

        throw new LimitReached($workspace, $featureKey, $this->limitFor($workspace, $featureKey));
    }

    public function override(
        Workspace $workspace,
        Feature $feature,
        ?int $value,
        AdminUser $admin,
        string $reason,
        ?DateTimeInterface $expiresAt = null,
    ): WorkspaceEntitlementOverride {
        return DB::transaction(function () use ($workspace, $feature, $value, $admin, $reason, $expiresAt) {
            // A partial unique index enforces one live override per feature, and
            // it counts an EXPIRED row as live because it only tests
            // `revoked_at IS NULL`. So replacing an override has to revoke the
            // old row outright - leaning on the live() scope skipping an expired
            // one would hit the index instead.
            WorkspaceEntitlementOverride::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->id)
                ->where('feature_id', $feature->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $override = WorkspaceEntitlementOverride::withoutWorkspaceScope()->create([
                'workspace_id' => $workspace->id,
                'feature_id' => $feature->id,
                'value' => $value,
                'reason' => $reason,
                'granted_by_admin_id' => $admin->id,
                'expires_at' => $expiresAt,
            ]);

            $this->settle($workspace);

            return $override;
        });
    }

    public function revokeOverride(WorkspaceEntitlementOverride $override): void
    {
        DB::transaction(function () use ($override) {
            $override->update(['revoked_at' => now()]);

            $this->settle($override->workspace);
        });
    }

    /**
     * Re-derive the snapshot AND section 7's hard block together.
     *
     * Raising a limit for a blocked customer has to let them write again in the
     * same breath - that is the whole point of sales being able to do it - and
     * lowering one has to apply the block rather than wait for the next write.
     */
    private function settle(Workspace $workspace): void
    {
        $this->rebuild($workspace);
        $this->usage->evaluate($workspace);
    }

    /** @return array<string, array{value: int|null, source: EntitlementSource}> */
    private function resolve(Workspace $workspace): array
    {
        $subscription = $this->liveSubscription($workspace);
        $plan = $subscription?->plan ?? $this->freePlan();

        $resolved = [];

        foreach ($plan->features as $feature) {
            $resolved[$feature->key] = [
                'value' => $feature->pivot->value === null ? null : (int) $feature->pivot->value,
                'source' => EntitlementSource::Plan,
            ];
        }

        if ($subscription !== null) {
            $this->applyAddons($subscription, $resolved);
        }

        $this->applyOverrides($workspace, $resolved);

        return $resolved;
    }

    private function applyAddons(Subscription $subscription, array &$resolved): void
    {
        $items = $subscription->items()->with('addon.feature')->get();

        foreach ($items as $item) {
            $feature = $item->addon->feature;

            if ($feature === null) {
                continue;
            }

            $current = $resolved[$feature->key] ?? ['value' => 0, 'source' => EntitlementSource::Plan];

            // Unlimited stays unlimited: adding capacity to "no ceiling" is a
            // no-op, not a downgrade to a number.
            if ($current['value'] === null) {
                continue;
            }

            $resolved[$feature->key] = [
                'value' => match ($item->addon->kind) {
                    AddonKind::Quantity, AddonKind::Metered => $current['value'] + (int) $item->addon->grant_per_unit * $item->quantity,
                    AddonKind::Unlock => 1,
                },
                'source' => EntitlementSource::Addon,
            ];
        }
    }

    private function applyOverrides(Workspace $workspace, array &$resolved): void
    {
        $overrides = WorkspaceEntitlementOverride::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->live()
            ->with('feature')
            ->get();

        foreach ($overrides as $override) {
            // An override REPLACES the resolved value - staff granted a specific
            // number, not an adjustment on top of whatever the plan happens to say.
            $resolved[$override->feature->key] = [
                'value' => $override->value === null ? null : (int) $override->value,
                'source' => EntitlementSource::Override,
            ];
        }
    }

    private function liveSubscription(Workspace $workspace): ?Subscription
    {
        return Subscription::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->live()
            ->with('plan.features')
            ->first();
    }

    private function freePlan(): Plan
    {
        $plan = Plan::query()->where('is_free', true)->with('features')->first();

        if ($plan === null) {
            throw new NoFreePlanConfigured;
        }

        return $plan;
    }

    private function entitlement(Workspace $workspace, string $featureKey): ?WorkspaceEntitlement
    {
        return WorkspaceEntitlement::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->where('feature_key', $featureKey)
            ->first();
    }
}
