<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Where a signed-in customer lands, and the home of the workspace switcher.
Route::middleware('auth')->get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
