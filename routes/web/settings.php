<?php

use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Settings\EmailChangeController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorController;
use Illuminate\Support\Facades\Route;

// Forgotten password: reachable only while signed out.
Route::middleware('guest')->group(function () {
    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    /*
     * The person's own account, not workspace-scoped (section 2).
     *
     * Outside the `verified` gate, all four of them. Someone who mistyped their
     * address at signup is otherwise in a dead end: they cannot reach the inbox
     * to verify, and cannot reach the form to correct it - a new account is the
     * only way out, which is a support ticket we would be writing on purpose.
     * Correcting it parks the new address exactly like any other change, and the
     * confirmation link proves it. Deleting is the exit, and the exit is never
     * blocked.
     */
    Route::get('settings/profile', [ProfileController::class, 'edit'])
        ->withoutMiddleware('verified')->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])
        ->withoutMiddleware('verified')->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])
        ->withoutMiddleware('verified')->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('user-password.update');

    /*
     * Confirming a parked email address. `signed` is the authorisation; `auth`
     * is here so we know WHOSE address is moving, and a link opened on a phone
     * that is signed out survives the detour through login via intended().
     */
    Route::get('settings/email/confirm/{id}/{hash}', [EmailChangeController::class, 'confirm'])
        ->middleware(['signed', 'throttle:6,1'])
        ->withoutMiddleware('verified')
        ->name('settings.email.confirm');

    Route::delete('settings/email/pending', [EmailChangeController::class, 'cancel'])
        ->withoutMiddleware('verified')
        ->name('settings.email.cancel');

    /*
     * Opt-in second factor. Reading the page is open; every change to it is
     * behind `password.confirm`, because adding or removing a second factor
     * from a session somebody left open is precisely the attack it exists to
     * stop. Confirming a code is throttled - six digits is 10^6, and the
     * password limiter has already been cleared by this point.
     */
    Route::get('settings/two-factor', [TwoFactorController::class, 'edit'])->name('two-factor.edit');

    Route::middleware('password.confirm')->group(function () {
        Route::post('settings/two-factor', [TwoFactorController::class, 'store'])->name('two-factor.store');
        Route::post('settings/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])->name('two-factor.recovery-codes');
        Route::delete('settings/two-factor', [TwoFactorController::class, 'destroy'])->name('two-factor.destroy');
    });

    Route::post('settings/two-factor/confirm', [TwoFactorController::class, 'confirm'])
        ->middleware(['password.confirm', 'throttle:6,1'])
        ->name('two-factor.confirm');
});
