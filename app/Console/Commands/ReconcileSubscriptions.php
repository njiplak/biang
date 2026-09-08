<?php

namespace App\Console\Commands;

use App\Contract\Billing\SubscriptionPullerContract;
use App\Enums\PullOutcome;
use App\Exceptions\Domain\ProviderLookupFailed;
use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * Asks Dodo about every subscription we are still granting access for.
 *
 * Section 16's known risk is a state change we hear about late or not at all:
 * "A customer cancels on the provider's page. Our records find out via
 * notification, not immediately." The webhook covers the ordinary case. This
 * covers the cases where the notification never arrives - a signature rejected
 * at the door, a reconcile that threw, a delivery lost - all of which leave our
 * records saying exactly what they say when nothing happened.
 *
 * That matters more with a merchant of record than it would with a payment
 * processor: section 8 puts cancellation on their page as well as ours, so the
 * events we are most likely to miss are the ones that should REMOVE access.
 *
 * Only live subscriptions are checked, and that is the point - they are the
 * ones whose access is still being granted on the strength of a payment we
 * believe is good.
 */
class ReconcileSubscriptions extends Command
{
    protected $signature = 'billing:reconcile-subscriptions {--limit=500 : Most subscriptions to check in one run}';

    protected $description = 'Ask the payment provider about every live subscription and reconcile any drift';

    public function handle(SubscriptionPullerContract $puller): int
    {
        $counts = [
            PullOutcome::Applied->value => 0,
            PullOutcome::InSync->value => 0,
            PullOutcome::NotFound->value => 0,
            PullOutcome::Mismatched->value => 0,
        ];

        $unreachable = 0;

        $due = Subscription::withoutWorkspaceScope()
            ->live()
            // A comped subscription has no counterpart to ask about, and
            // section 14 phase 3 keeps those working with no provider at all.
            ->whereNotNull('dodo_subscription_id')
            // Oldest-checked first, so a run that hits the limit makes progress
            // through the whole set rather than re-checking the same head.
            ->orderBy('provider_event_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($due as $subscription) {
            try {
                $outcome = $puller->pull($subscription->dodo_subscription_id);
            } catch (ProviderLookupFailed $e) {
                /*
                 * One unreachable subscription must not end the run. Reported
                 * rather than thrown: the next one may well answer, and a run
                 * that stops at the first timeout leaves every subscription
                 * after it unchecked for another hour.
                 */
                $unreachable++;
                $this->warn("{$subscription->dodo_subscription_id}: {$e->getMessage()}");

                continue;
            }

            $counts[$outcome->value]++;

            if ($outcome === PullOutcome::Applied) {
                $this->line("{$subscription->dodo_subscription_id}: reconciled");
            }
        }

        $this->info(sprintf(
            'Checked %d. Reconciled: %d. Already in step: %d. Unmatched: %d. Unreachable: %d.',
            $due->count(),
            $counts[PullOutcome::Applied->value],
            $counts[PullOutcome::InSync->value],
            $counts[PullOutcome::NotFound->value] + $counts[PullOutcome::Mismatched->value],
            $unreachable,
        ));

        return self::SUCCESS;
    }
}
