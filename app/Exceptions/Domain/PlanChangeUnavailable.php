<?php

namespace App\Exceptions\Domain;

use Throwable;

/**
 * We could not carry a plan change through to Dodo, so the money side of it did
 * not happen.
 *
 * Section 7 turned OFF the provider's own plan switcher, because a downgrade
 * made outside our app could not be blocked. The cost of that decision is that
 * when this fails there is no other door - so it has to say so plainly rather
 * than leave the customer believing they changed plan.
 *
 * Distinct from CheckoutUnavailable because the customer is already paying: the
 * useful instruction is "your current plan is unchanged", not "go and buy".
 */
class PlanChangeUnavailable extends DomainException
{
    public function __construct(private readonly string $why, ?Throwable $previous = null)
    {
        parent::__construct("Plan change unavailable: {$why}.", previous: $previous);
    }

    public function userMessage(): string
    {
        return "We could not change your plan right now ({$this->why}). Nothing has changed and you have not been charged — please try again shortly, or contact us.";
    }
}
