<?php

use App\Modules\Core\References\ReferenceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:references.manage'])->prefix('admin/references')->name('admin.references.')->group(function () {
    Route::get('/', [ReferenceController::class, 'index'])->name('index');
    Route::post('{list}/reorder', [ReferenceController::class, 'reorder'])->name('reorder');
    Route::post('{list}', [ReferenceController::class, 'store'])->name('store');
    Route::put('{list}/{item}', [ReferenceController::class, 'update'])->name('update');
    Route::delete('{list}/{item}', [ReferenceController::class, 'destroy'])->name('destroy');
});
