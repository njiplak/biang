<?php

namespace App\Exceptions\Domain;

use Throwable;

/**
 * We asked Dodo about something and did not get an answer.
 *
 * A DomainException rather than a crash because every caller has somewhere
 * sensible to go without one: the billing page renders from OUR records and has
 * to work when Dodo is down (section 8 makes ours authoritative for access), and
 * the drift check moves on to the next subscription.
 */
class ProviderLookupFailed extends DomainException
{
    public function __construct(private readonly string $why, ?Throwable $previous = null)
    {
        parent::__construct("Provider lookup failed: {$why}.", previous: $previous);
    }

    public function userMessage(): string
    {
        return 'We could not reach the payment provider just now. Your billing details will catch up shortly.';
    }
}
