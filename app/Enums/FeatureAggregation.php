<?php

namespace App\Enums;

enum FeatureAggregation: string
{
    /** A current level that goes up and down. Seats, storage. No period. */
    case Gauge = 'gauge';

    /** Accumulates across a period and resets. API calls, emails sent. */
    case Counter = 'counter';

    public function usesPeriod(): bool
    {
        return $this === self::Counter;
    }
}
