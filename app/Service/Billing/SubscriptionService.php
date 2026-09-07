<?php

namespace App\Service\Billing;

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Workspace\MembershipContract;
use App\Enums\AddonKind;
use App\Enums\BillingSource;
use App\Enums\BillingStatus;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Domain\AddonNotAvailable;
use App\Exceptions\Domain\DowngradeBlocked;
use App\Exceptions\Domain\NoActiveSubscription;
use App\Exceptions\Domain\TrialAlreadyConsumed;
use App\Exceptions\Domain\TrialNotExtendable;
use App\Exceptions\Domain\WorkspaceAlreadySubscribed;
use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\AdminUser;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Features;
use Illuminate\Support\Facades\DB;

/**
 * OUR subscription state, not a mirror of Dodo's.
 *
 * Section 14 phase 3 requires the complete product to be sellable by hand
 * before any payment integration exists, so nothing in this service talks to a
 * provider. Phase 4 adds a DodoBillingService that reconciles INTO this state -
 * it does not replace it. Section 8: our records are the source of truth for
 * access, theirs for money.
 */
class SubscriptionService implements SubscriptionContract
{
    /** Section 13.2 still lists confirming 14 days as open. */
    private const TRIAL_DAYS = 14;

    public function __construct(
        private readonly EntitlementContract $entitlements,
        private readonly MembershipContract $memberships,
    ) {}

    public function startTrial(Workspace $workspace, PlanPrice $price, User $startedBy): Subscription
    {
        return DB::transaction(function () use ($workspace, $price, $startedBy) {
            // Checked before anything is written, and inside the transaction, so
            // a refusal leaves no trace of a half-started trial.
            if ($startedBy->hasConsumedTrial()) {
                throw new TrialAlreadyConsumed($startedBy);
            }

            $this->assertNotSubscribed($workspace);

            $subscription = $this->open($workspace, $price, [
                'status' => SubscriptionStatus::Trialing,
                'trial_ends_at' => now()->addDays(self::TRIAL_DAYS),
                'billing_source' => BillingSource::Manual,
            ]);

            // Section 12: the trial is spent by the person who STARTS one. A
            // Path C invitee joining someone else's trialing workspace keeps
            // their own, because they never come through here.
            $startedBy->update([
                'trial_consumed_at' => now(),
                'trial_consumed_workspace_id' => $workspace->id,
            ]);

            $this->settle($workspace, BillingStatus::Trialing);

            return $subscription;
        });
    }

    public function grantPlan(Workspace $workspace, PlanPrice $price, AdminUser $admin, string $reason): Subscription
    {
        return DB::transaction(function () use ($workspace, $price, $admin, $reason) {
            $this->assertNotSubscribed($workspace);

            $subscription = $this->open($workspace, $price, [
                'status' => SubscriptionStatus::Active,
                // A comped account has no payment behind it, and billing_source
                // is what keeps that distinguishable from a broken sync.
                'billing_source' => BillingSource::Manual,
                'granted_by_admin_id' => $admin->id,
                'grant_reason' => $reason,
            ]);

            $this->settle($workspace, BillingStatus::Active);

            return $subscription;
        });
    }

