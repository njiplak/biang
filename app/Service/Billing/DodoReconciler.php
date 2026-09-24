<?php

namespace App\Service\Billing;

use App\Contract\Billing\BillingNotifierContract;
use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\ReconcilerContract;
use App\Contract\Workspace\MembershipContract;
use App\Enums\BillingSource;
use App\Enums\BillingStatus;
use App\Enums\DunningResolution;
use App\Enums\SubscriptionStatus;
use App\Models\DunningState;
use App\Models\InvoiceSummary;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Workspace;
use App\Notifications\Billing\SubscriptionCanceledNotification;
use App\Support\ProductEvents;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns Dodo's notifications into OUR state.
 *
 * Section 8 splits the world: their records are authoritative for money, ours
 * for access. So nothing here asks Dodo whether a customer may write - it moves
 * `subscriptions` and `workspaces`, and lets the entitlement layer decide the
 * rest exactly as it does for a plan granted by hand.
 *
 * Section 16 names the failure this is built around: "A customer cancels on the
 * provider's page. Our records find out via notification, not immediately. We
 * treat their notifications as authoritative and reconcile."
 */
class DodoReconciler implements ReconcilerContract
{
    public function __construct(
        private readonly EntitlementContract $entitlements,
        private readonly MembershipContract $memberships,
        private readonly BillingNotifierContract $notifier,
    ) {}

    public function reconcile(WebhookEvent $event): void
    {
        // An unverified event must never move billing state. The controller
        // already refuses these; this is the second lock on the same door,
        // because this method is also reachable from a retry command.
        if (! $event->isTrustworthy()) {
            $event->update(['failed_at' => now(), 'error' => 'Signature was not verified.']);

            return;
        }

        DB::transaction(function () use ($event) {
            $data = $event->payload['data'] ?? [];
            $subscription = $this->locate($data, $event->event_type);

            if ($subscription === null) {
                // Recorded, not processed. An event for a subscription we have
                // never seen is either an out-of-order delivery or a customer
                // created outside our checkout; either way, silently dropping
                // it would erase the only evidence.
                $event->update([
                    'failed_at' => now(),
                    'error' => 'No matching subscription.',
                ]);

                return;
            }

            /*
             * Section 8: "Webhooks arrive out of order and more than once."
             * State only moves forward. An `active` that overtakes a later
             * `cancelled` would silently restore access we already removed.
             */
            if ($this->isStale($subscription, $event)) {
                $event->update([
                    'processed_at' => now(),
                    'error' => 'Ignored: older than the state already recorded.',
                ]);

                return;
            }

            $this->apply($event, $subscription, $data);

            $event->update([
                'processed_at' => now(),
                'workspace_id' => $subscription->workspace_id,
            ]);
        });
    }

    private function apply(WebhookEvent $event, Subscription $subscription, array $data): void
    {
        /*
         * withTrashed: closing a workspace cancels its subscription at Dodo and
         * soft-deletes it, so the cancellation event that follows belongs to a
         * trashed workspace. Through the plain relation that was null, and
         * every such event failed on it until its retries ran out.
         */
        $workspace = $subscription->workspace()->withTrashed()->first();

        $this->adoptCustomerId($workspace, $data);

        match ($event->event_type) {
            'subscription.active', 'subscription.renewed' => $this->activate($subscription, $workspace, $data, $event->event_id),
            'subscription.on_hold', 'subscription.past_due', 'subscription.failed' => $this->pastDue($subscription, $workspace, $data),
            'subscription.cancelled' => $this->cancel($subscription, $workspace, $data, $event->event_id),
            // Changes made on their side - a cancellation scheduled from their
            // portal, a scheduled plan change applied - arrive as one of these
            // and carry the whole subscription, so its status says what to do.
            'subscription.updated', 'subscription.plan_changed' => $this->byStatus($subscription, $workspace, $data, $event->event_id),
            'subscription.expired' => $this->expire($subscription, $workspace),
            'payment.succeeded' => $this->paymentSucceeded($subscription, $workspace, $data),
            'payment.failed' => $this->pastDue($subscription, $workspace, $data),
            // Everything else is recorded and deliberately not acted on:
            // disputes, payouts and licence keys do not decide access.
            default => null,
        };

        $subscription->update(['provider_event_at' => $event->occurred_at]);
    }

