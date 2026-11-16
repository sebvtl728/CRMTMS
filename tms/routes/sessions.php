<?php

use App\Modules\Sessions\Sessions\SessionController;
use App\Modules\Sessions\Settings\AbandonRuleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:settings.manage'])->prefix('admin/abandon-rule')->name('admin.abandon-rule.')->group(function () {
    Route::get('/', [AbandonRuleController::class, 'edit'])->name('edit');
    Route::put('/', [AbandonRuleController::class, 'update'])->name('update');
});

// Training sessions (intra / inter): scoped rights sessions.sessions.* (SessionPolicy).
Route::middleware(['auth'])->prefix('sessions')->name('sessions.')->group(function () {
    Route::get('/', [SessionController::class, 'index'])->middleware('can:sessions.sessions.view')->name('index');
    Route::get('calendar', [SessionController::class, 'calendar'])->middleware('can:sessions.sessions.view')->name('calendar');
    Route::get('create', [SessionController::class, 'create'])->middleware('can:sessions.sessions.update')->name('create');
    Route::get('organisations', [SessionController::class, 'organisations'])->middleware('can:sessions.sessions.update')->name('organisations');
    Route::post('/', [SessionController::class, 'store'])->middleware('can:sessions.sessions.update')->name('store');
    Route::get('{trainingSession}', [SessionController::class, 'show'])->middleware('can:sessions.sessions.view')->name('show');
    Route::get('{trainingSession}/edit', [SessionController::class, 'edit'])->middleware('can:sessions.sessions.update')->name('edit');
    Route::put('{trainingSession}', [SessionController::class, 'update'])->middleware('can:sessions.sessions.update')->name('update');
    Route::post('{trainingSession}/cancel', [SessionController::class, 'cancel'])->middleware('can:sessions.sessions.update')->name('cancel');
    Route::delete('{trainingSession}', [SessionController::class, 'destroy'])->middleware('can:sessions.sessions.delete')->name('destroy');
    Route::post('{trainingSession}/restore', [SessionController::class, 'restore'])->middleware('can:sessions.sessions.delete')->name('restore');
});
