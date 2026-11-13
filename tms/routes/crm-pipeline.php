<?php

use App\Modules\Crm\Opportunities\OpportunityController;
use Illuminate\Support\Facades\Route;

// Pipeline: opportunities (list, fiche, stage moves).
Route::middleware(['auth'])->prefix('crm/opportunities')->name('crm.opportunities.')->group(function () {
    Route::get('/', [OpportunityController::class, 'index'])->middleware('can:crm.view')->name('index');
    Route::get('create', [OpportunityController::class, 'create'])->middleware('can:crm.create')->name('create');
    Route::post('/', [OpportunityController::class, 'store'])->middleware('can:crm.create')->name('store');
    Route::get('{opportunity}', [OpportunityController::class, 'show'])->middleware('can:crm.view')->name('show');
    Route::get('{opportunity}/edit', [OpportunityController::class, 'edit'])->middleware('can:crm.update')->name('edit');
    Route::put('{opportunity}', [OpportunityController::class, 'update'])->middleware('can:crm.update')->name('update');
    Route::delete('{opportunity}', [OpportunityController::class, 'destroy'])->middleware('can:crm.delete')->name('destroy');
    Route::post('{opportunity}/stage', [OpportunityController::class, 'stage'])->middleware('can:crm.update')->name('stage');
});
