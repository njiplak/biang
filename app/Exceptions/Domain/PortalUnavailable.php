<?php

namespace App\Exceptions\Domain;

use Throwable;

/**
 * Section 5: the billing page offers a way to "open the payment provider's page
 * for cards and invoices". Section 8 puts both of those on Dodo's side of the
 * line, so when we cannot open that page there is nothing of our own to fall
 * back to - the customer has to be told, not shown an error page.
 *
 * Distinct from CheckoutUnavailable because the two are different requests with
 * different answers: one is "I want to buy", this one is "I want to fix the
 * card I already gave you", and telling the second group to contact sales is
 * the wrong instruction.
 */
class PortalUnavailable extends DomainException
{
    public function __construct(private readonly string $why, ?Throwable $previous = null)
    {
        parent::__construct("Payment portal unavailable: {$why}.", previous: $previous);
    }

    public function userMessage(): string
    {
        return "We could not open the payment provider's page right now ({$this->why}). Please try again shortly, or contact us and we will sort the card out with you.";
    }
}
