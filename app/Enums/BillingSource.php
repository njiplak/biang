<?php

namespace App\Enums;

/**
 * Why this exists: some subscriptions permanently have no Dodo counterpart -
 * a comped account granted by sales has no payment behind it (section 8), and
 * section 10 requires staff to grant plans by hand forever.
 *
 * Without this column, a null dodo_subscription_id is ambiguous between
 * "correctly comped" and "sync is broken". With it, `source = dodo AND
 * dodo_subscription_id IS NULL` is a monitorable integrity alarm.
 */
enum BillingSource: string
{
    case Dodo = 'dodo';
    case Manual = 'manual';

    public function requiresProviderId(): bool
    {
        return $this === self::Dodo;
    }
}
