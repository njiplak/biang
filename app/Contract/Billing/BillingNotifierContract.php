<?php

namespace App\Contract\Billing;

use App\Models\Workspace;
use Closure;

interface BillingNotifierContract
{
    /**
     * Send a billing notification to the workspace's billing people, at most
     * once per dedupe key, ever.
     *
     * The notification is built lazily so nothing is constructed when the send
     * is going to be skipped.
     */
    public function sendOnce(Workspace $workspace, string $type, string $dedupeKey, Closure $notification): bool;
}
