<?php

use App\Modules\Crm\Quotes\QuoteController;
use Illuminate\Support\Facades\Route;

// Quotes (devis): created from an opportunity, changed while a draft only.
Route::middleware(['auth'])->group(function () {
    Route::get('crm/opportunities/{opportunity}/quotes/create', [QuoteController::class, 'create'])->middleware('can:crm.create')->name('crm.quotes.create');

    Route::prefix('crm/quotes')->name('crm.quotes.')->group(function () {
        Route::get('/', [QuoteController::class, 'index'])->middleware('can:crm.view')->name('index');
        Route::post('/', [QuoteController::class, 'store'])->middleware('can:crm.create')->name('store');
        Route::get('{quote}', [QuoteController::class, 'show'])->middleware('can:crm.view')->name('show');
        Route::get('{quote}/edit', [QuoteController::class, 'edit'])->middleware('can:crm.update')->name('edit');
        Route::put('{quote}/edit', [QuoteController::class, 'update'])->middleware('can:crm.update')->name('update');
        Route::delete('{quote}', [QuoteController::class, 'destroy'])->middleware('can:crm.delete')->name('destroy');
        Route::post('{quote}/duplicate', [QuoteController::class, 'duplicate'])->middleware('can:crm.create')->name('duplicate');
        // Archived PDF of a sent quote, or preview of a draft (rendered on the fly: limited).
        Route::get('{quote}/pdf', [QuoteController::class, 'pdf'])->middleware(['can:crm.view', 'throttle:quote-pdf'])->name('pdf');
    });
});
