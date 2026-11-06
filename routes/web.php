<?php

use App\Http\Controllers\LoginController;
use App\Http\Controllers\StageTransitionController;
use App\Http\Controllers\VendorController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', [VendorController::class, 'index'])->name('vendors.index');
    Route::get('/vendors/{vendor}', [VendorController::class, 'show'])->name('vendors.show');
    Route::post('/vendors/{vendor}/transitions', [StageTransitionController::class, 'store'])
        ->name('vendors.transitions.store');
});
