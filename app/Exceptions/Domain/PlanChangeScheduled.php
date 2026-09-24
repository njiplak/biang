<?php

namespace App\Exceptions\Domain;

use Carbon\CarbonInterface;

/**
 * Dodo refuses changes to a subscription that has a plan change scheduled
 * unless the request replaces that schedule. Changing add-ons would therefore
 * either fail at their end or silently drop the scheduled downgrade, so we
 * refuse first and say what to do.
 */
class PlanChangeScheduled extends DomainException
{
    public function __construct(
        private readonly string $plan,
        private readonly ?CarbonInterface $effectiveAt,
    ) {
        parent::__construct("A switch to {$plan} is already scheduled.");
    }

    public function userMessage(): string
    {
        $when = $this->effectiveAt?->toFormattedDayDateString() ?? 'your next renewal';

        return "A switch to {$this->plan} is scheduled for {$when}. Keep your current plan first, then change add-ons.";
    }
}
