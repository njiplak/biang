<?php

namespace App\Enums;

/**
 * The billing axis of spec section 6. Moved by Dodo webhooks and by staff grants.
 *
 * `Unpaid` is the only value with no matching subscriptions row, and it covers
 * both ends of the relationship: a workspace that has not bought yet, and one
 * whose subscription has ended. There is no free tier, so neither can write -
 * what they keep is everything they already have, readable, indefinitely.
 */
enum BillingStatus: string
{
    /*
     * Was `free`, when a perpetual free tier was a product. It is not one any
     * more: every plan carries a price and a card is taken up front, so a
     * workspace in this state is one nobody is paying for rather than one on a
     * plan that happens to cost nothing.
     */
    case Unpaid = 'unpaid';
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
