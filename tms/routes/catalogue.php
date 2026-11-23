<?php

use App\Modules\Catalogue\Assistant\TrainingAssistantController;
use App\Modules\Catalogue\Brochures\BrochureController;
use App\Modules\Catalogue\Rncp\RncpController;
use App\Modules\Catalogue\TrainingController;
use Illuminate\Support\Facades\Route;

// Catalogue de formations: read by every CRM role, managed by admin and direction.
Route::middleware(['auth'])->prefix('catalogue')->name('catalogue.')->group(function () {
    Route::get('/', [TrainingController::class, 'index'])->middleware('can:catalogue.view')->name('index');
    Route::get('export', [TrainingController::class, 'export'])->middleware(['can:catalogue.view', 'can:crm.export', 'throttle:catalogue-export'])->name('export');
    // Library of brochures (plaquettes): read by every CRM role, deposited and changed with catalogue.manage, trash with catalogue.delete.
    Route::prefix('brochures')->name('brochures.')->group(function () {
        Route::get('/', [BrochureController::class, 'index'])->middleware('can:catalogue.view')->name('index');
        Route::get('create', [BrochureController::class, 'create'])->middleware('can:catalogue.manage')->name('create');
        Route::post('/', [BrochureController::class, 'store'])->middleware('can:catalogue.manage')->name('store');
        Route::get('{brochure}/edit', [BrochureController::class, 'edit'])->middleware('can:catalogue.manage')->name('edit');
        Route::put('{brochure}', [BrochureController::class, 'update'])->middleware('can:catalogue.manage')->name('update');
        Route::delete('{brochure}', [BrochureController::class, 'destroy'])->middleware('can:catalogue.delete')->name('destroy');
        Route::get('{brochure}/download', [BrochureController::class, 'download'])->middleware('can:catalogue.view')->name('download');
        Route::get('{brochure}/versions/{version}', [BrochureController::class, 'downloadVersion'])->middleware('can:catalogue.view')->name('versions.download');
    });
    // AI assistant of the training form: a proposal (JSON), nothing saved; 20 requests an hour per user.
    Route::post('assistant/pdf', [TrainingAssistantController::class, 'pdf'])->middleware(['can:catalogue.manage', 'throttle:ai'])->name('assistant.pdf');
    Route::post('assistant/prompt', [TrainingAssistantController::class, 'prompt'])->middleware(['can:catalogue.manage', 'throttle:ai'])->name('assistant.prompt');
    Route::get('create', [TrainingController::class, 'create'])->middleware('can:catalogue.manage')->name('create');
    Route::post('create', [TrainingController::class, 'store'])->middleware('can:catalogue.manage')->name('store');
    Route::get('{training}/edit', [TrainingController::class, 'edit'])->middleware('can:catalogue.manage')->name('edit');
    Route::put('{training}/edit', [TrainingController::class, 'update'])->middleware('can:catalogue.manage')->name('update');
    Route::post('{training}/duplicate', [TrainingController::class, 'duplicate'])->middleware('can:catalogue.manage')->name('duplicate');
    Route::post('{training}/toggle', [TrainingController::class, 'toggle'])->middleware('can:catalogue.manage')->name('toggle');
    Route::get('rncp/{code}', [RncpController::class, 'show'])->middleware(['can:catalogue.view', 'throttle:crm-search'])->where('code', '[A-Za-z0-9]{1,20}')->name('rncp');
    Route::get('{training}/programme.pdf', [TrainingController::class, 'programme'])->middleware(['can:catalogue.view', 'throttle:catalogue-programme'])->name('programme');
    Route::get('{training}', [TrainingController::class, 'show'])->middleware('can:catalogue.view')->name('show');
    Route::delete('{training}', [TrainingController::class, 'destroy'])->middleware('can:catalogue.delete')->name('destroy');
});
