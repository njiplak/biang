<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    |
    | Which price row to reach for when the customer has not picked one - the
    | marketing site's "Start trial" button carries a PLAN, not a price, and a
    | plan may be sold in several currencies.
    |
    | Spec section 8 makes Dodo the merchant of record, so they own tax and
    | conversion. This is only about which of our own rows to select.
    |
    */

    'default_currency' => env('BILLING_DEFAULT_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Free trial length
    |--------------------------------------------------------------------------
    |
    | Free days before the first charge on a monthly price. 0 switches trials
    | off, and every price is then bought outright at checkout. Anything else
    | must be at least 4, or a trial can end before its 3-day warning email.
    |
    | Read by new checkouts only. A trial already running keeps the charge date
    | Dodo was given when it started.
    |
    */

    'trial_days' => env('BILLING_TRIAL_DAYS', 7),

];
