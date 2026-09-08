<?php

namespace App\Console\Commands;

use App\Contract\Billing\BillingNotifierContract;
use App\Contract\Billing\SubscriptionContract;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Notifications\Billing\TrialConvertedNotification;
use App\Notifications\Billing\TrialEndedUnpaidNotification;
use Illuminate\Console\Command;

/**
 * What happens to a trial whose fourteen days are up.
 *
 * Section 4 says it "charges automatically and becomes a normal paid
 * subscription", and since the card is now collected up front (section 4 again:
 * "A card is required to start"), DODO does that charging. Their
 * `subscription.renewed` webhook is what converts a real trial, not this
 * command - so a card-backed trial is deliberately left alone here.
 *
 * What is left for this command is the trial that has NO card behind it: one
 * granted by hand from the admin console (section 16: "Staff can grant one by
 * hand"). There is nothing to charge, so it cannot become a paid subscription -
 * it goes read-only, exactly as section 4 says a cancelled trial does.
 *
 * This is the leak it used to be. Every ended trial was converted to Active
 * with no payment behind it at all, which handed out the paid product for free
 * and made section 15's trial-to-paid conversion rate meaningless.
 */
class ConvertEndedTrials extends Command
{
    protected $signature = 'billing:convert-trials';

    protected $description = 'Make ended trials read-only when they have no card behind them';

    public function handle(SubscriptionContract $subscriptions, BillingNotifierContract $notifier): int
    {
        $ended = 0;

        $due = Subscription::withoutWorkspaceScope()
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            /*
             * The card-backed ones are Dodo's to convert. Leaving them trialing
             * until their webhook arrives is the safe direction to be wrong in:
             * a late webhook costs us a few hours of access, where converting
             * here would either double-charge or grant paid access for free
             * depending on which way the race went.
             */
            ->whereNull('dodo_subscription_id')
            ->with('workspace', 'plan')
            ->get();

        foreach ($due as $subscription) {
            if ($subscription->workspace === null) {
                continue;
            }

            // Section 4: "If they cancel during the trial, they are not charged
            // and the workspace goes read-only." A trial with no card
            // reaches the same place by the same logic - nothing to charge.
            $subscriptions->cancel($subscription->workspace);
            $ended++;

            $notifier->sendOnce(
                $subscription->workspace,
                'trial_ended_unpaid',
                "trial_ended_unpaid:sub_{$subscription->id}",
                fn () => new TrialEndedUnpaidNotification(
                    $subscription->workspace,
                    $subscription->plan->name,
                ),
            );
        }

        $confirmed = $this->confirmProviderConversions($notifier);

        $this->info("Trials ended without a card: {$ended}. Conversions confirmed: {$confirmed}.");

        return self::SUCCESS;
    }

    /**
     * Tell the customer their trial actually converted.
     *
     * Section 4 has us warn twice that the card WILL be charged, and section 16
     * is blunt about why: "Auto-charging trials generate disputes if warning
     * emails fail." Warning someone twice and then saying nothing when it
     * happens is the same failure wearing a different hat.
     *
     * Sent from here rather than from DodoReconciler because that runs every
     * webhook inside one transaction - an email sent from in there is already
     * gone if the transaction rolls back. Dedupe on the subscription means the
     * hourly re-run cannot repeat it.
     */
    private function confirmProviderConversions(BillingNotifierContract $notifier): int
    {
        $converted = Subscription::withoutWorkspaceScope()
            ->where('status', SubscriptionStatus::Active)
            // A trial that paid: both set is a shape nothing else produces,
            // which is the same rule section 15 counts conversions by.
            ->whereNotNull('trial_ends_at')
            ->whereNotNull('dodo_subscription_id')
            ->where('trial_ends_at', '<=', now())
            /*
             * Bounded, or this becomes an hourly scan over every trial that has
             * ever converted - growing forever, finding nothing, because the
             * dedupe key already stopped the email. A week is far longer than
             * the gap between the charge and the next run of this command.
             */
            ->where('trial_ends_at', '>=', now()->subWeek())
            ->with('workspace', 'plan')
            ->get();

        $sent = 0;

        foreach ($converted as $subscription) {
            if ($subscription->workspace === null) {
                continue;
            }

            $sent += (int) $notifier->sendOnce(
                $subscription->workspace,
                'trial_converted',
                "trial_converted:sub_{$subscription->id}",
                fn () => new TrialConvertedNotification(
                    $subscription->workspace,
                    $subscription->plan->name,
                ),
            );
        }

        return $sent;
    }
}
