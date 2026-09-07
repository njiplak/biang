<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retention after closing
    |--------------------------------------------------------------------------
    |
    | Spec section 6: a closed workspace is recoverable for 30 days, then
    | anonymised rather than hard-deleted so revenue history survives.
    |
    | Section 13 still lists confirming 30 days, and confirming that anonymising
    | is legally acceptable to us, as open questions - hence configurable.
    |
    */

    'retention_days' => (int) env('WORKSPACE_RETENTION_DAYS', 30),

];
