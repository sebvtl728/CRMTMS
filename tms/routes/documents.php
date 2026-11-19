<?php

use App\Modules\Documents\Texts\DocumentTextController;
use Illuminate\Support\Facades\Route;

/* Module Documents (0.10). */
Route::middleware(['auth', 'can:settings.manage'])->prefix('admin/document-texts')->name('admin.document-texts.')->group(function () {
    Route::get('/', [DocumentTextController::class, 'index'])->name('index');
    Route::put('{type}/{block}', [DocumentTextController::class, 'update'])->name('update');
    Route::delete('{type}/{block}', [DocumentTextController::class, 'destroy'])->name('destroy');
});
