<?php

namespace App\Exceptions\Domain;

use App\Models\Workspace;

/**
 * Section 12: "A customer on the free tier does not exist in Dodo at all."
 *
 * There is no payment account to attach a paid add-on to, so the answer for a
 * free workspace that hits a limit is an upgrade, not an add-on. Section 7's
 * "sales moment" still applies - it is just a different offer.
 */
class NoActiveSubscription extends DomainException
{
    public function __construct(public readonly Workspace $workspace)
    {
        parent::__construct("Workspace {$workspace->id} has no live subscription.");
    }

    public function userMessage(): string
    {
        return 'Add-ons are available on a paid plan. Choose a plan first.';
    }
}
