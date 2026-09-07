<?php

namespace App\Enums;

enum BillingInterval: string
{
    case Month = 'month';
    case Year = 'year';

    public function months(): int
    {
        return match ($this) {
            self::Month => 1,
            self::Year => 12,
        };
    }
}
