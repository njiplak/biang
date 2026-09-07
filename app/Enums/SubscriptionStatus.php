<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Expired = 'expired';

    /** Exactly one subscription per workspace may be in one of these. */
    public static function live(): array
    {
        return [self::Trialing, self::Active, self::PastDue];
    }

    public function isLive(): bool
    {
        return in_array($this, self::live(), true);
    }

    public function toBillingStatus(): BillingStatus
    {
        return match ($this) {
            self::Trialing => BillingStatus::Trialing,
            self::Active => BillingStatus::Active,
            self::PastDue => BillingStatus::PastDue,
            self::Canceled, self::Expired => BillingStatus::Canceled,
        };
    }
}
