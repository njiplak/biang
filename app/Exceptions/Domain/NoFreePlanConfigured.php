<?php

namespace App\Exceptions\Domain;

/**
 * Section 6: cancelling drops a workspace to the free tier, and section 12 says
 * the free tier always exists. Without exactly one free plan there is nowhere
 * for a workspace to land, so this is a configuration failure, not a user error.
 */
class NoFreePlanConfigured extends DomainException
{
    public function __construct()
    {
        parent::__construct('No free plan is configured; entitlements cannot be resolved.');
    }

    public function userMessage(): string
    {
        return 'Billing is not configured yet. Please contact support.';
    }
}
