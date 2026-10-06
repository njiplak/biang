<?php

namespace App\Console\Commands;

use App\Contract\Billing\BillingNotifierContract;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Notifications\Billing\TrialEndingNotification;
use Illuminate\Console\Command;

/**
 * Section 16 calls this a launch blocker, not a nice-to-have: the trial
 * auto-charges, so a customer who is not warned experiences it as an
 * unexpected charge - and with a merchant of record that is a formal
 * chargeback, not a quiet refund.
 */
class SendTrialWarnings extends Command
{
    protected $signature = 'billing:trial-warnings';

    protected $description = 'Warn workspaces whose trial auto-charges in 7 days, 3 days or tomorrow';

    /**
     * Section 4 asks for 3 days and 1 day. Seven is ours, and it is the one
     * with evidence behind it: a reminder roughly a week before a trial
     * converts is the single measured lever on trial-related chargebacks.
     * Three days is short notice for a team that has to get a purchase
     * approved, and the person who cannot approve it in time disputes the
     * charge instead of asking us.
     */
    private const MILESTONES = [
        7 => 'trial_ending_7d',
        3 => 'trial_ending_3d',
        1 => 'trial_ending_1d',
    ];

    public function handle(BillingNotifierContract $notifier): int
    {
        $sent = 0;

        foreach (self::MILESTONES as $days => $type) {
            $subscriptions = Subscription::withoutWorkspaceScope()
                ->where('status', SubscriptionStatus::Trialing)
                ->whereNotNull('trial_ends_at')
                ->whereDate('trial_ends_at', today()->addDays($days))
                // Already cancelled: nothing will be charged, so a warning that
                // says it will be is the one email we must not send.
                ->where('cancel_at_period_end', false)
                ->with('workspace')
                ->get();

            foreach ($subscriptions as $subscription) {
                if ($subscription->workspace === null) {
                    continue;
                }

                /*
                 * The key is the milestone AND the date it is counting down to.
                 *
                 * The milestone alone is what makes a second run today, or a
                 * retry tomorrow, resolve to "already sent". The date is what
                 * re-arms every milestone when staff extend the trial: the
                 * charge moves to a new day, and the customer is owed the same
                 * warnings before that one. Keyed on the milestone alone, a
                 * customer whose trial was extended past its 3-day warning was
                 * never warned again before the charge that actually happened.
                 */
                $sent += (int) $notifier->sendOnce(
                    $subscription->workspace,
                    $type,
                    "{$type}:sub_{$subscription->id}:{$subscription->trial_ends_at->toDateString()}",
                    fn () => new TrialEndingNotification($subscription->workspace, $days),
                );
            }
        }

        $this->info("Trial warnings sent: {$sent}");

        return self::SUCCESS;
    }
}
