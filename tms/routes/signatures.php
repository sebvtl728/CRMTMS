<?php

use App\Modules\Core\Signatures\SignatureController;
use Illuminate\Support\Facades\Route;

// The signer's personal link (no account): the host gives the organisme, the token the request.
Route::middleware('throttle:signature-public')->prefix('signature')->name('signature.')->group(function () {
    Route::get('{token}', [SignatureController::class, 'show'])->where('token', '[A-Za-z0-9]{40}')->name('show');
    Route::get('{token}/pdf', [SignatureController::class, 'pdf'])->where('token', '[A-Za-z0-9]{40}')->name('pdf');
    Route::post('{token}/code', [SignatureController::class, 'code'])->where('token', '[A-Za-z0-9]{40}')->name('code');
    Route::post('{token}/sign', [SignatureController::class, 'sign'])->where('token', '[A-Za-z0-9]{40}')->name('sign');
});
