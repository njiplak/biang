<?php

namespace App\Enums;

/**
 * The billing axis of spec section 6. Moved by Dodo webhooks and by staff grants.
 * `Free` is the only value with no matching subscriptions row - a free workspace
 * does not exist in Dodo at all (section 8).
 */
enum BillingStatus: string
{
    case Free = 'free';
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';

    public function isPaying(): bool
    {
        return in_array($this, [self::Active, self::PastDue], true);
    }

    /** Section 9: past due keeps full access on purpose. */
    public function allowsFullPlanFeatures(): bool
    {
        return in_array($this, [self::Trialing, self::Active, self::PastDue], true);
    }
}
