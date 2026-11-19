<?php

use App\Modules\Qualite\Qualiopi\QualiopiController;
use Illuminate\Support\Facades\Route;

/* Module Qualité: the Qualiopi file (qualite.view to see, qualite.manage to keep it). */
Route::middleware(['auth', 'can:qualite.view'])->prefix('qualite/qualiopi')->name('qualite.qualiopi.')->group(function () {
    Route::get('/', [QualiopiController::class, 'index'])->name('index');
    Route::put('{number}', [QualiopiController::class, 'update'])->whereNumber('number')->middleware('can:qualite.manage')->name('update');
});
