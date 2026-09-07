<?php

use App\Http\Controllers\Webhook\DodoWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Section 8: Dodo is merchant of record, and their notifications are how we
 * find out that money moved. Section 16: "A customer cancels on the provider's
 * page. Our records find out via notification, not immediately."
 *
 * Unauthenticated by necessity and gated by signature instead - the request
 * comes from Dodo's servers, not a session. Under `api/` so it never picks up
 * CSRF, which a server-to-server POST could not satisfy.
 */
Route::prefix('api')->group(function () {
    Route::post('webhooks/dodo', DodoWebhookController::class)->name('webhook.dodo');
});
