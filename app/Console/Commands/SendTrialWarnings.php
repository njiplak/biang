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

    protected $description = 'Warn workspaces whose trial auto-charges in 3 days or tomorrow';

    /** Section 4: "clear warning emails at 3 days and 1 day before". */
    private const MILESTONES = [3 => 'trial_ending_3d', 1 => 'trial_ending_1d'];

    public function handle(BillingNotifierContract $notifier): int
    {
        $sent = 0;

        foreach (self::MILESTONES as $days => $type) {
            $subscriptions = Subscription::withoutWorkspaceScope()
                ->where('status', SubscriptionStatus::Trialing)
                ->whereNotNull('trial_ends_at')
                ->whereDate('trial_ends_at', today()->addDays($days))
                ->with('workspace')
                ->get();

            foreach ($subscriptions as $subscription) {
                if ($subscription->workspace === null) {
                    continue;
                }

                // The key is the milestone, not the run - so a second run today
                // and a retry tomorrow both resolve to "already sent".
                $sent += (int) $notifier->sendOnce(
                    $subscription->workspace,
                    $type,
                    "{$type}:sub_{$subscription->id}",
                    fn () => new TrialEndingNotification($subscription->workspace, $days),
                );
            }
        }

        $this->info("Trial warnings sent: {$sent}");

        return self::SUCCESS;
    }
}
