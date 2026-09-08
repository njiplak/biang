<?php

namespace App\Enums;

enum ResetPeriod: string
{
    case None = 'none';
    case BillingPeriod = 'billing_period';

    /**
     * Free workspaces have no subscription and therefore no billing period,
     * so any metered feature reachable without a subscription must use this.
     */
    case CalendarMonth = 'calendar_month';
}
