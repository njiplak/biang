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

];
