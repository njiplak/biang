<?php

namespace App\Exceptions\Domain;

use Throwable;

/**
 * We could not tell Dodo to stop charging, so the cancellation did not happen.
 *
 * This deliberately BLOCKS the cancellation rather than letting it half-succeed.
 * A cancellation that moves our records and not theirs is the worst outcome
 * available: the customer loses the paid product and keeps being charged for
 * it, finds out on a statement rather than from us, and - because section 8
 * makes Dodo the merchant of record - their recourse is a chargeback rather
 * than a support ticket.
 *
 * Refusing leaves them subscribed and still receiving what they pay for, which
 * is recoverable by trying again. The other way round is not.
 */
class CancellationFailed extends DomainException
{
    public function __construct(private readonly string $why, ?Throwable $previous = null)
    {
        parent::__construct("Cancellation failed: {$why}.", previous: $previous);
    }

    public function userMessage(): string
    {
        return "We could not cancel your subscription just now ({$this->why}). Nothing has changed and your plan is still active — please try again shortly, or contact us and we will do it for you.";
    }
}
