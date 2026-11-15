<?php

use App\Modules\Catalogue\TrainingController;
use Illuminate\Support\Facades\Route;

// Catalogue de formations: read by every CRM role, managed by admin and direction.
Route::middleware(['auth'])->prefix('catalogue')->name('catalogue.')->group(function () {
    Route::get('/', [TrainingController::class, 'index'])->middleware('can:catalogue.view')->name('index');
    Route::get('export', [TrainingController::class, 'export'])->middleware(['can:catalogue.view', 'can:crm.export', 'throttle:catalogue-export'])->name('export');
    Route::get('create', [TrainingController::class, 'create'])->middleware('can:catalogue.manage')->name('create');
    Route::post('create', [TrainingController::class, 'store'])->middleware('can:catalogue.manage')->name('store');
    Route::get('{training}/edit', [TrainingController::class, 'edit'])->middleware('can:catalogue.manage')->name('edit');
    Route::put('{training}/edit', [TrainingController::class, 'update'])->middleware('can:catalogue.manage')->name('update');
    Route::post('{training}/toggle', [TrainingController::class, 'toggle'])->middleware('can:catalogue.manage')->name('toggle');
    Route::delete('{training}', [TrainingController::class, 'destroy'])->middleware('can:catalogue.delete')->name('destroy');
});
