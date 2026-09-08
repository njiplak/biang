<?php

namespace App\Enums;

/**
 * What came of asking Dodo about one subscription.
 *
 * Reported rather than swallowed because the caller's next move differs for
 * each: the billing page tells the customer their payment is still settling,
 * and the drift check counts them so a run that fixed nothing is
 * distinguishable from a run that found nothing to fix.
 */
enum PullOutcome: string
{
    /** Their answer moved our records. */
    case Applied = 'applied';

    /** Their answer matched what we already had. */
    case InSync = 'in_sync';

    /** Nothing here to attach their answer to. */
    case NotFound = 'not_found';

    /**
     * The subscription belongs to a different workspace than the one asking.
     *
     * Never an accident worth retrying: the id came off a URL, and the only way
     * to hold one for somebody else's subscription is to have typed it.
     */
    case Mismatched = 'mismatched';
}
