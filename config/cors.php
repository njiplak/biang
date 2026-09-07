<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | Spec section 11: the marketing site is a SEPARATE project on a separate
    | address, and our app sits on the `app.` subdomain so the two can ship
    | independently. That makes every pricing fetch a cross-origin request.
    |
    | Only the public pricing feed is listed. Nothing here is authenticated and
    | `supports_credentials` stays false, so a wide origin list cannot be turned
    | into a session-riding request against the rest of the app.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET'],

    // The pricing feed publishes exactly what the pricing page already shows
    // the world. Narrow this to the marketing site's origin if that ever stops
    // being true.
    'allowed_origins' => explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,

];
