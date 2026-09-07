<?php

namespace App\Console\Commands;

use App\Contract\Billing\BillingNotifierContract;
use App\Contract\Billing\SubscriptionContract;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Notifications\Billing\TrialConvertedNotification;
use Illuminate\Console\Command;

/**
 * Section 4: "At the end of day 14 it charges automatically and becomes a
 * normal paid subscription."
 *
 * No money moves here yet - phase 4 hands the charge to Dodo. What this does
 * today is move OUR state, which section 8 makes the source of truth for access.
 */
class ConvertEndedTrials extends Command
{
    protected $signature = 'billing:convert-trials';

    protected $description = 'Convert trials whose end date has passed into paid subscriptions';

    public function handle(SubscriptionContract $subscriptions, BillingNotifierContract $notifier): int
    {
        $converted = 0;

        $due = Subscription::withoutWorkspaceScope()
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', now())
            ->with('workspace', 'plan')
            ->get();

        foreach ($due as $subscription) {
            if ($subscription->workspace === null) {
                continue;
            }

            $subscriptions->convertTrial($subscription);
            $converted++;

            $notifier->sendOnce(
                $subscription->workspace,
                'trial_converted',
                "trial_converted:sub_{$subscription->id}",
                fn () => new TrialConvertedNotification($subscription->workspace, $subscription->plan->name),
            );
        }

        $this->info("Trials converted: {$converted}");

        return self::SUCCESS;
    }
}
