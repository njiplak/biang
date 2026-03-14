<?php

return [
    'default_paginated' => true,
    'pagination_per_page' => 10,
    'seeder_faker' => env('SEEDER_FAKER', false),
    'auth' => [
        'otp_expired' => 15,
        'token_expired' => 10
    ]

];
