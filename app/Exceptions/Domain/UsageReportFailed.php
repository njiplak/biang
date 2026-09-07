<?php

namespace App\Exceptions\Domain;

use Throwable;

/**
 * A metered event did not reach Dodo, so it has not been billed for.
 *
 * Thrown rather than swallowed on purpose. Section 4 bills metered add-ons on
 * actual consumption, and consumption only exists in our records - if this
 * fails silently the customer is undercharged and nothing anywhere says so.
 * The job that raises it retries, and the unreported rows stay findable.
 */
class UsageReportFailed extends DomainException
{
    public function __construct(private readonly string $why, ?Throwable $previous = null)
    {
        parent::__construct("Could not report usage to the payment provider: {$why}.", previous: $previous);
    }

    public function userMessage(): string
    {
        // Never customer-facing: metered usage is reported by a background job,
        // not by anything a person is waiting on.
        return 'We could not record usage with the payment provider. This has been logged and will be retried.';
    }
}
