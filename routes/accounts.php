<?php

use App\Http\Controllers\v1\Account\AccountController;
use App\Http\Controllers\v1\Account\AccountDocumentController;
use Illuminate\Support\Facades\Route;

Route::scopeBindings()->prefix('accounts/{account}')->group(function () {
    Route::get('/', [AccountController::class, 'show'])
        ->name('accounts.show');

    Route::get('documents', [AccountDocumentController::class, 'index'])
        ->name('accounts.documents.index');

    Route::post('documents/presign', [AccountDocumentController::class, 'presign'])
        ->name('accounts.documents.presign');

    Route::post('documents', [AccountDocumentController::class, 'store'])
        ->name('accounts.documents.store');

    Route::post('withdraw-pessimistic', [AccountController::class, 'withdrawPessimistic'])
        ->name('accounts.withdraw-pessimistic');

    Route::post('withdraw-optimistic', [AccountController::class, 'withdrawOptimistic'])
        ->name('accounts.withdraw-optimistic');

    Route::post('withdraw-idempotent', [AccountController::class, 'withdrawIdempotent'])
        ->middleware('idempotent')
        ->name('accounts.withdraw-idempotent');
});
