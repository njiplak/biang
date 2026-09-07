<?php

namespace App\Service\Billing;

use App\Contract\Billing\EntitlementContract;
use App\Contract\Billing\ReconcilerContract;
use App\Contract\Workspace\MembershipContract;
use App\Enums\BillingSource;
use App\Enums\BillingStatus;
use App\Enums\DunningResolution;
use App\Enums\SubscriptionStatus;
use App\Models\DunningState;
use App\Models\InvoiceSummary;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use App\Models\Workspace;
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
            $subscription = $this->locate($data);

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
        $workspace = $subscription->workspace;

        match ($event->event_type) {
            'subscription.active', 'subscription.renewed' => $this->activate($subscription, $workspace, $data),
            'subscription.on_hold', 'subscription.past_due', 'subscription.failed' => $this->pastDue($subscription, $workspace, $data),
            'subscription.cancelled' => $this->cancel($subscription, $workspace, $data),
            'subscription.expired' => $this->expire($subscription, $workspace),
            'payment.succeeded' => $this->paymentSucceeded($subscription, $workspace, $data),
            'payment.failed' => $this->pastDue($subscription, $workspace, $data),
            // Everything else is recorded and deliberately not acted on:
            // disputes, payouts and licence keys do not decide access.
            default => null,
        };

        $subscription->update(['provider_event_at' => $event->occurred_at]);
    }

    /**
     * Section 4: the trial auto-charges on day 15, and this is the notification
     * that says it worked. Also covers every later renewal.
     */
    private function activate(Subscription $subscription, Workspace $workspace, array $data): void
    {
        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'billing_source' => BillingSource::Dodo,
            'current_period_start' => $this->date($data, 'previous_billing_date') ?? now(),
            'current_period_end' => $this->date($data, 'next_billing_date'),
            'cancel_at_period_end' => (bool) ($data['cancel_at_next_billing_date'] ?? false),
        ]);

        // Section 9: recovering clears the grace window in the same breath, or
        // the workspace stays on a countdown it has already escaped.
        $this->resolveDunning($subscription, DunningResolution::Recovered);

        $workspace->update(['grace_ends_at' => null]);

        $this->settle($workspace, BillingStatus::Active);
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
     * Section 12: cancelling drops to the free tier and keeps the data. Section
     * 16's known risk is exactly this arriving from Dodo's own page rather than
     * ours, so it has to land in the same state either route.
     */
    private function cancel(Subscription $subscription, Workspace $workspace, array $data): void
    {
        $subscription->update([
            'status' => SubscriptionStatus::Canceled,
            'canceled_at' => $this->date($data, 'cancelled_at') ?? now(),
            'ended_at' => now(),
        ]);

        $this->resolveDunning($subscription, DunningResolution::Canceled);

        $workspace->update(['grace_ends_at' => null]);

        $this->settle($workspace, BillingStatus::Free);
    }

    private function expire(Subscription $subscription, Workspace $workspace): void
    {
        $subscription->update([
            'status' => SubscriptionStatus::Expired,
            'ended_at' => now(),
        ]);

        $this->resolveDunning($subscription, DunningResolution::Canceled);

        $workspace->update(['grace_ends_at' => null]);

        $this->settle($workspace, BillingStatus::Free);
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

        if ($subscription->status === SubscriptionStatus::PastDue) {
            $subscription->update(['status' => SubscriptionStatus::Active]);
            $this->settle($workspace, BillingStatus::Active);
        }
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
     */
    private function locate(array $data): ?Subscription
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

        $subscription = $workspace === null ? null : Subscription::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->id)
            ->live()
            ->first();

        // First sighting: adopt the provider's id so every later event for this
        // subscription finds it directly.
        if ($subscription !== null && $providerId !== null && $subscription->dodo_subscription_id === null) {
            $subscription->update(['dodo_subscription_id' => $providerId]);
        }

        return $subscription;
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
