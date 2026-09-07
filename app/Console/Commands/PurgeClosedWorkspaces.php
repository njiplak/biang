<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Section 6: "Deleted workspaces are recoverable for 30 days (configurable),
 * then anonymised rather than hard-deleted, so revenue history survives."
 *
 * Anonymising, not deleting, is the whole point: invoice_summaries and the
 * subscription history stay attached to a workspace row that no longer
 * identifies anyone.
 *
 * Section 13 still lists confirming that anonymising is legally acceptable to
 * us as an open question, so what is scrubbed here is deliberately the
 * identifying fields only - widen it once that is settled.
 */
class PurgeClosedWorkspaces extends Command
{
    protected $signature = 'workspaces:purge';

    protected $description = 'Anonymise closed workspaces whose retention window has passed';

    public function handle(): int
    {
        $purged = 0;

        $due = Workspace::onlyTrashed()
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->whereNull('anonymized_at')
            ->get();

        foreach ($due as $workspace) {
            DB::transaction(function () use ($workspace) {
                $workspace->forceFill([
                    'name' => 'Closed workspace',
                    // the slug is public, so it must stop identifying anyone
                    'slug' => 'closed-'.$workspace->ulid,
                    'settings' => null,
                    'suspension_reason' => null,
                    'anonymized_at' => now(),
                ])->save();

                // Membership is personal data with no further purpose; the
                // revenue history in invoice_summaries is untouched.
                $workspace->members()->delete();
                $workspace->invitations()->delete();
            });

            $purged++;
        }

        $this->info("Workspaces anonymised: {$purged}");

        return self::SUCCESS;
    }
}
