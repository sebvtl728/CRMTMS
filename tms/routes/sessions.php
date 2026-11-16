<?php

use App\Modules\Sessions\Dossiers\DossierController;
use App\Modules\Sessions\Enrolments\EnrolmentController;
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
    // Enrolments: sessions.sessions.update on the session (SessionPolicy).
    Route::get('{trainingSession}/trainees', [EnrolmentController::class, 'trainees'])->middleware('can:sessions.sessions.update')->name('trainees');
    Route::post('{trainingSession}/trainees', [EnrolmentController::class, 'createTrainee'])->middleware('can:sessions.sessions.update')->name('trainees.store');
    Route::post('{trainingSession}/enrolments', [EnrolmentController::class, 'store'])->middleware('can:sessions.sessions.update')->name('enrolments.store');
});

// An enrolment: sessions.sessions.update on its session (EnrolmentPolicy).
Route::middleware(['auth', 'can:sessions.sessions.update'])->prefix('enrolments')->name('enrolments.')->group(function () {
    Route::put('{enrolment}', [EnrolmentController::class, 'update'])->name('update');
    Route::post('{enrolment}/abandon', [EnrolmentController::class, 'abandon'])->name('abandon');
    Route::post('{enrolment}/{action}', [EnrolmentController::class, 'transition'])->whereIn('action', array_keys(EnrolmentController::ACTIONS))->name('transition');
});

// Dossiers de formation: scoped rights sessions.dossiers.* (DossierPolicy).
Route::middleware(['auth'])->prefix('dossiers')->name('dossiers.')->group(function () {
    Route::get('/', [DossierController::class, 'index'])->middleware('can:sessions.dossiers.view')->name('index');
    Route::get('export', [DossierController::class, 'export'])->middleware(['can:sessions.dossiers.view', 'can:crm.export', 'throttle:crm-export'])->name('export');
    Route::get('export/check', [DossierController::class, 'exportCheck'])->middleware(['can:sessions.dossiers.view', 'can:crm.export', 'throttle:crm-export'])->name('export.check');
    Route::get('create', [DossierController::class, 'create'])->middleware('can:sessions.dossiers.update')->name('create');
    Route::get('organisations', [DossierController::class, 'organisations'])->middleware('can:sessions.dossiers.update')->name('organisations');
    Route::get('people', [DossierController::class, 'people'])->middleware('can:sessions.dossiers.update')->name('people');
    Route::post('/', [DossierController::class, 'store'])->middleware('can:sessions.dossiers.update')->name('store');
    Route::get('{dossier}', [DossierController::class, 'show'])->middleware('can:sessions.dossiers.view')->name('show');
    Route::get('{dossier}/edit', [DossierController::class, 'edit'])->middleware('can:sessions.dossiers.update')->name('edit');
    Route::put('{dossier}', [DossierController::class, 'update'])->middleware('can:sessions.dossiers.update')->name('update');
    Route::post('{dossier}/steps/{step}', [DossierController::class, 'step'])->whereIn('step', array_keys(DossierController::STEPS))->middleware('can:sessions.dossiers.update')->name('step');
    Route::post('{dossier}/cancel', [DossierController::class, 'cancel'])->middleware('can:sessions.dossiers.update')->name('cancel');
    Route::delete('{dossier}', [DossierController::class, 'destroy'])->middleware('can:sessions.dossiers.delete')->name('destroy');
    Route::post('{dossier}/restore', [DossierController::class, 'restore'])->middleware('can:sessions.dossiers.delete')->name('restore');
});
