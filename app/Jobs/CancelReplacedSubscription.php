<?php

namespace App\Jobs;

use App\Contract\Billing\PaymentGatewayContract;
use App\Models\Subscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stop Dodo charging for a subscription a lifetime purchase replaced.
 *
 * Our side ends the moment the lifetime payment lands - that is when the
 * customer paid for something else. Dodo's side has to be told, and telling
 * them is a network call that cannot sit inside the reconciler's transaction,
 * so it runs here, after commit, with retries.
 *
 * The reconciler dispatches this again if the replaced subscription renews at
 * Dodo anyway, so a cancellation that never took is retried, not forgotten.
 */
class CancelReplacedSubscription implements ShouldQueue
{
    use Queueable;

    public array $backoff = [60, 300, 900];

    public int $tries = 4;

    public function __construct(private readonly int $subscriptionId) {}

    /**
     * Every retry spent and Dodo is still holding a subscription the customer
     * replaced: they will be charged for it at the next renewal. Critical, for
     * the same reason ReconcileWebhookEvent's failure is.
     */
    public function failed(Throwable $e): void
    {
        Log::critical('A subscription replaced by a lifetime purchase could not be cancelled at Dodo.', [
            'subscription_id' => $this->subscriptionId,
            'error' => $e->getMessage(),
        ]);
    }

    public function handle(PaymentGatewayContract $gateway): void
    {
        $subscription = Subscription::withoutWorkspaceScope()->find($this->subscriptionId);

        // Deleted, or never held at Dodo: nothing of theirs to stop.
        if ($subscription === null || ! $subscription->isHeldWithProvider()) {
            return;
        }

        // CancellationFailed propagates, so the queue retries it.
        $gateway->cancelSubscription($subscription);
    }
}
