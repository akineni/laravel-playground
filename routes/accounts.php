<?php

use App\Http\Controllers\v1\Account\AccountController;
use Illuminate\Support\Facades\Route;

Route::scopeBindings()->prefix('accounts/{account}')->group(function () {
    Route::get('/', [AccountController::class, 'show'])
        ->name('accounts.show');

    Route::post('withdraw-pessimistic', [AccountController::class, 'withdrawPessimistic'])
        ->name('accounts.withdraw-pessimistic');

    Route::post('withdraw-optimistic', [AccountController::class, 'withdrawOptimistic'])
        ->name('accounts.withdraw-optimistic');

    Route::post('withdraw-idempotent', [AccountController::class, 'withdrawIdempotent'])
        ->middleware('idempotent')
        ->name('accounts.withdraw-idempotent');
});
