<?php

namespace App\Contract\Billing;

use App\Enums\PullOutcome;
use App\Models\Workspace;

/**
 * Asking Dodo what is true, instead of waiting to be told.
 *
 * Section 8 treats their records as authoritative for money, and a webhook is
 * only one way of hearing them. It is the way that fails silently: a signature
 * rejected at the door, a delivery that never comes, a reconcile that threw -
 * all three leave our records saying nothing happened, which is exactly what
 * they say when nothing did happen.
 *
 * The result goes through ReconcilerContract like any webhook. One set of
 * rules for out-of-order and repeated events, one audit trail, two transports.
 */
interface SubscriptionPullerContract
{
    /**
     * $expected binds the answer to a workspace. The billing page passes it,
     * because there the subscription id came out of a URL the customer could
     * have typed - and the only thing that makes it theirs is the workspace
     * ulid WE stamped into the checkout metadata.
     *
     * @throws \App\Exceptions\Domain\ProviderLookupFailed when Dodo cannot be reached
     */
    public function pull(string $providerSubscriptionId, ?Workspace $expected = null): PullOutcome;
}
