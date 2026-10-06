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

    /*
    |--------------------------------------------------------------------------
    | Workspaces a person may own
    |--------------------------------------------------------------------------
    |
    | One customer, one workspace for now. Joining other people's workspaces by
    | invitation is not limited. Closed workspaces still inside their retention
    | window count, because restoring one would otherwise exceed the limit.
    |
    */

    'max_owned' => (int) env('WORKSPACE_MAX_OWNED', 1),

];
