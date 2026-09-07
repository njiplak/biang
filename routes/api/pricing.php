<?php

use App\Http\Controllers\PublicApi\PricingController;
use Illuminate\Support\Facades\Route;

/*
 * Section 11: "Pricing is published by our app and read by the marketing site."
 *
 * Under `api/` rather than the web group for two reasons: it needs the CORS
 * middleware (the marketing site is a separate origin on a separate domain),
 * and a session cookie on a feed meant to sit behind a CDN would make it
 * uncacheable.
 */
// The `api` prefix is what config/cors.php matches on, so the path and the
// CORS configuration have to agree - hence both are explicit.
Route::prefix('api')->group(function () {
    Route::get('pricing', PricingController::class)->name('pricing');
});
