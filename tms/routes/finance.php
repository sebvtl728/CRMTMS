<?php

use App\Modules\Finance\Invoices\InvoiceController;
use App\Modules\Finance\Invoices\ToInvoiceController;
use App\Modules\Finance\Payments\PaymentController;
use App\Modules\Finance\Plans\BillingPlanController;
use App\Modules\Finance\Reminders\ReminderController;
use App\Modules\Finance\Settings\FinanceSettingsController;
use Illuminate\Support\Facades\Route;

// Finance › Réglages: the defaults of the organisme (settings.manage).
Route::middleware(['auth', 'can:settings.manage'])->prefix('finance/settings')->name('finance.settings.')->group(function () {
    Route::get('/', [FinanceSettingsController::class, 'edit'])->name('edit');
    Route::put('/', [FinanceSettingsController::class, 'update'])->name('update');
    Route::put('reminders', [FinanceSettingsController::class, 'reminders'])->name('reminders');
});

// The billing plan of a dossier: the scope of finance.invoices.* on the dossier is checked by the controller (404 / 403).
Route::middleware(['auth', 'can:finance.invoices.manage'])->prefix('finance')->name('finance.')->group(function () {
    Route::put('dossiers/{dossierId}/plan', [BillingPlanController::class, 'update'])->name('plans.update');
    // A draft made by hand from the dossier (or its draft in progress).
    Route::post('dossiers/{dossierId}/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::post('to-invoice/prepare', [ToInvoiceController::class, 'prepare'])->name('to-invoice.prepare');
    Route::put('invoices/{invoiceId}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::post('invoices/{invoiceId}/recompute', [InvoiceController::class, 'recompute'])->name('invoices.recompute');
    Route::delete('invoices/{invoiceId}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
    // « Créer un avoir » from a validated invoice.
    Route::post('invoices/{invoiceId}/credit-note', [InvoiceController::class, 'creditNote'])->name('invoices.credit-note');
    // « Envoyer » / « Renvoyer »: the archived PDF by e-mail.
    Route::post('invoices/{invoiceId}/send', [InvoiceController::class, 'send'])->middleware('throttle:invoice-mail')->name('invoices.send');
    Route::post('invoices/{invoiceId}/resend', [InvoiceController::class, 'resend'])->middleware('throttle:invoice-mail')->name('invoices.resend');
});

// « Valider »: number, frozen content, archived PDF (the scope of finance.invoices.validate on the dossier: 403).
Route::middleware(['auth', 'can:finance.invoices.validate', 'throttle:invoice-pdf'])->prefix('finance')->name('finance.')->group(function () {
    Route::post('invoices/{invoiceId}/validate', [InvoiceController::class, 'validateDraft'])->name('invoices.validate');
});

// « Vérifier l'intégrité »: recomputes the chain of the journal of the organisme.
Route::middleware(['auth', 'can:finance.invoices.validate', 'throttle:invoice-integrity'])->prefix('finance')->name('finance.')->group(function () {
    Route::post('invoices-integrity', [InvoiceController::class, 'integrity'])->name('invoices.integrity');
});

// « À facturer » and the invoices: the scope of finance.invoices.view on each dossier is applied by the queries (404 on one invoice).
Route::middleware(['auth', 'can:finance.invoices.view'])->prefix('finance')->name('finance.')->group(function () {
    Route::get('to-invoice', [ToInvoiceController::class, 'index'])->name('to-invoice.index');
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices-export', [InvoiceController::class, 'export'])->middleware('throttle:finance-export')->name('invoices.export');
    Route::get('invoices/{invoiceId}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoiceId}/pdf', [InvoiceController::class, 'pdf'])->middleware('throttle:invoice-pdf')->name('invoices.pdf');
    Route::get('dossiers/{dossierId}/due', [InvoiceController::class, 'due'])->name('dossiers.due');
});

// Finance › Règlements (0.9.1): finance.payments.manage on the whole organisme.
Route::middleware(['auth', 'can:finance.payments.manage'])->prefix('finance/payments')->name('finance.payments.')->group(function () {
    Route::get('/', [PaymentController::class, 'index'])->name('index');
    Route::get('create', [PaymentController::class, 'create'])->name('create');
    Route::get('payers', [PaymentController::class, 'payers'])->middleware('throttle:60,1')->name('payers');
    Route::get('open-invoices', [PaymentController::class, 'openInvoices'])->name('open-invoices');
    Route::post('/', [PaymentController::class, 'store'])->name('store');
    Route::get('{paymentId}/edit', [PaymentController::class, 'edit'])->name('edit');
    Route::put('{paymentId}', [PaymentController::class, 'update'])->name('update');
    Route::delete('{paymentId}', [PaymentController::class, 'destroy'])->name('destroy');
});

// Finance › À relancer (0.9.1): finance.invoices.manage and its scope on each dossier.
Route::middleware(['auth', 'can:finance.invoices.manage'])->prefix('finance')->name('finance.')->group(function () {
    Route::get('reminders', [ReminderController::class, 'index'])->name('reminders.index');
    Route::post('reminders/send', [ReminderController::class, 'send'])->middleware('throttle:invoice-mail')->name('reminders.send');
    Route::post('invoices/{invoiceId}/remind', [ReminderController::class, 'remind'])->middleware('throttle:invoice-mail')->name('invoices.remind');
    Route::post('invoices/{invoiceId}/reminders/pause', [ReminderController::class, 'pause'])->name('invoices.reminders.pause');
    Route::delete('invoices/{invoiceId}/reminders/pause', [ReminderController::class, 'resume'])->name('invoices.reminders.resume');
});
