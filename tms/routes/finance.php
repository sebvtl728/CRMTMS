<?php

use App\Modules\Finance\Invoices\InvoiceController;
use App\Modules\Finance\Invoices\ToInvoiceController;
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
    // A draft made by hand from the dossier (or its draft in progress).
    Route::post('dossiers/{dossierId}/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::post('to-invoice/prepare', [ToInvoiceController::class, 'prepare'])->name('to-invoice.prepare');
    Route::put('invoices/{invoiceId}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::delete('invoices/{invoiceId}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
});

// « À facturer » and the invoices: the scope of finance.invoices.view on each dossier is applied by the queries (404 on one invoice).
Route::middleware(['auth', 'can:finance.invoices.view'])->prefix('finance')->name('finance.')->group(function () {
    Route::get('to-invoice', [ToInvoiceController::class, 'index'])->name('to-invoice.index');
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoiceId}', [InvoiceController::class, 'show'])->name('invoices.show');
});