    public function convertTrial(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $subscription->update([
                'status' => SubscriptionStatus::Active,
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);

            $this->settle($subscription->workspace, BillingStatus::Active);

            return $subscription->refresh();
        });
    }

    /**
     * Section 10: "Extend a trial. Sales cannot wait for a deploy."
     *
     * Entitlements do not move - the customer stays on the same plan, they just
     * get longer on it - so there is deliberately no settle() here. What changes
     * is `trial_ends_at`, which drives the countdown banner, the section 16
     * warning emails, and the hourly converter.
     */
    public function extendTrial(Workspace $workspace, int $days, AdminUser $admin, string $reason): Subscription
    {
        return DB::transaction(function () use ($workspace, $days, $admin, $reason) {
            $subscription = $this->liveSubscription($workspace);

            if ($subscription === null || $subscription->status !== SubscriptionStatus::Trialing) {
                throw new TrialNotExtendable($workspace);
            }

            // Extend from whichever is later. An in-flight trial GAINS days on
            // top of what is left; one that has already lapsed - the hourly
            // converter runs on a schedule, so there is a window - restarts from
            // now rather than being extended into a date that is still past.
            $from = $subscription->trial_ends_at?->isFuture()
                ? $subscription->trial_ends_at
                : now();

            $subscription->update([
                'trial_ends_at' => $from->addDays($days),
                // The same two columns the comp path uses. A trial extension is
                // a staff grant with a reason, and this records the most recent
                // one rather than accumulating a history - audit_logs is where
                // the full trail lands in phase 6.
                'granted_by_admin_id' => $admin->id,
                'grant_reason' => $reason,
            ]);

            return $subscription->refresh();
        });
    }

    public function changePlan(Workspace $workspace, PlanPrice $price): Subscription
    {
        return DB::transaction(function () use ($workspace, $price) {
            $subscription = $this->liveSubscription($workspace);

            if ($subscription === null) {
                throw new WorkspaceAlreadySubscribed($workspace);
            }

            $this->assertFits($workspace, $price);

            $subscription->update([
                'plan_id' => $price->plan_id,
                'plan_price_id' => $price->id,
            ]);

            $this->settle($workspace, $workspace->billing_status);

            return $subscription->refresh();
        });
    }

    public function cancel(Workspace $workspace): void
    {
        DB::transaction(function () use ($workspace) {
            $subscription = $this->liveSubscription($workspace);

            if ($subscription !== null) {
                $subscription->update([
                    'status' => SubscriptionStatus::Canceled,
                    'canceled_at' => now(),
                    'ended_at' => now(),
                ]);
            }

            // Section 6: the workspace drops to the free tier and the data
            // stays. If that leaves them over the free limit, section 7's hard
            // block applies - we do not delete anyone's data to make it fit.
            $this->settle($workspace, BillingStatus::Free);
        });
    }

    /**
     * Section 4: three kinds of add-on, charged differently, but all resolving
     * through the SAME entitlement path as the plan's own allowance.
     *
     * Entitlements move the moment this returns. No money moves: Dodo is
     * merchant of record and owns proration (section 8), so phase 4 reconciles
     * the charge against the item recorded here. That keeps section 14's
     * "sellable manually" promise intact.
     */
    public function purchaseAddon(Workspace $workspace, AddonPrice $price, int $quantity = 1): Subscription
    {
        return DB::transaction(function () use ($workspace, $price, $quantity) {
            $subscription = $this->requireSubscription($workspace);
            $addon = $price->addon;

            $this->assertPurchasable($subscription, $addon, $price);

            $item = SubscriptionItem::withoutWorkspaceScope()
                ->where('subscription_id', $subscription->id)
                ->where('addon_id', $addon->id)
                ->first();

            // An unlock is on or off; buying it twice is still just "on".
            $resolved = $addon->kind === AddonKind::Unlock
                ? 1
                : max(1, ($item?->quantity ?? 0) + $quantity);

            if ($item === null) {
                SubscriptionItem::withoutWorkspaceScope()->create([
                    'workspace_id' => $workspace->id,
                    'subscription_id' => $subscription->id,
                    'addon_id' => $addon->id,
                    'addon_price_id' => $price->id,
                    'quantity' => $resolved,
                ]);
            } else {
                $item->update(['quantity' => $resolved, 'addon_price_id' => $price->id]);
            }

            // Section 7: buying the seat lifts the hard block in the same breath.
            $this->settle($workspace, $workspace->billing_status);

            return $subscription->refresh();
        });
    }

    public function changeAddonQuantity(Workspace $workspace, Addon $addon, int $quantity): Subscription
    {
        return DB::transaction(function () use ($workspace, $addon, $quantity) {
            $subscription = $this->requireSubscription($workspace);

            $item = SubscriptionItem::withoutWorkspaceScope()
                ->where('subscription_id', $subscription->id)
                ->where('addon_id', $addon->id)
                ->first();

            if ($item === null) {
                throw new AddonNotAvailable($addon, 'not currently on this subscription');
            }

            // Reducing capacity is a downgrade, and the same rule applies: we do
            // not take away what is in use, and we say how much has to go first.
            $this->assertReductionFits($workspace, $addon, $item->quantity, $quantity);

            $quantity <= 0
                ? $item->delete()
                : $item->update(['quantity' => $quantity]);

            $this->settle($workspace, $workspace->billing_status);

            return $subscription->refresh();
        });
    }

    private function assertPurchasable(Subscription $subscription, Addon $addon, AddonPrice $price): void
    {
        if ($price->archived_at !== null || $addon->archived_at !== null) {
            throw new AddonNotAvailable($addon, 'retired');
        }

        /*
         * Section 13.1 leaves the value metric open, and the catalogue carries
         * features for candidate metrics that nothing meters yet. Selling
         * capacity in one of those takes real money for a ceiling that can
         * never be reached, and no refund path notices.
         *
         * A rule rather than a data fix, because the console can create an
         * add-on against any feature at any time.
         */
        if ($addon->feature !== null && ! Features::isMeasured($addon->feature->key)) {
            throw new AddonNotAvailable($addon, 'not measured yet, so there is nothing to sell');
        }

        if (! $subscription->plan->addons()->whereKey($addon->id)->exists()) {
            throw new AddonNotAvailable($addon, 'not sold on the current plan');
        }
    }

    /**
     * Works out what the entitlement would become and refuses if current usage
     * would no longer fit.
     */
    private function assertReductionFits(Workspace $workspace, Addon $addon, int $from, int $to): void
    {
        if ($to >= $from || $addon->feature === null || $addon->kind !== AddonKind::Quantity) {
            return;
        }

        $featureKey = $addon->feature->key;
        $current = $this->entitlements->limitFor($workspace, $featureKey);

        if ($current === null) {
            return;
        }

        $after = $current - (($from - $to) * (int) $addon->grant_per_unit);
        $used = $featureKey === Features::SEATS
            ? $workspace->seatsUsed()
            : $current;

        if ($used > $after) {
            throw new DowngradeBlocked($featureKey, $used, $after);
        }
    }

    private function requireSubscription(Workspace $workspace): Subscription
    {
        return $this->liveSubscription($workspace) ?? throw new NoActiveSubscription($workspace);
    }

    /**
     * Section 7: the downgrade is blocked until they remove enough people, and
     * we say exactly how many. Checked before the plan changes so a refusal
     * leaves the old plan and its entitlements untouched.
     */
    private function assertFits(Workspace $workspace, PlanPrice $price): void
    {
        $limit = $price->plan()->with('features')->first()?->limitFor(Features::SEATS);

        if ($limit === null) {
            return;
        }

        $used = $workspace->seatsUsed();

        if ($used > $limit) {
            throw new DowngradeBlocked(Features::SEATS, $used, $limit);
        }
    }

    private function assertNotSubscribed(Workspace $workspace): void
    {
        if ($this->liveSubscription($workspace) !== null) {
            throw new WorkspaceAlreadySubscribed($workspace);
        }
    }

    private function open(Workspace $workspace, PlanPrice $price, array $attributes): Subscription
    {
        return Subscription::withoutWorkspaceScope()->create(array_merge([
            'workspace_id' => $workspace->id,
            'plan_id' => $price->plan_id,
            'plan_price_id' => $price->id,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ], $attributes));
    }

    /**
     * Re-derive everything that depends on the subscription: the billing axis,
     * the entitlement snapshot, and whether the hard block now applies.
     */
    private function settle(Workspace $workspace, BillingStatus $status): void
    {
        $workspace->update(['billing_status' => $status]);

        $this->entitlements->rebuild($workspace);
        $this->memberships->syncSeats($workspace);
    }

    private function liveSubscription(Workspace $workspace): ?Subscription
    {
        return Subscription::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->live()
            ->first();
    }
}
