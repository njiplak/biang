<?php

use App\Http\Controllers\Setting\PermissionController;
use App\Http\Controllers\Setting\RoleController;
use App\Http\Controllers\Setting\SettingController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => 'auth', 'prefix' => 'setting', 'as' => 'backoffice.setting.'], function () {

    Route::group(['prefix' => 'setting', 'as' => 'setting.'], function () {
        Route::get('/', [SettingController::class, 'index'])->name('index');
        Route::get('/fetch', [SettingController::class, 'fetch'])->name('fetch');
        Route::get('/create', [SettingController::class, 'create'])->name('create');
        Route::post('/', [SettingController::class, 'store'])->name('store');
        Route::get('/{id}', [SettingController::class, 'show'])->name('show');
        Route::put('/{id}', [SettingController::class, 'update'])->name('update');
        Route::delete('/{id}', [SettingController::class, 'destroy'])->name('destroy');
        Route::post('/destroy-bulk', [SettingController::class, 'destroy_bulk'])->name('destroy-bulk');
    });

    Route::group(['prefix' => 'role', 'as' => 'role.'], function () {
        Route::get('/', [RoleController::class, 'index'])->name('index');
        Route::get('/fetch', [RoleController::class, 'fetch'])->name('fetch');
        Route::get('/create', [RoleController::class, 'create'])->name('create');
        Route::post('/', [RoleController::class, 'store'])->name('store');
        Route::get('/{id}', [RoleController::class, 'show'])->name('show');
        Route::put('/{id}', [RoleController::class, 'update'])->name('update');
        Route::delete('/{id}', [RoleController::class, 'destroy'])->name('destroy');
        Route::post('/destroy-bulk', [RoleController::class, 'destroy_bulk'])->name('destroy-bulk');
    });

    Route::group(['prefix' => 'permission', 'as' => 'permission.'], function () {
        Route::get('/', [PermissionController::class, 'index'])->name('index');
        Route::get('/fetch', [PermissionController::class, 'fetch'])->name('fetch');
        Route::get('/create', [PermissionController::class, 'create'])->name('create');
        Route::post('/', [PermissionController::class, 'store'])->name('store');
        Route::get('/{id}', [PermissionController::class, 'show'])->name('show');
        Route::put('/{id}', [PermissionController::class, 'update'])->name('update');
        Route::delete('/{id}', [PermissionController::class, 'destroy'])->name('destroy');
        Route::post('/destroy-bulk', [PermissionController::class, 'destroy_bulk'])->name('destroy-bulk');
    });
});
