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
}
