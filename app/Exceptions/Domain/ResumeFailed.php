<?php

namespace App\Exceptions\Domain;

use Throwable;

/**
 * We could not tell Dodo to keep renewing, so the scheduled cancellation still
 * stands. Our record is left untouched for the same reason CancellationFailed
 * leaves it: showing "resumed" while Dodo still plans to stop would end the
 * subscription the customer believes they kept.
 */
class ResumeFailed extends DomainException
{
    public function __construct(private readonly string $why, ?Throwable $previous = null)
    {
        parent::__construct("Resume failed: {$why}.", previous: $previous);
    }

    public function userMessage(): string
    {
        return "We could not resume your subscription just now ({$this->why}). It is still set to end on the date shown — please try again shortly, or contact us and we will do it for you.";
    }
}
