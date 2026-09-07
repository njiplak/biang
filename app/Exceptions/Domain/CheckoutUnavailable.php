<?php

namespace App\Exceptions\Domain;

use Throwable;

/**
 * Section 14 phase 3: the product is sellable by hand before payments exist, so
 * checkout being unavailable is a normal state, not a crash. Staff can still
 * grant the plan from the admin console.
 */
class CheckoutUnavailable extends DomainException
{
    public function __construct(private readonly string $why, ?Throwable $previous = null)
    {
        parent::__construct("Checkout unavailable: {$why}.", previous: $previous);
    }

    public function userMessage(): string
    {
        return "Card payment is not available right now ({$this->why}). Please contact us and we will set this up by hand.";
    }
}
