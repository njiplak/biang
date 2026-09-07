<?php

namespace App\Exceptions\Domain;

use Throwable;

/**
 * Publishing a price to Dodo failed, so nothing can be bought at that price yet.
 *
 * Section 10 puts the catalogue in staff hands "without an engineer", and
 * section 14 phase 3 keeps the product sellable by hand with no provider wired
 * up at all. So this is never fatal to creating a price: the price exists in
 * our catalogue, it simply is not on sale until publishing succeeds, and the
 * admin console says so and offers to retry.
 */
class ProductPublishFailed extends DomainException
{
    public function __construct(private readonly string $why, ?Throwable $previous = null)
    {
        parent::__construct("Could not publish to the payment provider: {$why}.", previous: $previous);
    }

    public function userMessage(): string
    {
        return "This price is saved, but we could not publish it to the payment provider ({$this->why}), so nobody can buy it yet. Try publishing it again from the catalogue.";
    }
}
