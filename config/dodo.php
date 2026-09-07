<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dodo Payments
    |--------------------------------------------------------------------------
    |
    | Spec section 8: Dodo is our payment provider and legally the seller on
    | every transaction. Their records are the source of truth for MONEY; ours
    | are the source of truth for ACCESS. Nothing in this app asks Dodo whether
    | a customer may write - it asks workspace_entitlements.
    |
    | The SDK reads DODO_PAYMENTS_API_KEY and DODO_PAYMENTS_WEBHOOK_KEY from the
    | environment itself, but we pass them explicitly so the values come from
    | config and can be faked in tests.
    |
    */

    'api_key' => env('DODO_PAYMENTS_API_KEY'),

    // Standard Webhooks secret, `whsec_`-prefixed. Without it no event can be
    // verified, and an unverified event must never move billing state.
    'webhook_key' => env('DODO_PAYMENTS_WEBHOOK_KEY'),

    'base_url' => env('DODO_PAYMENTS_BASE_URL', 'https://live.dodopayments.com'),

    /*
    | How long a past-due workspace keeps full access before section 9's
    | read-only grace expires. Section 9 deliberately keeps access during
    | dunning - a card problem is not misuse.
    */
    'grace_days' => (int) env('DODO_GRACE_DAYS', 14),

];
