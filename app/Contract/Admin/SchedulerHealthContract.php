<?php

namespace App\Contract\Admin;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Section 16 calls the trial-ending emails a launch blocker. They are sent by a
 * scheduled command, and a scheduler that has silently stopped looks exactly
 * like one with nothing to do.
 */
interface SchedulerHealthContract
{
    public function overview(): array;

    /**
     * The same tasks, paginated for the table.
     *
     * The list is small and fixed - one row per monitored task - but it goes
     * through the same envelope as every other admin table so the screens
     * behave identically, which is the point of them sharing a component.
     */
    public function paginate(?string $search, int $perPage): LengthAwarePaginator;
}
