<?php

namespace App\Exceptions\Domain;

use App\Models\Workspace;

/**
 * Section 10: "Extend a trial." Only a trial can be extended.
 *
 * Once the hourly converter has charged the trial (section 4), the workspace is
 * on a paid plan and adding days to `trial_ends_at` would move nothing - the
 * honest answer for a customer who needs more time at that point is a comp,
 * not a longer trial.
 */
class TrialNotExtendable extends DomainException
{
    public function __construct(public readonly Workspace $workspace)
    {
        parent::__construct("Workspace {$workspace->id} has no trial to extend.");
    }

    public function userMessage(): string
    {
        return 'This workspace is not on a trial, so there is nothing to extend.';
    }
}
