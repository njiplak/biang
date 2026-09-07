<?php

namespace App\Service\Admin;

use App\Contract\Admin\BillingOpsContract;
use App\Contract\Billing\ReconcilerContract;
use App\Enums\BillingSource;
use App\Models\DunningState;
use App\Models\Subscription;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

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

    /**
     * The lists this screen shows, and the only values paginate() accepts.
     *
     * An allow-list rather than a free string: the value arrives from a query
     * parameter, and turning that into a method name would let anyone call
     * anything on this class.
     */
    public const LISTS = ['failed_webhooks', 'dunning', 'integrity', 'recent_webhooks'];

    public function __construct(private readonly ReconcilerContract $reconciler) {}

    public function paginate(string $list, ?string $search, int $perPage): LengthAwarePaginator
    {
        $perPage = max(1, $perPage);

        return match ($list) {
            'failed_webhooks' => $this->failedWebhookQuery($search)->paginate($perPage)
                ->through(fn (WebhookEvent $event) => $this->presentFailedWebhook($event)),
            'dunning' => $this->dunningQuery($search)->paginate($perPage)
                ->through(fn (DunningState $state) => $this->presentDunning($state)),
            'integrity' => $this->integrityQuery($search)->paginate($perPage)
                ->through(fn (Subscription $subscription) => $this->presentIntegrity($subscription)),
            'recent_webhooks' => $this->recentWebhookQuery($search)->paginate($perPage)
                ->through(fn (WebhookEvent $event) => $this->presentRecentWebhook($event)),
            default => throw new InvalidArgumentException("Unknown billing-ops list [{$list}]."),
        };
    }

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
        return $this->failedWebhookQuery(null)
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (WebhookEvent $event) => $this->presentFailedWebhook($event))
            ->all();
    }

    private function failedWebhookQuery(?string $search): Builder
    {
        return WebhookEvent::query()
            ->whereNotNull('failed_at')
            ->whereNull('processed_at')
            ->when($search, fn (Builder $query, string $term) => $query
                ->where(fn (Builder $q) => $q
                    ->where('event_type', 'like', "%{$term}%")
                    ->orWhere('event_id', 'like', "%{$term}%")
                    ->orWhere('error', 'like', "%{$term}%")))
            ->orderByDesc('failed_at');
    }

    /** @return array<string, mixed> */
    private function presentFailedWebhook(WebhookEvent $event): array
    {
        return [
            'id' => $event->id,
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'error' => $event->error,
            'attempts' => $event->attempts,
            'occurred_at' => $event->occurred_at,
            'failed_at' => $event->failed_at,
            // A retry is only safe on an event we verified at intake.
            'can_retry' => $event->isTrustworthy(),
        ];
    }

    /**
     * Section 9's failed-payment episodes, and section 15's "failed-payment
     * recovery rate" - the population that number is measured over.
     */
    private function dunning(): array
    {
        return $this->dunningQuery(null)
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (DunningState $state) => $this->presentDunning($state))
            ->all();
    }

    private function dunningQuery(?string $search): Builder
    {
        return DunningState::withoutWorkspaceScope()
            ->open()
            ->with(['subscription.plan', 'workspace'])
            ->when($search, fn (Builder $query, string $term) => $query
                ->whereHas('workspace', fn (Builder $q) => $q->where('name', 'like', "%{$term}%")))
            ->orderBy('grace_ends_at');
    }

    /** @return array<string, mixed> */
    private function presentDunning(DunningState $state): array
    {
        return [
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
        ];
    }

    /**
     * Subscription::isMissingProviderRecord() describes itself as "a
     * data-integrity alarm, not a business state" - `source = dodo` with no
     * provider id means the sync broke, as distinct from a comp, which is
     * SUPPOSED to have no provider record. This is what monitors it.
     */
    private function integrityAlarms(): array
    {
        return $this->integrityQuery(null)
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Subscription $subscription) => $this->presentIntegrity($subscription))
            ->all();
    }

    private function integrityQuery(?string $search): Builder
    {
        return Subscription::withoutWorkspaceScope()
            ->live()
            ->where('billing_source', BillingSource::Dodo)
            ->whereNull('dodo_subscription_id')
            ->with(['workspace', 'plan'])
            ->when($search, fn (Builder $query, string $term) => $query
                ->whereHas('workspace', fn (Builder $q) => $q->where('name', 'like', "%{$term}%")))
            ->orderByDesc('created_at');
    }

    /** @return array<string, mixed> */
    private function presentIntegrity(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'workspace_ulid' => $subscription->workspace?->ulid,
            'workspace_name' => $subscription->workspace?->name,
            'plan' => $subscription->plan?->name,
            'status' => $subscription->status->value,
            'created_at' => $subscription->created_at,
        ];
    }

    /** Context for the failures above: what has been arriving at all. */
    private function recentWebhooks(): array
    {
        return $this->recentWebhookQuery(null)
            ->limit(20)
            ->get()
            ->map(fn (WebhookEvent $event) => $this->presentRecentWebhook($event))
            ->all();
    }

    private function recentWebhookQuery(?string $search): Builder
    {
        return WebhookEvent::query()
            ->when($search, fn (Builder $query, string $term) => $query
                ->where('event_type', 'like', "%{$term}%"))
            ->orderByDesc('received_at');
    }

    /** @return array<string, mixed> */
    private function presentRecentWebhook(WebhookEvent $event): array
    {
        return [
            'id' => $event->id,
            'event_type' => $event->event_type,
            'received_at' => $event->received_at,
            'processed_at' => $event->processed_at,
            'failed_at' => $event->failed_at,
        ];
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
