<?php

namespace App\Exceptions\Domain;

/**
 * There is no free tier, but there is still a floor: a workspace whose
 * subscription has ended keeps its data and keeps it readable, and something
 * has to answer "what may it do" while nobody is paying.
 *
 * That is the floor plan - one row, not public, not sellable, marked by
 * `plans.is_free`. Without exactly one there is nowhere for a cancelled
 * workspace to land and entitlements cannot resolve at all, so this is a
 * configuration failure rather than a user error.
 */
class NoFloorPlanConfigured extends DomainException
{
    public function __construct()
    {
        parent::__construct('No floor plan is configured; entitlements cannot be resolved.');
    }

    public function userMessage(): string
    {
        return 'Billing is not configured yet. Please contact support.';
    }
}
