<?php

namespace App\Service\Billing;

use App\Contract\Billing\BillingNotifierContract;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Section 16 is blunt about this: "Trial-ending emails are a launch blocker,
 * not a nice-to-have", because the trial auto-charges and a missed or repeated
 * warning becomes a chargeback rather than a support ticket.
 *
 * So the guarantee here is at-most-once, enforced by a unique index rather than
 * by remembering to check - a re-run of the scheduler, a queue retry, or two
 * workers racing all land on the same key.
 */
class BillingNotifier implements BillingNotifierContract
{
    public function sendOnce(Workspace $workspace, string $type, string $dedupeKey, Closure $notification): bool
    {
        $recipients = $this->recipients($workspace);

        if ($recipients->isEmpty()) {
            return false;
        }

        // Claim the key FIRST. If another worker already has it the unique
        // index rejects this insert and we send nothing - which is the right
        // way round: a missed duplicate beats a duplicate charge warning.
        try {
            DB::transaction(fn () => NotificationLog::create([
                'workspace_id' => $workspace->id,
                'type' => $type,
                'dedupe_key' => $dedupeKey,
                'channel' => 'mail',
                'sent_at' => now(),
            ]));
        } catch (QueryException) {
            return false;
        }

        Notification::send($recipients, $notification());

        return true;
    }

    /**
     * Section 9: the owner and any billing manager, never regular members -
     * "they should not learn about their company's card problems from us".
     *
     * @return Collection<int, User>
     */
    private function recipients(Workspace $workspace): Collection
    {
        return $workspace->members()->with('user')->get()
            ->filter(fn (WorkspaceMember $member) => $member->role->receivesBillingNotifications())
            ->map(fn (WorkspaceMember $member) => $member->user)
            ->filter()
            ->values();
    }
}
