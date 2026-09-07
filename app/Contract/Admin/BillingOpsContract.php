<?php

namespace App\Contract\Admin;

use App\Models\WebhookEvent;

/**
 * Section 8's operational half: what the payment integration recorded when
 * something went wrong, and the one action that can be taken about it.
 */
interface BillingOpsContract
{
    public function overview(): array;

    public function retry(WebhookEvent $event): void;
}
