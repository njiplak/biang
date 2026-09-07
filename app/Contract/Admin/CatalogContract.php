<?php

namespace App\Contract\Admin;

use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\Plan;
use App\Models\PlanPrice;

/**
 * Section 10: "Change what we sell. Create and edit plans, prices, limits and
 * add-ons without an engineer. Retire a plan without breaking the customers
 * already on it."
 *
 * Two rules run through everything here:
 *
 * 1. Nothing is deleted. A plan or price a customer was sold on is archived, so
 *    their subscription keeps pointing at the exact row it was sold on and can
 *    never be silently repriced.
 * 2. A limit change is not just a row edit. workspace_entitlements is a
 *    materialised snapshot that section 7's hard block reads on every write, so
 *    editing a plan's limits without rebuilding the affected workspaces leaves
 *    them enforcing yesterday's numbers.
 */
interface CatalogContract
{
    public function overview(): array;

    public function createPlan(array $attributes): Plan;

    public function updatePlan(Plan $plan, array $attributes): Plan;

    /** Rebuilds every workspace resolving against this plan. */
    public function syncFeatures(Plan $plan, array $values): Plan;

    /** Archives any live price for the same interval and currency. */
    public function addPrice(Plan $plan, array $attributes): PlanPrice;

    public function archivePrice(PlanPrice $price): void;

    /** Section 10: retiring a plan must not touch the customers on it. */
    public function archivePlan(Plan $plan): Plan;

    public function restorePlan(Plan $plan): Plan;

    public function createAddon(array $attributes): Addon;

    public function updateAddon(Addon $addon, array $attributes): Addon;

    public function addAddonPrice(Addon $addon, array $attributes): AddonPrice;

    public function archiveAddon(Addon $addon): Addon;

    /** Which add-ons a plan offers. */
    public function syncPlanAddons(Plan $plan, array $addonIds): Plan;
}
