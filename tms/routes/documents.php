<?php

use App\Modules\Documents\DocumentController;
use App\Modules\Documents\Texts\DocumentTextController;
use Illuminate\Support\Facades\Route;

/* Module Documents (0.10). */
Route::middleware(['auth', 'can:settings.manage'])->prefix('admin/document-texts')->name('admin.document-texts.')->group(function () {
    Route::get('/', [DocumentTextController::class, 'index'])->name('index');
    Route::put('{type}/{block}', [DocumentTextController::class, 'update'])->name('update');
    Route::delete('{type}/{block}', [DocumentTextController::class, 'destroy'])->name('destroy');
});

Route::middleware(['auth'])->group(function () {
    Route::post('dossiers/{dossierId}/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::get('documents/{document}/pdf', [DocumentController::class, 'pdf'])->name('documents.pdf');
    Route::post('documents/{document}/send', [DocumentController::class, 'send'])->middleware('throttle:document-mail')->name('documents.send');
    Route::post('documents/{document}/cancel', [DocumentController::class, 'cancel'])->name('documents.cancel');
});
