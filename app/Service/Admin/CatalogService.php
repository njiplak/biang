<?php

namespace App\Service\Admin;

use App\Contract\Admin\CatalogContract;
use App\Contract\Billing\EntitlementContract;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Domain\CannotArchivePlan;
use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Support\Features;
use Illuminate\Support\Facades\DB;

class CatalogService implements CatalogContract
{
    public function __construct(private readonly EntitlementContract $entitlements) {}

    public function overview(): array
    {
        $plans = Plan::query()
            ->with(['prices', 'features', 'addons'])
            ->withCount(['subscriptions as live_subscriptions_count' => fn ($query) => $query
                ->whereIn('status', array_column(SubscriptionStatus::live(), 'value'))])
            ->orderBy('sort_order')
            ->get();

        return [
            'plans' => $plans->map(fn (Plan $plan) => $this->planPayload($plan))->all(),
            'addons' => Addon::query()->with(['prices', 'feature'])->orderBy('name')->get()
                ->map(fn (Addon $addon) => $this->addonPayload($addon))->all(),
            'features' => Feature::query()->orderBy('sort_order')->get()
                ->map(fn (Feature $feature) => [
                    'id' => $feature->id,
                    'key' => $feature->key,
                    'name' => $feature->name,
                    'type' => $feature->type->value,
                    'unit' => $feature->unit,
                    // Section 13.1: a limit on a key nothing meters can never
                    // be breached, so the plan reads as enforced when it is
                    // decorative. Say so rather than letting it look real.
                    'is_measured' => Features::isMeasured($feature->key),
                ])->all(),
        ];
    }

    /**
     * A new plan has no limits, and allows() treats a missing entitlement as a
     * denial - so it starts by refusing everything until its limits are set.
     * That is the safe direction to fail in.
     */
    public function createPlan(array $attributes): Plan
    {
        return Plan::create($attributes);
    }

    public function updatePlan(Plan $plan, array $attributes): Plan
    {
        return DB::transaction(function () use ($plan, $attributes) {
            // `code` is what seeders, tests and any future integration address a
            // plan by. Renaming the display name is safe; re-coding it is not.
            //
            // `is_free` is stripped for a different reason: the partial unique
            // index stops TWO floor plans, but nothing stops the floor one being
            // flipped to false, which would leave cancellation with nowhere to
            // land and fire NoFloorPlanConfigured across the whole app.
            unset($attributes['code'], $attributes['is_free']);

            $plan->update($attributes);

            return $plan->refresh();
        });
    }

    /**
     * @param  array<int, int|null>  $values  feature_id => limit, null meaning unlimited
     */
    public function syncFeatures(Plan $plan, array $values): Plan
    {
        return DB::transaction(function () use ($plan, $values) {
            $plan->features()->sync(
                collect($values)->mapWithKeys(fn ($value, $featureId) => [
                    (int) $featureId => ['value' => $value],
                ])->all()
            );

            $this->rebuildAffected($plan->refresh());

            return $plan;
        });
    }

    public function addPrice(Plan $plan, array $attributes): PlanPrice
    {
        return DB::transaction(function () use ($plan, $attributes) {
            // Prices are immutable once sold. Editing one is an INSERT plus an
            // archive of the old row, which is also what the partial unique
            // index on (plan_id, billing_interval, currency) demands.
            PlanPrice::query()
                ->where('plan_id', $plan->id)
                ->where('billing_interval', $attributes['billing_interval'])
                ->where('currency', $attributes['currency'])
                ->whereNull('archived_at')
                ->update(['archived_at' => now()]);

            return PlanPrice::create([...$attributes, 'plan_id' => $plan->id]);
        });
    }

    public function archivePrice(PlanPrice $price): void
    {
        // Not deleted: a subscription points at the exact row it was sold on,
        // and restrictOnDelete on that foreign key would refuse anyway.
        $price->update(['archived_at' => now()]);
    }

    public function archivePlan(Plan $plan): Plan
    {
        return DB::transaction(function () use ($plan) {
            // Cancelling drops a workspace onto the floor plan, so archiving
            // it would leave that path with nowhere to land.
            if ($plan->is_free) {
                throw new CannotArchivePlan($plan, 'it is the floor plan every cancellation falls back to');
            }

            // Idempotent: re-retiring would otherwise move the date on which we
            // stopped selling it, which is the one thing that record is for.
            if ($plan->archived_at === null) {
                $plan->update(['archived_at' => now(), 'is_public' => false]);
            }

            // Deliberately NOT touching subscriptions or entitlements. Section
            // 10: "Retire a plan without breaking the customers already on it."
            // They keep the plan, the price and the limits they were sold.
            $plan->prices()->whereNull('archived_at')->update(['archived_at' => now()]);

            return $plan->refresh();
        });
    }

    public function restorePlan(Plan $plan): Plan
    {
        // Prices stay archived: un-retiring a plan should not silently put a
        // price back on sale that may be years out of date.
        $plan->update(['archived_at' => null]);

        return $plan->refresh();
    }

    public function createAddon(array $attributes): Addon
    {
        return Addon::create($attributes);
    }

    public function updateAddon(Addon $addon, array $attributes): Addon
    {
        return DB::transaction(function () use ($addon, $attributes) {
            unset($attributes['key']);

            $addon->update($attributes);

            // grant_per_unit feeds entitlement resolution directly, so changing
            // it moves every workspace that already owns this add-on.
            $this->rebuildOwners($addon);

            return $addon->refresh();
        });
    }

