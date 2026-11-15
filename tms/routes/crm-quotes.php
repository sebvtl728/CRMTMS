<?php

use App\Modules\Crm\Export\ExportController;
use App\Modules\Crm\Quotes\QuoteController;
use App\Modules\Crm\Quotes\QuoteSignedController;
use App\Modules\Crm\Quotes\QuoteTransitionController;
use Illuminate\Support\Facades\Route;

// Quotes (devis): created from an opportunity, changed while a draft only.
Route::middleware(['auth'])->group(function () {
    Route::get('crm/opportunities/{opportunity}/quotes/create', [QuoteController::class, 'create'])->middleware('can:crm.quotes.update')->name('crm.quotes.create');

    Route::prefix('crm/quotes')->name('crm.quotes.')->group(function () {
        Route::get('/', [QuoteController::class, 'index'])->middleware('can:crm.quotes.view')->name('index');
        Route::get('export', [ExportController::class, 'quotes'])->middleware(['can:crm.quotes.view', 'can:crm.export', 'throttle:crm-export'])->name('export');
        Route::get('export/check', [ExportController::class, 'checkQuotes'])->middleware(['can:crm.quotes.view', 'can:crm.export', 'throttle:crm-export'])->name('export.check');
        Route::post('/', [QuoteController::class, 'store'])->middleware('can:crm.quotes.update')->name('store');
        Route::get('{quote}', [QuoteController::class, 'show'])->middleware('can:crm.quotes.view')->name('show');
        Route::get('{quote}/edit', [QuoteController::class, 'edit'])->middleware('can:crm.quotes.update')->name('edit');
        Route::put('{quote}/edit', [QuoteController::class, 'update'])->middleware('can:crm.quotes.update')->name('update');
        Route::delete('{quote}', [QuoteController::class, 'destroy'])->middleware('can:crm.quotes.delete')->name('destroy');
        Route::post('{quote}/duplicate', [QuoteController::class, 'duplicate'])->middleware('can:crm.quotes.update')->name('duplicate');
        // Sending (renders and archives the PDF: limited), reminder, outcome.
        Route::post('{quote}/send', [QuoteTransitionController::class, 'send'])->middleware(['can:crm.quotes.update', 'throttle:quote-pdf', 'throttle:quote-mail'])->name('send');
        Route::post('{quote}/remind', [QuoteTransitionController::class, 'remind'])->middleware(['can:crm.quotes.update', 'throttle:quote-mail'])->name('remind');
        Route::post('{quote}/resend', [QuoteTransitionController::class, 'resend'])->middleware(['can:crm.quotes.update', 'throttle:quote-mail'])->name('resend');
        Route::post('{quote}/accept', [QuoteTransitionController::class, 'accept'])->middleware('can:crm.quotes.update')->name('accept');
        Route::post('{quote}/refuse', [QuoteTransitionController::class, 'refuse'])->middleware('can:crm.quotes.update')->name('refuse');
        // Signed copy of an accepted quote: attach / replace, download (limited).
        Route::post('{quote}/signe', [QuoteSignedController::class, 'store'])->middleware(['can:crm.quotes.update', 'throttle:quote-signed'])->name('signed.store');
        Route::get('{quote}/signe', [QuoteSignedController::class, 'show'])->middleware(['can:crm.quotes.view', 'throttle:quote-signed'])->name('signed.show');
        // Archived PDF of a sent quote, or preview of a draft (rendered on the fly: limited).
        Route::get('{quote}/pdf', [QuoteController::class, 'pdf'])->middleware(['can:crm.quotes.view', 'throttle:quote-pdf'])->name('pdf');
    });
});
