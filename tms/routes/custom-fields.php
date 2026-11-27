<?php

use App\Modules\Core\CustomFields\CustomFieldController;
use Illuminate\Support\Facades\Route;

// Administration › Champs personnalisés: one tab per fiche declared in CustomFieldEntities (?entity=).
Route::middleware(['auth', 'can:settings.manage'])->prefix('admin/custom-fields')->name('admin.custom-fields.')->group(function () {
    Route::get('/', [CustomFieldController::class, 'index'])->name('index');
    Route::post('/', [CustomFieldController::class, 'store'])->name('store');
    Route::post('reorder', [CustomFieldController::class, 'reorder'])->name('reorder');
    Route::put('{customField}', [CustomFieldController::class, 'update'])->name('update');
    Route::post('{customField}/hide', [CustomFieldController::class, 'hide'])->name('hide');
    Route::post('{customField}/unhide', [CustomFieldController::class, 'unhide'])->name('unhide');
    Route::delete('{customField}', [CustomFieldController::class, 'destroy'])->name('destroy');
});
