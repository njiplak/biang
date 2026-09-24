<?php

use App\Http\Controllers\Billing\BillingController;
use Illuminate\Support\Facades\Route;

// Section 7: plan changes happen only inside our app - the provider's own
// self-serve plan switcher is turned off, because a downgrade made outside
// these routes could not be blocked.
Route::middleware('auth')->prefix('billing')->as('billing.')->group(function () {
    Route::get('/', [BillingController::class, 'index'])->name('index');

    /*
     * `verified` from here down. Section 5 Path B is explicit that verification
     * comes before the card, and section 12 sells one trial per PERSON, ever -
     * a rule that means nothing while the person is only an unproved address.
     *
     * Reading the page is deliberately not gated: section 11 carries a chosen
     * plan through signup, and bouncing someone off the page they were sent to
     * loses the plan they picked. Nor is cancelling - never block the exit.
     */
    Route::post('trial', [BillingController::class, 'startTrial'])->middleware('verified')->name('trial');

    // Section 8: the card form belongs to Dodo. This only hands the customer
    // over; nothing about our state moves until their webhook arrives.
    Route::post('checkout', [BillingController::class, 'checkout'])->middleware('verified')->name('checkout');

    /*
     * Section 5: "open the payment provider's page for cards and invoices."
     * Section 9 leans on it - the past-due banner tells the customer to update
     * their card, and this is the only door to it.
     *
     * A route rather than a URL in the page payload, because the link is
     * one-time and short-lived: minting one on every render would put a call to
     * Dodo in the way of a page that has to work when they are down.
     */
    Route::get('portal', [BillingController::class, 'portal'])->name('portal');

    Route::put('plan', [BillingController::class, 'changePlan'])->middleware('verified')->name('plan');

    /*
     * What the switch above would cost, asked before it is made. Section 4
     * prorates the difference and section 8 makes that arithmetic Dodo's, so
     * this is the only way to show a number before charging it.
     *
     * A GET because it changes nothing, and `verified` because it reaches out
     * to the provider on the customer's behalf.
     */
    Route::get('plan/preview', [BillingController::class, 'previewPlan'])
        ->middleware('verified')->name('plan.preview');
    Route::delete('/', [BillingController::class, 'cancel'])->name('cancel');

    // Verified like every other action that keeps charging a card.
    Route::post('resume', [BillingController::class, 'resume'])->middleware('verified')->name('resume');

    // Section 4's three add-on kinds. Entitlements move now; money follows in
    // phase 4, when Dodo becomes the merchant of record for the charge.
    Route::post('addons', [BillingController::class, 'purchaseAddon'])->middleware('verified')->name('addon.store');
    Route::put('addons', [BillingController::class, 'changeAddonQuantity'])->middleware('verified')->name('addon.update');
});
