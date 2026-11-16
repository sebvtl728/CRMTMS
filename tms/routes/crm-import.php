<?php

use App\Modules\Crm\Import\ImportController;
use Illuminate\Support\Facades\Route;

// Import wizard and history (crm.import). A batch is visible to its author and to users.manage.
Route::middleware(['auth', 'can:crm.import'])->prefix('crm/import')->name('crm.import.')->group(function () {
    Route::get('/', [ImportController::class, 'index'])->name('index');
    Route::get('new', [ImportController::class, 'create'])->name('create');
    Route::post('/', [ImportController::class, 'store'])->middleware('throttle:import-upload')->name('store');
    Route::get('template/{target}', [ImportController::class, 'template'])->name('template');

    Route::prefix('{batch}')->whereUuid('batch')->group(function () {
        Route::get('/', [ImportController::class, 'show'])->name('show');
        // Polled by the progress page: generous but bounded.
        Route::get('status', [ImportController::class, 'status'])->middleware('throttle:import-status')->name('status');
        Route::delete('/', [ImportController::class, 'destroy'])->name('destroy');
        Route::get('mapping', [ImportController::class, 'mapping'])->name('mapping');
        Route::put('mapping', [ImportController::class, 'saveMapping'])->name('mapping.save');
        Route::get('values', [ImportController::class, 'values'])->name('values');
        Route::put('values', [ImportController::class, 'saveValues'])->name('values.save');
        Route::put('strategy', [ImportController::class, 'saveStrategy'])->name('strategy.save');
        Route::post('run', [ImportController::class, 'run'])->name('run');
        Route::post('fail', [ImportController::class, 'fail'])->name('fail');
        Route::get('undo', [ImportController::class, 'undoPreview'])->middleware('throttle:import-undo-preview')->name('undo.preview');
        Route::post('undo', [ImportController::class, 'undo'])->name('undo');
        Route::get('report', [ImportController::class, 'report'])->name('report');
    });
});
