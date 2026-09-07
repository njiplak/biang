<?php

namespace App\Enums;

/** Spec section 4's three kinds, which are charged differently. */
enum AddonKind: string
{
    /** Per unit, prorated when the quantity changes. Extra seats, extra storage. */
    case Quantity = 'quantity';

    /** Flat recurring fee for a premium capability sold separately. */
    case Unlock = 'unlock';

    /** Billed on actual consumption. */
    case Metered = 'metered';

    public function hasQuantity(): bool
    {
        return $this === self::Quantity;
    }
}
