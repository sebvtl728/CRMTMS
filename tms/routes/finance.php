<?php

use App\Modules\Finance\Plans\BillingPlanController;
use App\Modules\Finance\Settings\FinanceSettingsController;
use Illuminate\Support\Facades\Route;

// Finance › Réglages: the defaults of the organisme (settings.manage).
Route::middleware(['auth', 'can:settings.manage'])->prefix('finance/settings')->name('finance.settings.')->group(function () {
    Route::get('/', [FinanceSettingsController::class, 'edit'])->name('edit');
    Route::put('/', [FinanceSettingsController::class, 'update'])->name('update');
});

// The billing plan of a dossier: the scope of finance.invoices.* on the dossier is checked by the controller (404 / 403).
Route::middleware(['auth', 'can:finance.invoices.manage'])->prefix('finance')->name('finance.')->group(function () {
    Route::put('dossiers/{dossierId}/plan', [BillingPlanController::class, 'update'])->name('plans.update');
});