    public function addAddonPrice(Addon $addon, array $attributes): AddonPrice
    {
        return DB::transaction(function () use ($addon, $attributes) {
            AddonPrice::query()
                ->where('addon_id', $addon->id)
                ->where('billing_interval', $attributes['billing_interval'])
                ->where('currency', $attributes['currency'])
                ->whereNull('archived_at')
                ->update(['archived_at' => now()]);

            return AddonPrice::create([...$attributes, 'addon_id' => $addon->id]);
        });
    }

    public function archiveAddon(Addon $addon): Addon
    {
        return DB::transaction(function () use ($addon) {
            $addon->update(['archived_at' => now()]);
            $addon->prices()->whereNull('archived_at')->update(['archived_at' => now()]);

            // Same promise as a retired plan: nobody who already bought it
            // loses what they bought.
            return $addon->refresh();
        });
    }

    public function syncPlanAddons(Plan $plan, array $addonIds): Plan
    {
        return DB::transaction(function () use ($plan, $addonIds) {
            $plan->addons()->sync($addonIds);

            return $plan->refresh();
        });
    }

    /**
     * Section 7's hard block reads a materialised snapshot, so a limit change
     * that does not rebuild leaves workspaces enforcing the old numbers until
     * something else happens to touch them.
     *
     * The floor plan is the awkward case: workspaces on it have NO subscription
     * at all - EntitlementService falls back to it - so they cannot be found by
     * joining subscriptions, and have to be swept separately.
     */
    private function rebuildAffected(Plan $plan): void
    {
        $plan->is_free
            ? $this->rebuildFreeWorkspaces()
            : $this->rebuildSubscribers($plan);
    }

    private function rebuildSubscribers(Plan $plan): void
    {
        Subscription::withoutWorkspaceScope()
            ->where('plan_id', $plan->id)
            ->live()
            ->with('workspace')
            ->chunkById(100, function ($subscriptions) {
                foreach ($subscriptions as $subscription) {
                    if ($subscription->workspace !== null) {
                        $this->entitlements->rebuild($subscription->workspace);
                    }
                }
            });
    }

    private function rebuildFreeWorkspaces(): void
    {
        $subscribed = Subscription::withoutWorkspaceScope()->live()->pluck('workspace_id');

        Workspace::query()
            ->whereNotIn('id', $subscribed)
            ->chunkById(100, function ($workspaces) {
                foreach ($workspaces as $workspace) {
                    $this->entitlements->rebuild($workspace);
                }
            });
    }

    private function rebuildOwners(Addon $addon): void
    {
        Subscription::withoutWorkspaceScope()
            ->live()
            ->whereHas('items', fn ($query) => $query->where('addon_id', $addon->id))
            ->with('workspace')
            ->chunkById(100, function ($subscriptions) {
                foreach ($subscriptions as $subscription) {
                    if ($subscription->workspace !== null) {
                        $this->entitlements->rebuild($subscription->workspace);
                    }
                }
            });
    }

    private function planPayload(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'is_public' => $plan->is_public,
            'is_free' => $plan->is_free,
            'sort_order' => $plan->sort_order,
            'is_archived' => $plan->archived_at !== null,
            // Section 10: this number is why a plan is retired and not deleted.
            'live_subscriptions' => $plan->live_subscriptions_count,
            'features' => $plan->features->map(fn (Feature $feature) => [
                'id' => $feature->id,
                'key' => $feature->key,
                'name' => $feature->name,
                'value' => $feature->pivot->value === null ? null : (int) $feature->pivot->value,
                'is_measured' => Features::isMeasured($feature->key),
            ])->values()->all(),
            /*
             * Section 10: staff change what we sell without an engineer, and
             * `is_published` is what makes that honest. A price with no product
             * behind it at Dodo cannot be bought, and a catalogue screen that
             * showed it the same as any other would be showing a price that is
             * on the pricing page and refuses at checkout.
             */
            'prices' => $plan->prices->map(fn (PlanPrice $price) => [
                'id' => $price->id,
                'interval' => $price->billing_interval->value,
                'currency' => $price->currency,
                'amount_minor' => $price->amount_minor,
                'is_archived' => $price->archived_at !== null,
                'is_published' => filled($price->dodo_product_id),
            ])->values()->all(),
            'addon_ids' => $plan->addons->pluck('id')->all(),
        ];
    }

    private function addonPayload(Addon $addon): array
    {
        return [
            'id' => $addon->id,
            'key' => $addon->key,
            'name' => $addon->name,
            'description' => $addon->description,
            'kind' => $addon->kind->value,
            'feature_id' => $addon->feature_id,
            'feature_key' => $addon->feature?->key,
            'grant_per_unit' => $addon->grant_per_unit,
            'max_quantity' => $addon->max_quantity,
            'is_archived' => $addon->archived_at !== null,
            // An add-on is published as an ADD-ON at Dodo, not a product, so
            // its id lives in a different column - see the migration.
            'prices' => $addon->prices->map(fn (AddonPrice $price) => [
                'id' => $price->id,
                'interval' => $price->billing_interval->value,
                'currency' => $price->currency,
                'amount_minor' => $price->amount_minor,
                'is_archived' => $price->archived_at !== null,
                'is_published' => filled($price->dodo_addon_id),
            ])->values()->all(),
        ];
    }
}
