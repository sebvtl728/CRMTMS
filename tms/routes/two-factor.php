<?php

use App\Modules\Core\Users\TwoFactorChallengeController;
use App\Modules\Core\Users\TwoFactorController;
use Illuminate\Support\Facades\Route;

// Second login step (the password step stored a pending login in the session).
Route::middleware('guest')->group(function () {
    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
        ->middleware('throttle:two-factor')->name('two-factor.challenge.store');
});

// Enrolment (exempt from EnsureTwoFactorEnrolled, except the password-confirmed actions).
Route::middleware('auth')->prefix('two-factor')->name('two-factor.')->group(function () {
    Route::get('setup', [TwoFactorController::class, 'show'])->name('setup');
    Route::post('confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:two-factor-setup')->name('confirm');
    Route::post('recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
        ->middleware('password.confirm')->name('recovery-codes');
    Route::delete('/', [TwoFactorController::class, 'destroy'])->middleware('password.confirm')->name('disable');
});
