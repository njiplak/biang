<?php

namespace App\Service\Billing;

use App\Contract\Billing\PaymentGatewayContract;
use App\Contract\Billing\ReconcilerContract;
use App\Contract\Billing\SubscriptionPullerContract;
use App\Enums\PullOutcome;
use App\Enums\WebhookEventSource;
use App\Models\InvoiceSummary;
use App\Models\WebhookEvent;
use App\Models\Workspace;

/**
 * The pull half of section 8's reconciliation.
 *
 * It does not decide anything. It asks Dodo, dresses the answer as the webhook
 * that answer would have been, and hands it to the same reconciler - so a
 * subscription settled this way lands in exactly the state a delivered webhook
 * would have left it, including the out-of-order and idempotency rules.
 */
class SubscriptionPuller implements SubscriptionPullerContract
{
    public function __construct(
        private readonly PaymentGatewayContract $gateway,
        private readonly ReconcilerContract $reconciler,
    ) {}

    public function pull(string $providerSubscriptionId, ?Workspace $expected = null): PullOutcome
    {
        $data = $this->gateway->retrieveSubscription($providerSubscriptionId);

        /*
         * The id in a return URL is a hint to go and look, never evidence.
         * What makes the answer THEIRS is the workspace ulid we stamped into
         * the checkout metadata; without this check, pasting a stranger's
         * subscription id would attach their subscription to whatever
         * workspace the pasting customer happened to be in.
         */
        if ($expected !== null && ($data['metadata']['workspace_ulid'] ?? null) !== $expected->ulid) {
            return PullOutcome::Mismatched;
        }

        /*
         * Payments first, and this order is load-bearing. The reconciler tells
         * a trial from a paid subscription by whether an invoice summary exists
         * (Dodo has no `trialing` status - a subscription in its free days is
         * `active` with nothing charged). Reconciling the subscription first
         * would find no invoice and record a paid customer as trialing.
         */
        $applied = $this->applyPayments($providerSubscriptionId, $data['metadata'] ?? []);

        $event = $this->record(
            $this->eventTypeFor($data['status'] ?? null),
            $data,
            $this->fingerprint($providerSubscriptionId, $data),
        );

        // Already recorded on an earlier pull, and nothing about the state has
        // changed since - the fingerprint is what says so.
        if ($event->processed_at !== null) {
            return $applied ? PullOutcome::Applied : PullOutcome::InSync;
        }

        $this->reconciler->reconcile($event);

        if ($event->fresh()->processed_at === null) {
            // The reconciler refused it: nothing here to attach it to.
            return $applied ? PullOutcome::Applied : PullOutcome::NotFound;
        }

        return PullOutcome::Applied;
    }

    /**
     * Record every settled payment we have no invoice summary for.
     *
     * Keyed on the payment id rather than the subscription, so this works
     * before the subscription row exists: a `payment.succeeded` is one of the
     * events that opens one.
     *
     * $subscriptionMetadata is what makes that possible. The reconciler finds
     * the workspace through the ulid we stamped at checkout, and whether Dodo
     * copies a subscription's metadata onto its payments is their business, not
     * something to build on - so we carry it down ourselves.
     */
    private function applyPayments(string $providerSubscriptionId, array $subscriptionMetadata): bool
    {
        $ids = $this->gateway->listSucceededPaymentIds($providerSubscriptionId);

        if ($ids === []) {
            return false;
        }

        $recorded = InvoiceSummary::withoutWorkspaceScope()
            ->whereIn('dodo_invoice_id', $ids)
            ->pluck('dodo_invoice_id')
            ->all();

        $applied = false;

        foreach (array_diff($ids, $recorded) as $paymentId) {
            // Only the missing ones are fetched in full. Their list omits tax
            // and the settlement amount, and re-fetching a payment we already
            // hold would be one request per renewal, forever.
            $payment = $this->gateway->retrievePayment($paymentId);

            // The payment's own metadata wins where it has any; the
            // subscription's fills the gap that would otherwise leave a first
            // purchase with nothing to attach this to.
            $payment['metadata'] = ($payment['metadata'] ?? []) + $subscriptionMetadata;

            $event = $this->record('payment.succeeded', $payment, "pull:payment:{$paymentId}");

            if ($event->processed_at !== null) {
                continue;
            }

            $this->reconciler->reconcile($event);
            $applied = true;
        }

        return $applied;
    }

    /**
     * A stable id for the STATE being reported, not for the moment of asking.
     *
     * This is what keeps a check that runs every hour from writing a row every
     * hour: pulling the same state twice produces the same id, `firstOrCreate`
     * finds the processed row, and nothing happens. A state that has actually
     * moved produces a new id and is reconciled.
     */
    private function fingerprint(string $providerSubscriptionId, array $data): string
    {
        $state = hash('sha256', (string) json_encode([
            $data['status'] ?? null,
            $data['next_billing_date'] ?? null,
            $data['previous_billing_date'] ?? null,
            $data['cancel_at_next_billing_date'] ?? null,
            $data['cancelled_at'] ?? null,
            $data['trial_period_days'] ?? null,
        ]));

        return "pull:sub:{$providerSubscriptionId}:".substr($state, 0, 16);
    }

    /** Their subscription status in the vocabulary the reconciler already reads. */
    private function eventTypeFor(?string $status): string
    {
        return match ($status) {
            'active' => 'subscription.active',
            'on_hold' => 'subscription.on_hold',
            'past_due' => 'subscription.past_due',
            'failed' => 'subscription.failed',
            'cancelled' => 'subscription.cancelled',
            'expired' => 'subscription.expired',
            /*
             * `paused` and `pending` are real statuses of theirs that the
             * reconciler deliberately does not act on. Named as they are rather
             * than mapped onto something we do handle: recording what was
             * actually seen is the point of an audit trail, and the fingerprint
             * above means an unactionable state is recorded once, not hourly.
             */
            default => 'subscription.'.($status ?? 'unknown'),
        };
    }

    private function record(string $eventType, array $data, string $eventId): WebhookEvent
    {
        return WebhookEvent::firstOrCreate(
            ['provider' => 'dodo', 'event_id' => $eventId],
            [
                'source' => WebhookEventSource::Pull,
                'event_type' => $eventType,
                'payload' => ['type' => $eventType, 'data' => $data],
                // Nothing signed this, and saying otherwise would put a lie in
                // the audit trail. WebhookEvent::isTrustworthy knows a pull is
                // evidence because WE placed the call.
                'signature_verified' => false,
                /*
                 * Now, because a snapshot is true as of now. That is also what
                 * makes it win: the reconciler ignores anything older than the
                 * state already recorded, and a fresh answer from the provider
                 * is never the stale one.
                 */
                'occurred_at' => now(),
                'received_at' => now(),
                'attempts' => 0,
            ],
        );
    }
}
