<?php

namespace App\Contract\Admin;

/**
 * Section 16 calls the trial-ending emails a launch blocker. They are sent by a
 * scheduled command, and a scheduler that has silently stopped looks exactly
 * like one with nothing to do.
 */
interface SchedulerHealthContract
{
    public function overview(): array;
}
