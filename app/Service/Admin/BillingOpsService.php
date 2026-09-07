<?php

namespace App\Service\Admin;

use App\Contract\Admin\BillingOpsContract;
use App\Contract\Billing\ReconcilerContract;
use App\Enums\BillingSource;
use App\Models\DunningState;
use App\Models\Subscription;
use App\Models\WebhookEvent;

/**
 * The operational half of section 8's split.
 *
 * Everything the payment integration writes when something goes wrong -
 * a webhook that would not process, a customer in dunning, a subscription whose
 * provider record has gone missing - is recorded and, until this screen
 * existed, readable only by querying the database. A payment integration whose
 * failures are silent is one you find out about from the customer.
 */
class BillingOpsService implements BillingOpsContract
{
    private const LIMIT = 50;

    public function __construct(private readonly ReconcilerContract $reconciler) {}

    public function overview(): array
    {
        return [
            'failed_webhooks' => $this->failedWebhooks(),
            'dunning' => $this->dunning(),
            'integrity' => $this->integrityAlarms(),
            'recent_webhooks' => $this->recentWebhooks(),
        ];
    }

    /**
     * Anything accepted but not applied: a signature we could not verify, a
     * subscription we could not match, or a throw during reconciliation.
     */
    private function failedWebhooks(): array
    {
        return WebhookEvent::query()
            ->whereNotNull('failed_at')
            ->whereNull('processed_at')
            ->orderByDesc('failed_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (WebhookEvent $event) => [
                'id' => $event->id,
                'event_id' => $event->event_id,
                'event_type' => $event->event_type,
                'error' => $event->error,
                'attempts' => $event->attempts,
                'occurred_at' => $event->occurred_at,
                'failed_at' => $event->failed_at,
                // A retry is only safe on an event we verified at intake.
                'can_retry' => $event->isTrustworthy(),
            ])
            ->all();
    }

    /**
     * Section 9's failed-payment episodes, and section 15's "failed-payment
     * recovery rate" - the population that number is measured over.
     */
    private function dunning(): array
    {
        return DunningState::withoutWorkspaceScope()
            ->open()
            ->with(['subscription.plan', 'workspace'])
            ->orderBy('grace_ends_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (DunningState $state) => [
                'id' => $state->id,
                'workspace_ulid' => $state->workspace?->ulid,
                'workspace_name' => $state->workspace?->name,
                'plan' => $state->subscription?->plan?->name,
                'started_at' => $state->started_at,
                'grace_ends_at' => $state->grace_ends_at,
                // Section 9: access is kept during grace on purpose, so the
                // expiry date is the number staff actually need to act before.
                'grace_expired' => $state->graceExpired(),
                'attempt_count' => $state->attempt_count,
                'last_failure_message' => $state->last_failure_message,
            ])
            ->all();
    }

    /**
     * Subscription::isMissingProviderRecord() describes itself as "a
     * data-integrity alarm, not a business state" - `source = dodo` with no
     * provider id means the sync broke, as distinct from a comp, which is
     * SUPPOSED to have no provider record. This is what monitors it.
     */
    private function integrityAlarms(): array
    {
        return Subscription::withoutWorkspaceScope()
            ->live()
            ->where('billing_source', BillingSource::Dodo)
            ->whereNull('dodo_subscription_id')
            ->with(['workspace', 'plan'])
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Subscription $subscription) => [
                'id' => $subscription->id,
                'workspace_ulid' => $subscription->workspace?->ulid,
                'workspace_name' => $subscription->workspace?->name,
                'plan' => $subscription->plan?->name,
                'status' => $subscription->status->value,
                'created_at' => $subscription->created_at,
            ])
            ->all();
    }

    /** Context for the failures above: what has been arriving at all. */
    private function recentWebhooks(): array
    {
        return WebhookEvent::query()
            ->orderByDesc('received_at')
            ->limit(20)
            ->get()
            ->map(fn (WebhookEvent $event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'received_at' => $event->received_at,
                'processed_at' => $event->processed_at,
                'failed_at' => $event->failed_at,
            ])
            ->all();
    }

    /**
     * Re-runs reconciliation for one event. The reconciler is idempotent and
     * re-checks the signature flag itself, so a retry cannot apply something
     * the original intake refused.
     */
    public function retry(WebhookEvent $event): void
    {
        $event->update(['failed_at' => null, 'error' => null, 'attempts' => $event->attempts + 1]);

        $this->reconciler->reconcile($event->fresh());
    }
}
