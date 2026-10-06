<?php

namespace App\Enums;

enum BillingInterval: string
{
    case Month = 'month';
    case Year = 'year';

    /**
     * Paid once and never renewed. Sold at Dodo as a one-time product, so a
     * lifetime plan has no Dodo subscription behind it at all.
     */
    case Lifetime = 'lifetime';

    /** Null for lifetime: there is no period to count months in. */
    public function months(): ?int
    {
        return match ($this) {
            self::Month => 1,
            self::Year => 12,
            self::Lifetime => null,
        };
    }

    public function isRecurring(): bool
    {
        return $this !== self::Lifetime;
    }
}