    /** The same mapping SubscriptionPuller uses for a subscription it asked about. */
    private function byStatus(Subscription $subscription, Workspace $workspace, array $data, string $eventKey): void
    {
        match ($data['status'] ?? null) {
            'active' => $this->activate($subscription, $workspace, $data, $eventKey),
            'on_hold', 'past_due', 'failed' => $this->pastDue($subscription, $workspace, $data),
            'cancelled' => $this->cancel($subscription, $workspace, $data, $eventKey),
            'expired' => $this->expire($subscription, $workspace),
            default => null,
        };
    }

    /**
     * Section 4: the trial auto-charges on day 15, and this is the notification
     * that says it worked. Also covers every later renewal.
     */
    private function activate(Subscription $subscription, Workspace $workspace, array $data, string $eventKey): void
    {
        $wasScheduledToEnd = $subscription->cancel_at_period_end;
        $previousStatus = $subscription->status;

        /*
         * Dodo has no `trialing` status - a subscription in its free days is
         * `active` with trial_period_days set and nothing charged yet. So we
         * have to decide from something else what "active" means here.
         *
         * That something is whether MONEY has actually moved. It cannot be
         * previous_billing_date: that field is required in their payload and is
         * populated from the moment a subscription is created, trial or not, so
         * testing it for null classifies every trial as already paid. (Verified
         * against their sandbox, which is the only reason we know.)
         *
         * A charge of zero does not count. A trial that opens with a nil
         * authorisation has still not been paid for.
         */
        $trialing = (int) ($data['trial_period_days'] ?? 0) > 0
            && ! $this->hasBeenCharged($subscription);

        $subscription->update([
            'status' => $trialing ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            'billing_source' => BillingSource::Dodo,
            'current_period_start' => $this->date($data, 'previous_billing_date') ?? now(),
            'current_period_end' => $this->date($data, 'next_billing_date'),
            // The trial ends when the first charge is due, which is the same
            // date the section 4 warning emails count down to.
            'trial_ends_at' => $trialing
                ? $this->date($data, 'next_billing_date')
                : $subscription->trial_ends_at,
            'cancel_at_period_end' => (bool) ($data['cancel_at_next_billing_date'] ?? false),
        ]);

        if ($trialing) {
            $this->consumeTrial($subscription, $data);
        }

        $this->applyScheduledPlanChange($subscription, $data);

        // Section 15's funnel: counted when the state first moves, not on
        // every renewal or re-delivered event.
        $now = $trialing ? SubscriptionStatus::Trialing : SubscriptionStatus::Active;
        // wasRecentlyCreated: a first sighting is opened as trialing by
        // open(), so its status alone would not show it moving.
        if ($previousStatus !== $now || $subscription->wasRecentlyCreated) {
            ProductEvents::record($trialing ? 'trial_started' : 'subscription_activated', null, $workspace, [
                'plan' => $subscription->plan?->code,
            ]);
        }

        /*
         * Scheduled to end, and not by us: our own cancel sets the flag before
         * Dodo echoes it back, so a flip seen here was made on their portal and
         * nobody has confirmed it to the customer yet.
         */
        if (! $wasScheduledToEnd && $subscription->cancel_at_period_end) {
            $this->confirmCancellation($subscription, $workspace, $this->date($data, 'next_billing_date'), $eventKey);
        }

        // Section 9: recovering clears the grace window in the same breath, or
        // the workspace stays on a countdown it has already escaped.
        $this->resolveDunning($subscription, DunningResolution::Recovered);

        $workspace->update(['grace_ends_at' => null]);

        $this->settle($workspace, $trialing ? BillingStatus::Trialing : BillingStatus::Active);
    }

    /**
     * Whether real money has ever moved for this subscription.
     *
     * The trial/paid distinction rests on this rather than on anything in the
     * payload, because it is the actual question - "have they been charged
     * yet" - and it survives events arriving out of order (section 8).
     *
     * Zero-value payments are excluded on purpose: a trial that opens with a
     * nil authorisation has still not been paid for, and counting it would end
     * the trial on day one.
     */
    private function hasBeenCharged(Subscription $subscription): bool
    {
        return InvoiceSummary::withoutWorkspaceScope()
            ->where('subscription_id', $subscription->id)
            ->where('status', 'paid')
            ->where('total_minor', '>', 0)
            ->exists();
    }

