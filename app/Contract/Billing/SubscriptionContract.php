<?php

namespace App\Contract\Billing;

use App\Enums\CancellationFeedback;
use App\Models\Addon;
use App\Models\AddonPrice;
use App\Models\AdminUser;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;

interface SubscriptionContract
{
    /** Section 5 Path B. Throws TrialAlreadyConsumed - the limit is per person, ever. */
    public function startTrial(Workspace $workspace, PlanPrice $price, User $startedBy): Subscription;

    /** Section 10: sales grants a plan by hand, with no payment behind it. */
    public function grantPlan(Workspace $workspace, PlanPrice $price, AdminUser $admin, string $reason): Subscription;

    /** Section 4: the trial auto-charges on day 15. */
    public function convertTrial(Subscription $subscription): Subscription;

    /**
     * Section 10: staff extend a trial by hand. Throws TrialNotExtendable when
     * the workspace is not actually on one.
     */
    public function extendTrial(Workspace $workspace, int $days, AdminUser $admin, string $reason): Subscription;

    /** Throws DowngradeBlocked when the new plan cannot hold current usage. */
    public function changePlan(Workspace $workspace, PlanPrice $price): Subscription;

    /**
     * How many seats over a plan's allowance this workspace already is, or 0 if
     * it fits.
     *
     * Asked BEFORE money moves, by everything that can put a workspace on a
     * plan - not just the in-app switcher. A first purchase used to skip this
     * entirely, so a workspace with 25 people could buy a 5-seat plan, pay, and
     * land straight in section 7's hard block having just been charged. With
     * Dodo as merchant of record that is a refund request, not a rollback.
     *
     * Returned as a number rather than a bool because section 7 requires us to
     * say exactly how many have to go, and the billing page needs the same
     * figure to mark a plan as unbuyable before anyone clicks it.
     */
    /** Drop a downgrade scheduled for the renewal and stay on the current plan. */
    public function keepCurrentPlan(Workspace $workspace): void;

    /** When a move to $price would wait for the renewal (a downgrade), or null when it applies now. */
    public function downgradeDate(Subscription $subscription, PlanPrice $price): ?CarbonInterface;

    public function seatOverageFor(Workspace $workspace, PlanPrice $price): int;

    /**
     * The same question, as a refusal.
     *
     * @throws \App\Exceptions\Domain\DowngradeBlocked naming how many to remove
     */
    public function assertPlanFits(Workspace $workspace, PlanPrice $price): void;

    /**
     * Drops the workspace to read-only. Deletes nothing.
     *
     * $feedback and $comment are the customer's answer to "why", and both are
     * optional forever: section 8's exit is never blocked, and requiring an
     * answer to leave is a way of blocking it. Section 15 wants the split
     * between voluntary churn and failed payments, and this is where the
     * voluntary half gets its reason.
     */
    public function cancel(
        Workspace $workspace,
        ?CancellationFeedback $feedback = null,
        ?string $comment = null,
    ): void;

    /**
     * Section 4: buy a quantity add-on, a paid unlock, or metered capacity.
     * Entitlements move immediately; money follows in phase 4.
     */
    /**
     * The customer's cancel: scheduled for the end of the paid period when
     * there is one, immediate otherwise. cancel() stays immediate for callers
     * that must stop now (closing a workspace, ending a card-less trial).
     */
    public function cancelAtPeriodEnd(
        Workspace $workspace,
        ?CancellationFeedback $feedback = null,
        ?string $comment = null,
    ): void;

    /**
     * When cancelAtPeriodEnd would take effect, or null when it would cancel
     * immediately (a plan granted by hand, or one already past due).
     */
    public function paidThrough(Subscription $subscription): ?CarbonInterface;

    /** Undo cancelAtPeriodEnd while the paid period is still running. */
    public function resume(Workspace $workspace): void;

    public function purchaseAddon(Workspace $workspace, AddonPrice $price, int $quantity = 1): Subscription;

    /** Zero removes the item. Throws DowngradeBlocked if the capacity is in use. */
    public function changeAddonQuantity(Workspace $workspace, Addon $addon, int $quantity): Subscription;
}
