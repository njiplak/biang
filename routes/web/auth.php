<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\UserAuthController;
use App\Http\Controllers\Auth\UserTwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

// The way IN. Guest-only, so a signed-in person cannot quietly re-login as
// somebody else without leaving first.
Route::group(['middleware' => 'guest', 'prefix' => 'auth'], function () {
    Route::get('login', [UserAuthController::class, 'login'])->name('login');
    Route::post('login', [UserAuthController::class, 'attempt'])->name('attempt');

    // Section 5 Path A and B. Signup lives in our app; the marketing site only
    // links to it.
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->name('register.store');

    /*
     * The second half of a login. Guest-only, like the first half: reaching it
     * means the password passed but no session exists yet, and the note saying
     * so lives in the session (App\Support\PendingTwoFactor) rather than in the
     * URL - an id in a query string would let anyone skip straight to the code
     * prompt for an account they only know the id of.
     */
    Route::get('two-factor-challenge', [UserTwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
    Route::post('two-factor-challenge', [UserTwoFactorChallengeController::class, 'store'])->name('two-factor.challenge.store');
    Route::delete('two-factor-challenge', [UserTwoFactorChallengeController::class, 'destroy'])->name('two-factor.challenge.abandon');
});

/*
 * The way OUT, deliberately OUTSIDE the guest group above.
 *
 * Nesting it inside gave the route both `guest` and `auth`, which can never
 * both pass: RedirectIfAuthenticated runs first and sent every signed-in
 * customer to /dashboard, so logging out was impossible. See
 * tests/Feature/Auth/LogoutTest.php.
 */
Route::middleware('auth')->prefix('auth')->group(function () {
    Route::post('logout', [UserAuthController::class, 'logout'])->name('logout');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});
