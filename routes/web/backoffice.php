<?php

use App\Http\Controllers\BackofficeController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => 'auth', 'prefix' => 'backoffice', 'as' => 'backoffice.'], function () {
    Route::get('/', [BackofficeController::class, 'index'])->name('index');
});
