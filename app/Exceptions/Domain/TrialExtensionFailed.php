<?php

namespace App\Exceptions\Domain;

use Throwable;

/**
 * We could not move the trial's billing date at Dodo, so the extension did not
 * happen.
 *
 * This BLOCKS the extension rather than letting it half-succeed, for the same
 * reason CancellationFailed does. Dodo owns the billing clock (section 8), so
 * writing a later `trial_ends_at` on our side while their `next_billing_date`
 * stays put does not buy the customer a single extra day - it only stops us
 * warning them before the charge they were promised would not come yet.
 *
 * Sales told somebody they had longer. Refusing here means sales finds out
 * immediately and can comp the plan instead; succeeding locally means the
 * customer finds out from a bank statement, and with a merchant of record that
 * is a chargeback rather than a support ticket.
 */
class TrialExtensionFailed extends DomainException
{
    public function __construct(private readonly string $why, ?Throwable $previous = null)
    {
        parent::__construct("Trial extension failed: {$why}.", previous: $previous);
    }

    public function userMessage(): string
    {
        return "We could not move this trial's billing date at the payment provider ({$this->why}). The trial is unchanged and still ends when it did — try again shortly, or grant a plan instead.";
    }
}
