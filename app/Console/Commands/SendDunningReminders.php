<?php

namespace App\Console\Commands;

use App\Contract\Billing\BillingNotifierContract;
use App\Models\DunningState;
use App\Notifications\Billing\PaymentFailedNotification;
use App\Notifications\Billing\PaymentFailedReminderNotification;
use Illuminate\Console\Command;

/**
 * Section 9's first two rows: "Renewal fails → Email + banner", then "Still
 * failing → Reminder emails escalate in tone."
 *
 * Driven from `dunning_states` rather than from the webhook that opened the
 * episode, for two reasons. Dodo retries a failed renewal several times and
 * each retry is its own notification, so sending from the webhook would email
 * the customer once per attempt. And DodoReconciler runs everything inside one
 * transaction: an email sent from in there is already gone if the transaction
 * later rolls back, and no longer matches what we recorded.
 *
 * The cost of that choice is that the first email waits for the next run, which
 * is why this is scheduled hourly and not daily. The banner is instant either
 * way - it reads the same state this does.
 */
class SendDunningReminders extends Command
{
    protected $signature = 'billing:dunning-reminders';

    protected $description = 'Tell a past-due workspace its payment failed, then escalate while it keeps failing';

    /**
     * Days since the episode opened. Against the default 14-day grace window
     * that is a warning at the start, one a fifth of the way in, and one at the
     * halfway point, each naming the date access changes.
     */
    private const REMINDER_DAYS = [3, 7];

    public function handle(BillingNotifierContract $notifier): int
    {
        $sent = 0;

        // No withoutWorkspaceScope: nothing has set a current workspace in a
        // scheduled command, so the tenancy scope is inert here - the same way
        // ExpireDunningGrace reads this table.
        $episodes = DunningState::query()->open()->with('workspace')->get();

        foreach ($episodes as $episode) {
            $workspace = $episode->workspace;

            // A workspace deleted mid-episode. The cascade will take the row;
            // there is nobody left to email in the meantime.
            if ($workspace === null) {
                continue;
            }

            /*
             * Keyed to the EPISODE, not the run or the attempt. Dodo retries a
             * single failed renewal several times, and section 9 promises one
             * "your payment failed", not one per retry.
             */
            $firstEmailJustSent = $notifier->sendOnce(
                $workspace,
                'payment_failed',
                "payment_failed:dunning_{$episode->id}",
                fn () => new PaymentFailedNotification($workspace, $episode->grace_ends_at),
            );

            if ($firstEmailJustSent) {
                // Reminders start from the next run. Without this, an episode
                // that is already days old the first time this command sees it
                // would deliver the warning and both escalations at once.
                $sent++;

                continue;
            }

            /*
             * Grace already spent. ExpireDunningGrace owns what the customer
             * hears from here, and it says the workspace is now read-only - a
             * reminder promising them time they no longer have would contradict
             * an email they may have already opened.
             */
            if ($episode->grace_ends_at->isPast()) {
                continue;
            }

            foreach (self::REMINDER_DAYS as $day) {
                if ($episode->started_at->copy()->addDays($day)->isFuture()) {
                    continue;
                }

                $sent += (int) $notifier->sendOnce(
                    $workspace,
                    "payment_failed_reminder_{$day}d",
                    "payment_failed_reminder:dunning_{$episode->id}:day_{$day}",
                    fn () => new PaymentFailedReminderNotification($workspace, $episode->grace_ends_at),
                );
            }
        }

        $this->info("Dunning reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
