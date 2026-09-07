<?php

namespace App\Jobs;

use App\Contract\Billing\PaymentGatewayContract;
use App\Models\UsageRecord;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Carries one metered event to Dodo so it can be billed (section 4).
 *
 * Queued rather than inline because reporting usage must never be in the way of
 * the thing being metered: a customer making an API call should not wait on
 * Dodo, and should certainly not have their call fail because Dodo is down.
 *
 * Retries are safe by construction. The record's own idempotency key is sent as
 * the provider event id, and they ignore a repeat of one they have already
 * seen - so a job that runs twice bills once. That property is the whole reason
 * this can be retried at all: metered usage is money.
 */
class ReportUsageToProvider implements ShouldQueue
{
    use Queueable;

    /**
     * Spread out rather than hammering a provider that is already struggling.
     * After this the record simply stays unreported, which is a findable state
     * (UsageRecord::unreported) rather than a lost one.
     */
    public array $backoff = [60, 300, 900];

    public int $tries = 4;

    public function __construct(private readonly int $usageRecordId) {}

    public function handle(PaymentGatewayContract $gateway): void
    {
        $record = UsageRecord::withoutWorkspaceScope()
            ->with('workspace')
            ->find($this->usageRecordId);

        // Deleted, or already carried by an earlier run of this same job.
        if ($record === null || $record->reported_at !== null) {
            return;
        }

        $customerId = $record->workspace?->dodo_customer_id;

        /*
         * No payment account, so there is nobody to bill. Free and comped
         * workspaces meter usage for the hard block in section 7 exactly like
         * everyone else - that is our own accounting, and it never reaches
         * Dodo (section 12: "free never touches them").
         */
        if (blank($customerId)) {
            return;
        }

        $gateway->reportUsage(
            $customerId,
            $record->idempotency_key,
            $record->feature_key,
            [
                // Their event carries no quantity of its own; meters aggregate
                // over metadata, and every value has to be a string.
                'quantity' => (string) $record->quantity,
                'workspace' => $record->workspace->ulid,
            ],
            $record->occurred_at,
        );

        $record->update([
            'reported_at' => now(),
            // Their id space is ours here: the event is addressed by the same
            // key we deduplicate on, so this is what to quote in a dispute.
            'dodo_event_id' => $record->idempotency_key,
        ]);
    }
}
