<?php

namespace App\Support;

/**
 * Well-known feature keys.
 *
 * Spec section 13.1 leaves the headline value metric open, and this class is
 * deliberately not that decision - seats are needed regardless, because
 * section 7's invite flow has to count them. When the value metric is chosen it
 * becomes a row in `features` like any other; only keys the CODE must name by
 * hand belong here.
 */
final class Features
{
    public const SEATS = 'seats';

    /**
     * Feature keys the application actually MEASURES - that is, keys something
     * calls setGauge/increment/record for.
     *
     * Section 13.1 leaves the value metric open, and the plan table carries
     * limits for candidate metrics that nothing meters yet. A limit on an
     * unmeasured key can never be breached: usage stays at zero, evaluate()
     * never flags it, and the plan reads as enforced when it is decorative.
     *
     * Keeping the honest list here means the catalogue screen can say so, and
     * adding a metric is: meter it, then add its key to this list.
     */
    public const MEASURED = [
        self::SEATS,
    ];

    public static function isMeasured(string $key): bool
    {
        return in_array($key, self::MEASURED, true);
    }
}
