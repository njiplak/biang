<?php

namespace App\Exceptions\Domain;

use App\Models\Workspace;

/**
 * The customer's cancel button, pressed on a lifetime plan.
 *
 * There is no renewal to stop, so "cancel" could only mean throwing away what
 * was paid for - with no refund and no way back short of buying it again.
 * Closing the workspace still ends it; that path does not come through here.
 */
class LifetimeNotCancellable extends DomainException
{
    public function __construct(public readonly Workspace $workspace)
    {
        parent::__construct("Workspace {$workspace->id} holds a lifetime plan, which has nothing to cancel.");
    }

    public function userMessage(): string
    {
        return 'A lifetime plan never renews, so there is nothing to cancel and you will not be charged again.';
    }
}
