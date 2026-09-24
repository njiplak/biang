<?php

namespace App\Jobs;

use App\Contract\Billing\ReconcilerContract;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns one recorded provider event into our state, off the request.
 *
 * Queued because the endpoint's job is to ACCEPT the delivery, and reconciling
 * is not part of accepting it. Doing both inline put an entitlement rebuild and
 * a seat sync inside Dodo's HTTP timeout: a slow one reads to them as a failed
 * delivery, and their answer to a failed delivery is to send it again - so the
 * slowest events were also the ones we processed most often.
 *
 * Safe to run more than once by construction, which is what makes retrying it
 * safe at all. The reconciler moves state forward only (section 8: events
 * arrive out of order and more than once), invoice summaries are keyed on the
 * provider's payment id, and a consumed trial is never given back.
 */
class ReconcileWebhookEvent implements ShouldQueue
{
    use Queueable;

    /**
     * Spread out rather than hammering a provider or a database that is already
     * struggling. After the last attempt the event simply stays unprocessed -
     * a findable state (WebhookEvent::unprocessed), visible in billing-ops with
     * a retry button, and swept up by billing:reconcile-subscriptions.
     */
    public array $backoff = [10, 60, 300];

    public int $tries = 4;

    public function __construct(private readonly int $webhookEventId) {}

    /**
     * Every retry spent: the payment moved at Dodo and our records did not.
     * Critical, so a log channel that alerts (LOG_STACK=single,slack) wakes
     * someone - a customer who paid and is still locked out is the failure.
     */
    public function failed(Throwable $e): void
    {
        Log::critical('Dodo webhook event could not be applied after every retry.', [
            'webhook_event_id' => $this->webhookEventId,
            'error' => $e->getMessage(),
        ]);
    }

    public function handle(ReconcilerContract $reconciler): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        // Deleted, or already handled by an earlier run of this same job.
        if ($event === null || $event->processed_at !== null) {
            return;
        }

        try {
            $reconciler->reconcile($event);
        } catch (Throwable $e) {
            /*
             * Recorded BEFORE rethrowing. The rethrow is what asks the queue to
             * try again; without the record, a job that exhausts its attempts
             * would leave an event that looks merely unprocessed rather than
             * one that failed for a reason somebody can read.
             */
            $event->update([
                'failed_at' => now(),
                'error' => $e->getMessage(),
                'attempts' => $event->attempts + 1,
            ]);

            throw $e;
        }
    }
}
