<?php

namespace App\Contract\Admin;

use App\Models\Announcement;
use App\Models\User;
use App\Models\Workspace;

/** Section 10: "Talk to everyone. Announce maintenance or a new feature." */
interface AnnouncementContract
{
    public function all(): array;

    public function create(array $attributes): Announcement;

    public function update(Announcement $announcement, array $attributes): Announcement;

    public function publish(Announcement $announcement): Announcement;

    public function unpublish(Announcement $announcement): Announcement;

    public function delete(Announcement $announcement): void;

    /** Live, targeted at this workspace, and not already dismissed by this person. */
    public function forUser(User $user, ?Workspace $workspace): array;

    public function dismiss(Announcement $announcement, User $user): void;
}
