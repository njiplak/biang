<?php

use App\Http\Controllers\Billing\BillingController;
use Illuminate\Support\Facades\Route;

// Section 7: plan changes happen only inside our app - the provider's own
// self-serve plan switcher is turned off, because a downgrade made outside
// these routes could not be blocked.
Route::middleware('auth')->prefix('billing')->as('billing.')->group(function () {
    Route::get('/', [BillingController::class, 'index'])->name('index');
    Route::post('trial', [BillingController::class, 'startTrial'])->name('trial');
    Route::put('plan', [BillingController::class, 'changePlan'])->name('plan');
    Route::delete('/', [BillingController::class, 'cancel'])->name('cancel');

    // Section 4's three add-on kinds. Entitlements move now; money follows in
    // phase 4, when Dodo becomes the merchant of record for the charge.
    Route::post('addons', [BillingController::class, 'purchaseAddon'])->name('addon.store');
    Route::put('addons', [BillingController::class, 'changeAddonQuantity'])->name('addon.update');
});
