<?php

use App\Http\Controllers\Project\ProjectController;
use Illuminate\Support\Facades\Route;

// The example product feature. Everything here acts on the current workspace.
Route::middleware(['auth', 'verified'])->prefix('projects')->as('project.')->group(function () {
    Route::get('/', [ProjectController::class, 'index'])->name('index');
    Route::post('/', [ProjectController::class, 'store'])->name('store');
    Route::put('{project}', [ProjectController::class, 'update'])->name('update');
    Route::delete('{project}', [ProjectController::class, 'destroy'])->name('destroy');
});
