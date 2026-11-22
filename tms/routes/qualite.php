<?php

use App\Modules\Qualite\Qualiopi\QualiopiController;
use App\Modules\Qualite\Registers\RegisterController;
use Illuminate\Support\Facades\Route;

/* Module Qualité: the Qualiopi file (qualite.view to see, qualite.manage to keep it). */
Route::middleware(['auth', 'can:qualite.view'])->prefix('qualite/qualiopi')->name('qualite.qualiopi.')->group(function () {
    Route::get('/', [QualiopiController::class, 'index'])->name('index');
    Route::get('export', [QualiopiController::class, 'export'])->name('export');
    Route::get('proofs/{proof}', [QualiopiController::class, 'downloadProof'])->name('proofs.download');
    Route::delete('proofs/{proof}', [QualiopiController::class, 'deleteProof'])->middleware('can:qualite.manage')->name('proofs.destroy');
    Route::get('{number}', [QualiopiController::class, 'show'])->whereNumber('number')->name('show');
    Route::post('{number}/proofs', [QualiopiController::class, 'addProof'])->whereNumber('number')->middleware('can:qualite.manage')->name('proofs.store');
    Route::put('{number}', [QualiopiController::class, 'update'])->whereNumber('number')->middleware('can:qualite.manage')->name('update');
});

// Registers (indicators 31 and 32): complaints and improvement actions.
Route::middleware(['auth', 'can:qualite.view'])->prefix('qualite')->name('qualite.')->group(function () {
    Route::get('reclamations', [RegisterController::class, 'complaints'])->name('complaints.index');
    Route::post('reclamations', [RegisterController::class, 'storeComplaint'])->middleware('can:qualite.manage')->name('complaints.store');
    Route::put('reclamations/{complaint}', [RegisterController::class, 'updateComplaint'])->middleware('can:qualite.manage')->name('complaints.update');
    Route::get('amelioration', [RegisterController::class, 'actions'])->name('actions.index');
    Route::post('amelioration', [RegisterController::class, 'storeAction'])->middleware('can:qualite.manage')->name('actions.store');
    Route::put('amelioration/{action}', [RegisterController::class, 'updateAction'])->middleware('can:qualite.manage')->name('actions.update');
});