    /**
     * Section 12: "Trial eligibility: one per person, ever" - the person who
     * STARTS one, which is why their id rides along in the checkout metadata.
     * By the time this webhook lands there is no session left to ask.
     *
     * Only ever set, never cleared: a customer who cancels during the trial has
     * still had their trial, which is the whole point of the rule.
     */
    private function consumeTrial(Subscription $subscription, array $data): void
    {
        $userId = $data['metadata']['started_by_user_id'] ?? null;

        if ($userId === null) {
            return;
        }

        User::query()
            ->whereKey($userId)
            ->whereNull('trial_consumed_at')
            ->update([
                'trial_consumed_at' => now(),
                'trial_consumed_workspace_id' => $subscription->workspace_id,
            ]);
    }

    /**
     * Section 9: "Past due keeps full access." A card problem is not misuse, so
     * nothing is taken away here - a grace window opens and the customer is
     * told. ExpireDunningGrace acts when it runs out.
     */
    private function pastDue(Subscription $subscription, Workspace $workspace, array $data): void
    {
        $subscription->update(['status' => SubscriptionStatus::PastDue]);

        $graceEnds = $this->date($data, 'past_due_ends_at')
            ?? now()->addDays((int) config('dodo.grace_days'));

        DunningState::withoutWorkspaceScope()->firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'subscription_id' => $subscription->id,
                'resolution' => null,
            ],
            [
                'started_at' => now(),
                'grace_ends_at' => $graceEnds,
                'attempt_count' => 0,
            ],
        );

        $workspace->update(['grace_ends_at' => $graceEnds]);

        $this->settle($workspace, BillingStatus::PastDue);
    }

    /**
     * Cancelling drops the workspace to read-only and keeps the data. Section
     * 16's known risk is exactly this arriving from Dodo's own page rather than
     * ours, so it has to land in the same state either route.
     */
    private function cancel(Subscription $subscription, Workspace $workspace, array $data, string $eventKey): void
    {
        /*
         * Ended outright from their side, with no warning sent. Excluded: one we
         * cancelled ourselves (already not live), a scheduled end the customer
         * was told about when it was scheduled, and a past-due subscription
         * ending, which the grace-period emails already cover.
         */
        $unannounced = in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)
            && ! $subscription->cancel_at_period_end;

        $subscription->update([
            'status' => SubscriptionStatus::Canceled,
            'canceled_at' => $this->date($data, 'cancelled_at') ?? now(),
            'ended_at' => now(),
        ]);

        $this->resolveDunning($subscription, DunningResolution::Canceled);

        $workspace->update(['grace_ends_at' => null]);

        $this->settle($workspace, BillingStatus::Unpaid);

        if ($unannounced) {
            $this->confirmCancellation($subscription, $workspace, null, $eventKey);
        }
    }

    /**
     * A downgrade we scheduled lands at the renewal. Dodo's record then names
     * the new product, and that - not the date - is what moves our plan, so a
     * renewal that did not apply it leaves the customer where they are.
     */
    private function applyScheduledPlanChange(Subscription $subscription, array $data): void
    {
        if ($subscription->scheduled_plan_price_id === null) {
            return;
        }

        $productId = $data['product_id'] ?? null;
        $scheduled = PlanPrice::find($subscription->scheduled_plan_price_id);

        if ($scheduled !== null && $productId !== null && $productId === $scheduled->dodo_product_id) {
            $subscription->update([
                'plan_id' => $scheduled->plan_id,
                'plan_price_id' => $scheduled->id,
                'scheduled_plan_price_id' => null,
                'scheduled_change_at' => null,
            ]);

            return;
        }

        // Still on the old product and nothing scheduled any more: it was
        // dropped on their side, so stop showing it here.
        if (array_key_exists('scheduled_change', $data) && $data['scheduled_change'] === null && $productId !== null) {
            $subscription->update([
                'scheduled_plan_price_id' => null,
                'scheduled_change_at' => null,
            ]);
        }
    }

    /** Keyed on the event, so a redelivery or a retry never sends it twice. */
    private function confirmCancellation(Subscription $subscription, Workspace $workspace, ?Carbon $endsAt, string $eventKey): void
    {
        // A closed workspace's owners already know; they closed it.
        if ($workspace->trashed()) {
            return;
        }

        $plan = $subscription->plan->name;

        $this->notifier->sendOnce(
            $workspace,
            'subscription_canceled',
            "subscription_canceled:sub_{$subscription->id}:{$eventKey}",
            fn () => new SubscriptionCanceledNotification($workspace, $plan, $endsAt),
        );
    }

    private function expire(Subscription $subscription, Workspace $workspace): void
    {
        $subscription->update([
            'status' => SubscriptionStatus::Expired,
            'ended_at' => now(),
        ]);

        $this->resolveDunning($subscription, DunningResolution::Canceled);

        $workspace->update(['grace_ends_at' => null]);

        $this->settle($workspace, BillingStatus::Unpaid);
    }

    /**
     * Section 8: "We keep a summary; the document itself stays with the
     * provider." Every figure here is copied from them and never computed by
     * us - they own tax as merchant of record.
     */
    private function paymentSucceeded(Subscription $subscription, Workspace $workspace, array $data): void
    {
        $paymentId = $data['payment_id'] ?? null;

        if ($paymentId !== null) {
            InvoiceSummary::withoutWorkspaceScope()->updateOrCreate(
                ['dodo_invoice_id' => $paymentId],
                [
                    'workspace_id' => $workspace->id,
                    'subscription_id' => $subscription->id,
                    'status' => 'paid',
                    'currency' => $data['currency'] ?? 'USD',
                    'subtotal_minor' => (int) ($data['settlement_amount'] ?? $data['total_amount'] ?? 0),
                    'tax_minor' => (int) ($data['tax'] ?? 0),
                    'total_minor' => (int) ($data['total_amount'] ?? 0),
                    'issued_at' => $this->date($data, 'created_at') ?? now(),
                    'paid_at' => now(),
                ],
            );
        }

        // A payment landing is the clearest possible signal that dunning is over.
        $this->resolveDunning($subscription, DunningResolution::Recovered);
        $workspace->update(['grace_ends_at' => null]);

        /*
         * A charge landing is what ENDS a trial, and it is also what recovers a
         * past-due one. Both are here rather than only in activate(), because
         * section 8 warns these events arrive out of order: if the renewal
         * notification overtakes the payment, activate() has already run and
         * seen no charge, and this is what corrects it.
         */
        $wasUnpaid = in_array($subscription->status, [
            SubscriptionStatus::PastDue,
            SubscriptionStatus::Trialing,
        ], true);

        if ($wasUnpaid && ($data['total_amount'] ?? 0) > 0) {
            $subscription->update(['status' => SubscriptionStatus::Active]);
            $this->settle($workspace, BillingStatus::Active);
        }
    }

    /**
     * Section 5's "open the payment provider's page for cards and invoices"
     * needs a customer to open it FOR, and this is the only place their id ever
     * reaches us - we hand Dodo an email at checkout and they mint the customer.
     *
     * Written once and never overwritten. The column is unique, and a second id
     * arriving for a workspace that already has one means something is wrong
     * upstream; quietly adopting it would replace a working portal link with a
     * stranger's.
     */
    private function adoptCustomerId(?Workspace $workspace, array $data): void
    {
        $customerId = $data['customer']['customer_id'] ?? null;

        // Nullable deliberately. This runs for EVERY event type, including the
        // ones the match below ignores, and a soft-deleted workspace resolves
        // to null through the relation - so an unhandled event for a closed
        // workspace used to pass through untouched and must keep doing so.
        if ($workspace === null || $customerId === null || filled($workspace->dodo_customer_id)) {
            return;
        }

        $workspace->update(['dodo_customer_id' => $customerId]);
    }

    private function resolveDunning(Subscription $subscription, DunningResolution $resolution): void
    {
        DunningState::withoutWorkspaceScope()
            ->where('subscription_id', $subscription->id)
            ->open()
            ->update(['resolution' => $resolution, 'resolved_at' => now()]);
    }

    /**
     * Identical to SubscriptionService::settle - the point of section 8's split
     * is that a webhook and a staff grant land in exactly the same state.
     */
    private function settle(Workspace $workspace, BillingStatus $status): void
    {
        $workspace->update(['billing_status' => $status]);

        $this->entitlements->rebuild($workspace);
        $this->memberships->syncSeats($workspace);
    }

    /**
     * Linked by the provider's own id first, then by the workspace we stamped
     * into metadata at checkout - which is what catches the very first event
     * for a subscription, before we have ever seen its id.
     *
     * Failing that, OPENED. A workspace buying for the first time has no
     * subscription row at all: section 8 promises checkout changes nothing of
     * ours until the money moves, so the row cannot exist before this event.
     * Without this the ordinary first purchase reconciled to "No matching
     * subscription" and the customer paid for nothing.
     */
    private function locate(array $data, string $eventType): ?Subscription
    {
        $providerId = $data['subscription_id'] ?? null;

        if ($providerId !== null) {
            $found = Subscription::withoutWorkspaceScope()
                ->where('dodo_subscription_id', $providerId)
                ->first();

            if ($found !== null) {
                return $found;
            }
        }

        $ulid = $data['metadata']['workspace_ulid'] ?? null;

        if ($ulid === null) {
            return null;
        }

        $workspace = Workspace::query()->where('ulid', $ulid)->first();

        if ($workspace === null) {
            return null;
        }

        $subscription = Subscription::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->live()
            ->first();

        if ($subscription === null) {
            return $this->open($workspace, $data, $eventType, $providerId);
        }

        // First sighting: adopt the provider's id so every later event for this
        // subscription finds it directly.
        if ($providerId !== null && $subscription->dodo_subscription_id === null) {
            $subscription->update(['dodo_subscription_id' => $providerId]);
        }

        return $subscription;
    }

    /**
     * The first subscription a workspace has ever had, built from what the
     * checkout metadata carried.
     *
     * `plan_price_id` is stamped by DodoPaymentGateway::createCheckout for
     * exactly this: it is the one thing their payload cannot tell us, because
     * their product id is a different id space from our price rows.
     *
     * Only opened for the events that mean a subscription EXISTS and is live.
     * A `cancelled` for something we have never heard of is a genuine anomaly,
     * and inventing a cancelled row to hold it would turn a visible failure
     * into a silent one.
     *
     * Opened as Trialing rather than Active deliberately: nothing has been
     * charged as far as our records know, and that is what Trialing means here.
     * `activate()` recomputes it from the payload a few lines later, and
     * `paymentSucceeded()` promotes it - which only happens from an unpaid
     * status, so guessing Active would strand a first payment.
     */
    private function open(Workspace $workspace, array $data, string $eventType, ?string $providerId): ?Subscription
    {
        $opens = in_array($eventType, [
            'subscription.active',
            'subscription.renewed',
            'payment.succeeded',
        ], true);

        if (! $opens || $providerId === null) {
            return null;
        }

        $price = PlanPrice::find($data['metadata']['plan_price_id'] ?? null);

        if ($price === null) {
            return null;
        }

        /*
         * The provider id goes on at creation, not afterwards. The column is
         * unique, so two deliveries of the same first event race into one row
         * rather than two - the loser fails, is recorded, and finds the row on
         * redelivery.
         */
        return Subscription::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $price->plan_id,
            'plan_price_id' => $price->id,
            'status' => SubscriptionStatus::Trialing,
            'billing_source' => BillingSource::Dodo,
            'dodo_subscription_id' => $providerId,
            'current_period_start' => now(),
        ]);
    }

    private function isStale(Subscription $subscription, WebhookEvent $event): bool
    {
        return $subscription->provider_event_at !== null
            && $event->occurred_at !== null
            && $event->occurred_at->lt($subscription->provider_event_at);
    }

    private function date(array $data, string $key): ?\Illuminate\Support\Carbon
    {
        $value = $data[$key] ?? null;

        return $value === null ? null : \Illuminate\Support\Carbon::parse($value);
    }
}
