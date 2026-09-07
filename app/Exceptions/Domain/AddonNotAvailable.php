<?php

namespace App\Exceptions\Domain;

use App\Models\Addon;

/**
 * Section 4 caps paid add-ons per plan, so which add-ons a workspace may buy
 * depends on the plan it is on - and an archived price is history, not an offer.
 */
class AddonNotAvailable extends DomainException
{
    public function __construct(public readonly Addon $addon, string $reason)
    {
        parent::__construct("Add-on {$addon->key} is not available: {$reason}.");
    }

    public function userMessage(): string
    {
        return 'That add-on is not available on your current plan.';
    }
}
