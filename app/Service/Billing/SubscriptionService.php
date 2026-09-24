<?php

namespace App\Service\Billing;

use App\Contract\Billing\BillingNotifierContract;
use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\PaymentGatewayContract;
use App\Contract\Billing\SubscriptionContract;
use App\Contract\Billing\UsageContract;
use App\Contract\Workspace\MembershipContract;
use App\Enums\AddonKind;
use App\Enums\BillingInterval;
use App\Enums\BillingSource;
use App\Enums\BillingStatus;
use App\Enums\CancellationFeedback;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Domain\AddonNotAvailable;
use App\Exceptions\Domain\DowngradeBlocked;
use App\Exceptions\Domain\NoActiveSubscription;
use App\Exceptions\Domain\PlanChangeScheduled;
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
use App\Notifications\Billing\AddonChangedNotification;
use App\Notifications\Billing\PlanChangedNotification;
use App\Notifications\Billing\SubscriptionCanceledNotification;
use App\Support\Features;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    /**
     * Section 4, and section 13 now settles it at 14. Public because the
     * checkout that COLLECTS the card has to ask for the same number of free
     * days this service would have granted - two constants would drift into a
     * trial that ends on a different day than the one we emailed about.
     */
    public const TRIAL_DAYS = 14;

    public function __construct(
        private readonly EntitlementContract $entitlements,
        private readonly MembershipContract $memberships,
        private readonly PaymentGatewayContract $gateway,
        private readonly BillingNotifierContract $notifier,
        private readonly UsageContract $usage,
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

    /**
     * Section 7 is why this exists at all: the provider's own plan switcher is
     * turned OFF, because a downgrade made outside our app could not be blocked.
     * So every plan change comes through here, and the seat check runs first.
     *
     * Order matters, and it is deliberate:
     *
     *   assertPlanFits  →  tell Dodo (money)  →  move our rows (access)
     *
     * The seat check is ours and must refuse before anybody is charged.
     * Proration is Dodo's (section 8 makes them merchant of record), and if
     * they refuse we must not move access the customer has not paid for - so
     * PlanChangeUnavailable propagates and our rows are untouched.
     */
    public function changePlan(Workspace $workspace, PlanPrice $price): Subscription
    {
        $subscription = $this->liveSubscription($workspace);

        if ($subscription === null) {
            throw new NoActiveSubscription($workspace);
        }

        // Picking the plan they are already on, with a downgrade pending, is
        // "never mind the downgrade".
        if ((int) $price->id === (int) $subscription->plan_price_id) {
            $this->keepCurrentPlan($workspace);

            return $subscription->refresh();
        }

        // Section 7: "The downgrade is blocked until they remove three people."
        // Before the money, so a refusal costs nothing to unwind.
        $this->assertPlanFits($workspace, $price);

        $scheduleFor = $this->downgradeDate($subscription, $price);
        $replacing = $subscription->scheduled_plan_price_id !== null;

        /*
         * Deliberately OUTSIDE the transaction below. A call to somebody else's
         * system cannot be rolled back, and holding a database transaction open
         * across a network round trip is how a slow provider becomes a locked
         * table.
         */
        if ($subscription->isHeldWithProvider()) {
            $this->gateway->changeSubscriptionPlan(
                $subscription,
                $price,
                $this->providerAddons($subscription),
                atNextBillingDate: $scheduleFor !== null,
                replaceScheduled: $replacing,
            );
        }

        $fromPlan = $subscription->plan->name;

        /*
         * A downgrade waits for the renewal: they keep the plan they paid for
         * until then, and the reconciler moves them when Dodo applies it.
         */
        if ($scheduleFor !== null) {
            $subscription->update([
                'scheduled_plan_price_id' => $price->id,
                'scheduled_change_at' => $scheduleFor,
            ]);

            $this->notify($workspace, 'plan_changed', $subscription, PlanChangedNotification::for(
                $workspace, $fromPlan, $price, true, $scheduleFor,
            ));

            return $subscription->refresh();
        }

        $changed = DB::transaction(function () use ($workspace, $subscription, $price) {
            $subscription->update([
                'plan_id' => $price->plan_id,
                'plan_price_id' => $price->id,
                // An immediate change replaces anything that was scheduled.
                'scheduled_plan_price_id' => null,
                'scheduled_change_at' => null,
            ]);

            /*
             * Applied now rather than waiting for the confirming webhook. An
             * upgrade the customer has just been charged for has to work
             * immediately; the reconciler corrects anything Dodo later reports
             * differently, and section 8 keeps THEM authoritative for the money
             * either way.
             */
            $this->settle($workspace, $workspace->billing_status);

            return $subscription->refresh();
        });

        $this->notify($workspace, 'plan_changed', $changed, PlanChangedNotification::for(
            $workspace, $fromPlan, $price, $changed->isHeldWithProvider(),
        ));

        return $changed;
    }

    /**
     * Drop a scheduled downgrade and stay on the current plan. Nothing is
     * charged: the current plan is what they are already paying for.
     */
    public function keepCurrentPlan(Workspace $workspace): void
    {
        $subscription = $this->requireSubscription($workspace);

        if ($subscription->scheduled_plan_price_id === null) {
            return;
        }

        if ($subscription->isHeldWithProvider()) {
            $this->gateway->cancelScheduledPlanChange($subscription);
        }

        $subscription->update([
            'scheduled_plan_price_id' => null,
            'scheduled_change_at' => null,
        ]);
    }

    /**
     * When a move to $price would take effect if it waited for the renewal, or
     * null when it applies now.
     *
     * Only a downgrade waits - a lower tier, or the same tier from annual to
     * monthly - and only on a paid period Dodo is renewing. An upgrade applies
     * at once (they are paying for more), and a trial or a plan granted by
     * hand has no paid time to protect.
     */
    public function downgradeDate(Subscription $subscription, PlanPrice $price): ?CarbonInterface
    {
        if (! $subscription->isHeldWithProvider()
            || $subscription->status !== SubscriptionStatus::Active
            || ! $subscription->current_period_end?->isFuture()) {
            return null;
        }

        $from = $subscription->plan;
        $to = $price->plan;

        $isDowngrade = $from->sort_order !== $to->sort_order
            ? $to->sort_order < $from->sort_order
            : $subscription->planPrice->billing_interval === BillingInterval::Year
                && $price->billing_interval === BillingInterval::Month;

        return $isDowngrade ? $subscription->current_period_end : null;
    }

    /**
     * The add-ons Dodo has to keep when the plan moves under them.
     *
     * changePlan replaces the entire subscription line-up, so an add-on that is
     * not restated is silently dropped - and with it the entitlement it grants,
     * which is how a customer who upgraded would lose the extra seats they are
     * still paying for.
     *
     * @return list<array{addon_id: string, quantity: int}>
     */
    private function providerAddons(Subscription $subscription): array
    {
        /*
         * The provider id lives on the PRICE, not the add-on: Dodo's add-on
         * carries its own amount, so one of their add-ons is one priced row
         * here. An unpublished one is skipped rather than sent as null - it
         * cannot be attached, and losing it from the line-up is better than a
         * rejected call that loses the whole plan change.
         */
        return $subscription->items()
            ->with('addonPrice')
            ->get()
            ->filter(fn (SubscriptionItem $item) => filled($item->addonPrice?->dodo_addon_id))
            ->map(fn (SubscriptionItem $item) => [
                'addon_id' => $item->addonPrice->dodo_addon_id,
                'quantity' => $item->quantity,
            ])
            ->values()
            ->all();
    }

    /**
     * Section 8's table has cancelling working from both sides. This is ours,
     * and it has to reach Dodo before it touches anything of ours.
     *
     *   tell Dodo to stop charging  →  then drop access to free
     *
     * Get that order wrong and a cancellation takes the paid product away while
     * the card keeps being charged every month. The customer finds out from a
     * bank statement rather than from us, and because Dodo is merchant of
     * record their recourse is a formal chargeback, not a support ticket.
     *
     * If Dodo refuses, CancellationFailed propagates and NOTHING changes - the
     * customer keeps the plan they are paying for and can try again. That is
     * the recoverable failure; the other order is not.
     */
    public function cancel(
        Workspace $workspace,
        ?CancellationFeedback $feedback = null,
        ?string $comment = null,
    ): void {
        $subscription = $this->liveSubscription($workspace);

        /*
         * Outside the transaction, like every other call to their system.
         * Immediate rather than at the period end, because the lines below take
         * the paid features away immediately - scheduling the money to stop
         * later would mean charging them again for a plan they no longer have.
         */
        if ($subscription?->isHeldWithProvider()) {
            $this->gateway->cancelSubscription($subscription, false, $feedback, $comment);
        }

        DB::transaction(function () use ($workspace, $subscription, $feedback, $comment) {
            if ($subscription !== null) {
                $subscription->update([
                    'status' => SubscriptionStatus::Canceled,
                    'canceled_at' => now(),
                    // Kept on OUR side as well as theirs. Section 15's churn
                    // split is our metric, and asking Dodo for it every time
                    // would put a rate-limited API call behind a dashboard.
                    'cancellation_feedback' => $feedback,
                    'cancellation_comment' => $comment,
                    'ended_at' => now(),
                ]);
            }

            // The workspace goes read-only and the data stays. Nobody is
            // removed to make it fit a smaller plan - there is no smaller plan
            // to fit, only the floor, and writing is what stops.
            $this->settle($workspace, BillingStatus::Unpaid);
        });
    }

    /**
     * The customer's own cancel button. Scheduled for the end of the period
     * they have already paid for, so an annual customer who leaves in month two
     * keeps the ten months they paid for instead of losing them with no refund.
     *
     * Falls back to cancelling now when there is no paid period to honour: a
     * plan granted by hand (nothing is charged), or a subscription already past
     * due (the period was never paid for).
     */
    public function cancelAtPeriodEnd(
        Workspace $workspace,
        ?CancellationFeedback $feedback = null,
        ?string $comment = null,
    ): void {
        $subscription = $this->liveSubscription($workspace);
        $endsAt = $subscription === null ? null : $this->paidThrough($subscription);

        if ($subscription === null || $endsAt === null) {
            $this->cancel($workspace, $feedback, $comment);

            if ($subscription !== null) {
                $this->notify($workspace, 'subscription_canceled', $subscription, new SubscriptionCanceledNotification(
                    $workspace, $subscription->plan->name, null,
                ));
            }

            return;
        }

        // Pressing cancel twice is not a second cancellation, and not a second email.
        if ($subscription->cancel_at_period_end) {
            return;
        }

        // Dodo first, for the reason cancel() gives: if they refuse, nothing here moves.
        $this->gateway->cancelSubscription($subscription, true, $feedback, $comment);

        /*
         * canceled_at stays null until the subscription actually ends: the
         * churn figures count it, and a customer who is still paying (and may
         * yet resume) has not churned. Their answer is kept now, though -
         * asking again at the period end would mean asking someone who left.
         */
        $subscription->update([
            'cancel_at_period_end' => true,
            'cancellation_feedback' => $feedback,
            'cancellation_comment' => $comment,
        ]);

        $this->notify($workspace, 'subscription_canceled', $subscription, new SubscriptionCanceledNotification(
            $workspace, $subscription->plan->name, $endsAt,
        ));
    }

    /**
     * Undo cancelAtPeriodEnd before the period runs out. After it has run out
     * the subscription is gone at Dodo too, and coming back is a new checkout.
     */
    public function resume(Workspace $workspace): void
    {
        $subscription = $this->requireSubscription($workspace);

        // Nothing scheduled: it is already renewing, which is what was asked for.
        if (! $subscription->cancel_at_period_end) {
            return;
        }

        $this->gateway->resumeSubscription($subscription);

        $subscription->update([
            'cancel_at_period_end' => false,
            'cancellation_feedback' => null,
            'cancellation_comment' => null,
        ]);
    }

    /**
     * The date a scheduled cancellation takes effect, or null when there is no
     * paid period left to run out. A trial runs to its end: cancelling on day
     * three still leaves the rest of the fourteen days, uncharged.
     */
    public function paidThrough(Subscription $subscription): ?CarbonInterface
    {
        if (! $subscription->isHeldWithProvider()) {
            return null;
        }

        $end = match ($subscription->status) {
            SubscriptionStatus::Trialing => $subscription->trial_ends_at ?? $subscription->current_period_end,
            SubscriptionStatus::Active => $subscription->current_period_end,
            default => null,
        };

        return $end?->isFuture() ? $end : null;
    }

    /**
     * A confirmation for something the customer just did. Keyed uniquely per
     * action because each one is its own event; sendOnce is used for its
     * recipient rules and its log, not to collapse repeats.
     */
    private function notify(Workspace $workspace, string $type, Subscription $subscription, Notification $notification): void
    {
        $this->notifier->sendOnce(
            $workspace,
            $type,
            "{$type}:sub_{$subscription->id}:".Str::ulid(),
            fn () => $notification,
        );
    }

    /**
     * Section 4: three kinds of add-on, charged differently, but all resolving
     * through the SAME entitlement path as the plan's own allowance.
     *
     * The money now moves with the entitlement. Dodo owns the proration
     * (section 8), so the charge is theirs to make - but it has to happen
     * BEFORE we grant the capacity, or section 7's seat sale hands out a seat
     * on a card that was going to decline.
     *
     * A subscription granted by hand has nothing to charge against and is
     * deliberately untouched: section 14 phase 3 keeps the product sellable
     * with no provider at all.
     */
    public function purchaseAddon(Workspace $workspace, AddonPrice $price, int $quantity = 1): Subscription
    {
        $subscription = $this->requireSubscription($workspace);
        $addon = $price->addon;

        $this->assertNoScheduledChange($subscription);
        $this->assertPurchasable($subscription, $addon, $price);

        $existing = SubscriptionItem::withoutWorkspaceScope()
            ->where('subscription_id', $subscription->id)
            ->where('addon_id', $addon->id)
            ->first();

        // An unlock is on or off; buying it twice is still just "on".
        $resolved = $addon->kind === AddonKind::Unlock
            ? 1
            : max(1, ($existing?->quantity ?? 0) + $quantity);

        // Outside the transaction: a network round trip cannot be rolled back,
        // and holding a write lock across one is how a slow provider becomes a
        // locked table.
        $this->chargeAddons($subscription, $price, $resolved);

        $updated = DB::transaction(function () use ($workspace, $subscription, $addon, $price, $resolved) {
            $item = SubscriptionItem::withoutWorkspaceScope()
                ->where('subscription_id', $subscription->id)
                ->where('addon_id', $addon->id)
                ->first();

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

        $this->notify($workspace, 'addon_changed', $updated, new AddonChangedNotification(
            $workspace, $addon->name, $resolved, $updated->isHeldWithProvider(),
        ));

        return $updated;
    }

    public function changeAddonQuantity(Workspace $workspace, Addon $addon, int $quantity): Subscription
    {
        $subscription = $this->requireSubscription($workspace);

        $item = SubscriptionItem::withoutWorkspaceScope()
            ->where('subscription_id', $subscription->id)
            ->where('addon_id', $addon->id)
            ->with('addonPrice')
            ->first();

        if ($item === null) {
            throw new AddonNotAvailable($addon, 'not currently on this subscription');
        }

        $this->assertNoScheduledChange($subscription);

        // Reducing capacity is a downgrade, and the same rule applies: we do
        // not take away what is in use, and we say how much has to go first.
        $this->assertReductionFits($workspace, $addon, $item->quantity, $quantity);

        // Both directions go through Dodo: buying more is a charge, and giving
        // some back is a credit they owe. Skipping the reduction would keep
        // billing for capacity we have already taken away.
        if ($item->addonPrice !== null) {
            $this->chargeAddons($subscription, $item->addonPrice, max(0, $quantity));
        }

        $updated = DB::transaction(function () use ($workspace, $subscription, $item, $quantity) {
            $quantity <= 0
                ? $item->delete()
                : $item->update(['quantity' => $quantity]);

            $this->settle($workspace, $workspace->billing_status);

            return $subscription->refresh();
        });

        $this->notify($workspace, 'addon_changed', $updated, new AddonChangedNotification(
            $workspace, $addon->name, max(0, $quantity), $updated->isHeldWithProvider(),
        ));

        return $updated;
    }

    /**
     * Restate the whole add-on line-up at Dodo with $price set to $quantity.
     *
     * Section 4 charges a quantity add-on "per unit, prorated when the quantity
     * changes", and Dodo does that arithmetic against the line-up it is given.
     * The line-up is sent WHOLE rather than as a delta because their plan-change
     * call replaces it - anything omitted is dropped, along with the entitlement
     * it grants.
     *
     * Does nothing for a subscription granted by hand: there is no payment
     * account behind it, and section 14 phase 3 depends on that staying true.
     */
    private function chargeAddons(Subscription $subscription, AddonPrice $price, int $quantity): void
    {
        if (! $subscription->isHeldWithProvider()) {
            return;
        }

        $addons = collect($this->providerAddons($subscription))
            ->keyBy('addon_id')
            ->when(
                filled($price->dodo_addon_id),
                fn ($items) => $quantity <= 0
                    ? $items->forget($price->dodo_addon_id)
                    : $items->put($price->dodo_addon_id, [
                        'addon_id' => $price->dodo_addon_id,
                        'quantity' => $quantity,
                    ]),
            )
            ->values()
            ->all();

        $this->gateway->changeSubscriptionPlan($subscription, $subscription->planPrice, $addons);
    }

    private function assertNoScheduledChange(Subscription $subscription): void
    {
        // A plan granted by hand never reaches Dodo, so there is nothing to refuse it.
        if ($subscription->scheduled_plan_price_id === null || ! $subscription->isHeldWithProvider()) {
            return;
        }

        throw new PlanChangeScheduled(
            (string) $subscription->scheduledPlanPrice?->plan?->name,
            $subscription->scheduled_change_at,
        );
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
     * Section 7: the move is blocked until they remove enough people, and we
     * say exactly how many. Checked before anything moves - and, for a
     * purchase, before anyone is charged.
     *
     * Null is unlimited and always fits.
     */
    public function seatOverageFor(Workspace $workspace, PlanPrice $price): int
    {
        $limit = $price->plan()->with('features')->first()?->limitFor(Features::SEATS);

        if ($limit === null) {
            return 0;
        }

        return max(0, $workspace->seatsUsed() - $limit);
    }

    public function assertPlanFits(Workspace $workspace, PlanPrice $price): void
    {
        $plan = $price->plan()->with('features')->first();

        if ($this->seatOverageFor($workspace, $price) > 0) {
            throw new DowngradeBlocked(Features::SEATS, $workspace->seatsUsed(), (int) $plan?->limitFor(Features::SEATS));
        }

        // Every other counted limit gets the same rule: a plan that cannot
        // hold what is already in use is refused before anyone is charged,
        // rather than accepted and turned into an over-limit hard block.
        foreach (Features::MEASURED as $featureKey) {
            $limit = $featureKey === Features::SEATS ? null : $plan?->limitFor($featureKey);

            if ($limit === null) {
                continue;
            }

            $used = $this->usage->current($workspace, $featureKey);

            if ($used > $limit) {
                throw new DowngradeBlocked($featureKey, $used, $limit);
            }
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
