<?php

use App\Modules\Qualite\Qualiopi\QualiopiController;
use Illuminate\Support\Facades\Route;

/* Module Qualité: the Qualiopi file (qualite.view to see, qualite.manage to keep it). */
Route::middleware(['auth', 'can:qualite.view'])->prefix('qualite/qualiopi')->name('qualite.qualiopi.')->group(function () {
    Route::get('/', [QualiopiController::class, 'index'])->name('index');
    Route::get('proofs/{proof}', [QualiopiController::class, 'downloadProof'])->name('proofs.download');
    Route::delete('proofs/{proof}', [QualiopiController::class, 'deleteProof'])->middleware('can:qualite.manage')->name('proofs.destroy');
    Route::get('{number}', [QualiopiController::class, 'show'])->whereNumber('number')->name('show');
    Route::post('{number}/proofs', [QualiopiController::class, 'addProof'])->whereNumber('number')->middleware('can:qualite.manage')->name('proofs.store');
    Route::put('{number}', [QualiopiController::class, 'update'])->whereNumber('number')->middleware('can:qualite.manage')->name('update');
});
