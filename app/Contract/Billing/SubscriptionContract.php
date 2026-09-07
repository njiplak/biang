<?php

namespace App\Contract\Billing;

use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\AdminUser;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;

interface SubscriptionContract
{
    /** Section 5 Path B. Throws TrialAlreadyConsumed - the limit is per person, ever. */
    public function startTrial(Workspace $workspace, PlanPrice $price, User $startedBy): Subscription;

    /** Section 10: sales grants a plan by hand, with no payment behind it. */
    public function grantPlan(Workspace $workspace, PlanPrice $price, AdminUser $admin, string $reason): Subscription;

    /** Section 4: the trial auto-charges on day 15. */
    public function convertTrial(Subscription $subscription): Subscription;

    /** Throws DowngradeBlocked when the new plan cannot hold current usage. */
    public function changePlan(Workspace $workspace, PlanPrice $price): Subscription;

    /** Section 6: drops to the free tier. Deletes nothing. */
    public function cancel(Workspace $workspace): void;

    /**
     * Section 4: buy a quantity add-on, a paid unlock, or metered capacity.
     * Entitlements move immediately; money follows in phase 4.
     */
    public function purchaseAddon(Workspace $workspace, AddonPrice $price, int $quantity = 1): Subscription;

    /** Zero removes the item. Throws DowngradeBlocked if the capacity is in use. */
    public function changeAddonQuantity(Workspace $workspace, Addon $addon, int $quantity): Subscription;
}
