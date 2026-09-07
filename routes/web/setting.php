<?php

use App\Http\Controllers\Setting\PermissionController;
use App\Http\Controllers\Setting\RoleController;
use App\Http\Controllers\Setting\SettingController;
use App\Http\Controllers\Setting\UserController;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => 'auth:admin', 'prefix' => 'setting', 'as' => 'backoffice.setting.'], function () {

    Route::group(['prefix' => 'setting', 'as' => 'setting.'], function () {
        Route::get('/', [SettingController::class, 'index'])->name('index')->middleware('permission:setting.view');
        Route::get('/fetch', [SettingController::class, 'fetch'])->name('fetch')->middleware('permission:setting.view');
        Route::get('/create', [SettingController::class, 'create'])->name('create')->middleware('permission:setting.create');
        Route::post('/', [SettingController::class, 'store'])->name('store')->middleware('permission:setting.create');
        Route::get('/{id}', [SettingController::class, 'show'])->name('show')->middleware('permission:setting.update');
        Route::put('/{id}', [SettingController::class, 'update'])->name('update')->middleware('permission:setting.update');
        Route::delete('/{id}', [SettingController::class, 'destroy'])->name('destroy')->middleware('permission:setting.delete');
        Route::post('/destroy-bulk', [SettingController::class, 'destroy_bulk'])->name('destroy-bulk')->middleware('permission:setting.delete');
    });

    Route::group(['prefix' => 'role', 'as' => 'role.'], function () {
        Route::get('/', [RoleController::class, 'index'])->name('index')->middleware('permission:role.view');
        Route::get('/fetch', [RoleController::class, 'fetch'])->name('fetch')->middleware('permission:role.view');
        Route::get('/create', [RoleController::class, 'create'])->name('create')->middleware('permission:role.create');
        Route::post('/', [RoleController::class, 'store'])->name('store')->middleware('permission:role.create');
        Route::get('/{id}', [RoleController::class, 'show'])->name('show')->middleware('permission:role.update');
        Route::put('/{id}', [RoleController::class, 'update'])->name('update')->middleware('permission:role.update');
        Route::delete('/{id}', [RoleController::class, 'destroy'])->name('destroy')->middleware('permission:role.delete');
        Route::post('/destroy-bulk', [RoleController::class, 'destroy_bulk'])->name('destroy-bulk')->middleware('permission:role.delete');
    });

    Route::group(['prefix' => 'user', 'as' => 'user.'], function () {
        Route::get('/', [UserController::class, 'index'])->name('index')->middleware('permission:user.view');
        Route::get('/fetch', [UserController::class, 'fetch'])->name('fetch')->middleware('permission:user.view');
        Route::get('/create', [UserController::class, 'create'])->name('create')->middleware('permission:user.create');
        Route::post('/', [UserController::class, 'store'])->name('store')->middleware('permission:user.create');
        Route::get('/{id}', [UserController::class, 'show'])->name('show')->middleware('permission:user.update');
        Route::put('/{id}', [UserController::class, 'update'])->name('update')->middleware('permission:user.update');
        Route::delete('/{id}', [UserController::class, 'destroy'])->name('destroy')->middleware('permission:user.delete');
        Route::post('/destroy-bulk', [UserController::class, 'destroy_bulk'])->name('destroy-bulk')->middleware('permission:user.delete');
    });

    Route::group(['prefix' => 'permission', 'as' => 'permission.'], function () {
        Route::get('/', [PermissionController::class, 'index'])->name('index')->middleware('permission:permission.view');
        Route::get('/fetch', [PermissionController::class, 'fetch'])->name('fetch')->middleware('permission:permission.view');
        Route::get('/create', [PermissionController::class, 'create'])->name('create')->middleware('permission:permission.create');
        Route::post('/', [PermissionController::class, 'store'])->name('store')->middleware('permission:permission.create');
        Route::get('/{id}', [PermissionController::class, 'show'])->name('show')->middleware('permission:permission.update');
        Route::put('/{id}', [PermissionController::class, 'update'])->name('update')->middleware('permission:permission.update');
        Route::delete('/{id}', [PermissionController::class, 'destroy'])->name('destroy')->middleware('permission:permission.delete');
        Route::post('/destroy-bulk', [PermissionController::class, 'destroy_bulk'])->name('destroy-bulk')->middleware('permission:permission.delete');
    });
});
