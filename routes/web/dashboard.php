<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

/*
 * Where a signed-in customer lands, and the home of the workspace switcher.
 *
 * `verified` because section 5 puts verification BEFORE the product, so an
 * unconfirmed signup never reaches this page - which is why it no longer
 * carries a resend banner. They are held on verify-email instead.
 */
Route::middleware(['auth', 'verified'])->get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
