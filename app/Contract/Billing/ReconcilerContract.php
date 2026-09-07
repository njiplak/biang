<?php

namespace App\Contract\Billing;

use App\Models\WebhookEvent;

/**
 * Section 8: "Our records are the source of truth for access, theirs for
 * money." This is the seam between the two - it reads a provider notification
 * and moves OUR state, never the other way round.
 */
interface ReconcilerContract
{
    public function reconcile(WebhookEvent $event): void;
}
