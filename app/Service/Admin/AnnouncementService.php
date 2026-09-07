<?php

namespace App\Service\Admin;

use App\Contract\Admin\AnnouncementContract;
use App\Models\Announcement;
use App\Models\AnnouncementDismissal;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Section 10: "Talk to everyone. Announce maintenance or a new feature to all
 * customers."
 *
 * Targeting lives in `audience` plus a jsonb `audience_filter` rather than in
 * columns, so a new way to slice the customer base is a change here rather than
 * a migration.
 */
class AnnouncementService implements AnnouncementContract
{
    public function all(): array
    {
        return Announcement::query()
            ->with('createdByAdmin')
            ->withCount('dismissals')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Announcement $announcement) => [
                'id' => $announcement->id,
                'ulid' => $announcement->ulid,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'audience' => $announcement->audience,
                'audience_filter' => $announcement->audience_filter,
                'severity' => $announcement->severity,
                'is_dismissible' => $announcement->is_dismissible,
                'published_at' => $announcement->published_at,
                'expires_at' => $announcement->expires_at,
                // Derived, not stored: `live` is a function of the clock, so a
                // stored flag would go stale the moment it was written.
                'is_live' => $announcement->isLive(),
                'created_by' => $announcement->createdByAdmin?->name,
                'dismissals_count' => $announcement->dismissals_count,
            ])
            ->all();
    }

    public function create(array $attributes): Announcement
    {
        return Announcement::create($attributes);
    }

    public function update(Announcement $announcement, array $attributes): Announcement
    {
        $announcement->update($attributes);

        return $announcement->refresh();
    }

    public function publish(Announcement $announcement): Announcement
    {
        // Publishing an already-published one must not silently move its start
        // time - that would resurrect it for everyone who dismissed it.
        if ($announcement->published_at === null) {
            $announcement->update(['published_at' => now()]);
        }

        return $announcement->refresh();
    }

    public function unpublish(Announcement $announcement): Announcement
    {
        $announcement->update(['published_at' => null]);

        return $announcement->refresh();
    }

    public function delete(Announcement $announcement): void
    {
        // Dismissals cascade with it. An announcement is not a business record
        // the way a subscription is; there is nothing to preserve.
        $announcement->delete();
    }

    /**
     * What this person should see right now, in this workspace.
     *
     * Dismissal is per PERSON, not per workspace: the same human should not be
     * told about the same maintenance window once per workspace in their
     * switcher.
     */
    public function forUser(User $user, ?Workspace $workspace): array
    {
        $dismissed = AnnouncementDismissal::query()
            ->where('user_id', $user->id)
            ->pluck('announcement_id');

        return Announcement::query()
            ->live()
            ->whereNotIn('id', $dismissed)
            ->orderByDesc('published_at')
            ->get()
            ->filter(fn (Announcement $announcement) => $this->matches($announcement, $workspace))
            ->map(fn (Announcement $announcement) => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'severity' => $announcement->severity,
                'is_dismissible' => $announcement->is_dismissible,
            ])
            ->values()
            ->all();
    }

    public function dismiss(Announcement $announcement, User $user): void
    {
        DB::transaction(function () use ($announcement, $user) {
            // A non-dismissible announcement stays put by design - that is what
            // makes it usable for "we are down right now".
            if (! $announcement->is_dismissible) {
                return;
            }

            AnnouncementDismissal::firstOrCreate(
                ['announcement_id' => $announcement->id, 'user_id' => $user->id],
                ['dismissed_at' => now()],
            );
        });
    }

    /**
     * `all` is the common case and needs no workspace at all. The targeted
     * kinds do, so someone between workspaces sees only the universal ones
     * rather than a filter that silently matches nothing.
     */
    private function matches(Announcement $announcement, ?Workspace $workspace): bool
    {
        if ($announcement->audience === 'all') {
            return true;
        }

        if ($workspace === null) {
            return false;
        }

        $filter = $announcement->audience_filter ?? [];

        return match ($announcement->audience) {
            'plan' => in_array(
                $workspace->subscription()->withoutWorkspaceScope()->first()?->plan?->code ?? 'free',
                $filter['plan_codes'] ?? [],
                true,
            ),
            'state' => in_array($workspace->displayState()->value, $filter['states'] ?? [], true),
            default => false,
        };
    }
}
