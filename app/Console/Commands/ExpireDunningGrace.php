<?php

namespace App\Console\Commands;

use App\Contract\Billing\BillingNotifierContract;
use App\Enums\AccessStatus;
use App\Enums\DunningResolution;
use App\Models\DunningState;
use App\Notifications\Billing\GracePeriodEndedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Section 9's last row: "Grace period ends → Workspace goes read-only. Email
 * explains exactly why. Billing stops."
 *
 * Note what does NOT happen: nothing is deleted and login still works. Section
 * 6 is deliberate about this - "a customer whose card expired is not a customer
 * who left", and locking them out is how a card problem becomes a cancellation.
 */
class ExpireDunningGrace extends Command
{
    protected $signature = 'billing:expire-grace';

    protected $description = 'Move workspaces to read-only when their payment grace period ends';

    public function handle(BillingNotifierContract $notifier): int
    {
        $expired = 0;

        $episodes = DunningState::query()
            ->whereNull('resolved_at')
            ->where('grace_ends_at', '<=', now())
            ->with('workspace')
            ->get();

        foreach ($episodes as $episode) {
            $workspace = $episode->workspace;

            if ($workspace === null) {
                continue;
            }

            DB::transaction(function () use ($episode, $workspace) {
                $workspace->update([
                    'access_status' => AccessStatus::Suspended,
                    'suspended_at' => now(),
                    'suspension_reason' => 'Payment could not be taken within the grace period.',
                ]);

                $episode->update([
                    'resolved_at' => now(),
                    'resolution' => DunningResolution::Suspended,
                ]);
            });

            $notifier->sendOnce(
                $workspace,
                'grace_ended',
                "grace_ended:dunning_{$episode->id}",
                fn () => new GracePeriodEndedNotification($workspace),
            );

            $expired++;
        }

        $this->info("Grace periods expired: {$expired}");

        return self::SUCCESS;
    }
}
