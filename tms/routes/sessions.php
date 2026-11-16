<?php

use App\Modules\Sessions\Dossiers\DossierController;
use App\Modules\Sessions\Dossiers\DossierFromQuoteController;
use App\Modules\Sessions\Enrolments\EnrolmentController;
use App\Modules\Sessions\Fundings\FundingController;
use App\Modules\Sessions\Modules\SessionModuleController;
use App\Modules\Sessions\Resources\ResourceSettingsController;
use App\Modules\Sessions\Sessions\SessionController;
use App\Modules\Sessions\Settings\AbandonRuleController;
use App\Modules\Sessions\Trainers\MyPlanningController;
use App\Modules\Sessions\Trainers\TrainerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:settings.manage'])->prefix('admin/abandon-rule')->name('admin.abandon-rule.')->group(function () {
    Route::get('/', [AbandonRuleController::class, 'edit'])->name('edit');
    Route::put('/', [AbandonRuleController::class, 'update'])->name('update');
});

// Identity searches (people with their birth date, entreprises): the named
// limiter sessions-trainees, answers never cached (Cache-Control: no-store).
// Training sessions (intra / inter): scoped rights sessions.sessions.* (SessionPolicy).
Route::middleware(['auth'])->prefix('sessions')->name('sessions.')->group(function () {
    Route::get('/', [SessionController::class, 'index'])->middleware('can:sessions.sessions.view')->name('index');
    Route::get('calendar', [SessionController::class, 'calendar'])->middleware('can:sessions.sessions.view')->name('calendar');
    Route::get('create', [SessionController::class, 'create'])->middleware('can:sessions.sessions.update')->name('create');
    Route::get('organisations', [SessionController::class, 'organisations'])->middleware(['can:sessions.sessions.update', 'throttle:sessions-trainees'])->name('organisations');
    Route::post('/', [SessionController::class, 'store'])->middleware('can:sessions.sessions.update')->name('store');
    Route::get('{trainingSession}', [SessionController::class, 'show'])->middleware('can:sessions.sessions.view')->name('show');
    Route::get('{trainingSession}/edit', [SessionController::class, 'edit'])->middleware('can:sessions.sessions.update')->name('edit');
    Route::put('{trainingSession}', [SessionController::class, 'update'])->middleware('can:sessions.sessions.update')->name('update');
    Route::post('{trainingSession}/cancel', [SessionController::class, 'cancel'])->middleware('can:sessions.sessions.update')->name('cancel');
    Route::delete('{trainingSession}', [SessionController::class, 'destroy'])->middleware('can:sessions.sessions.delete')->name('destroy');
    Route::post('{trainingSession}/restore', [SessionController::class, 'restore'])->middleware('can:sessions.sessions.delete')->name('restore');
    // Enrolments: sessions.sessions.update on the session (SessionPolicy).
    Route::get('{trainingSession}/trainees', [EnrolmentController::class, 'trainees'])->middleware(['can:sessions.sessions.update', 'throttle:sessions-trainees'])->name('trainees');
    Route::post('{trainingSession}/trainees', [EnrolmentController::class, 'createTrainee'])->middleware(['can:sessions.sessions.update', 'throttle:sessions-trainees'])->name('trainees.store');
    Route::post('{trainingSession}/enrolments', [EnrolmentController::class, 'store'])->middleware('can:sessions.sessions.update')->name('enrolments.store');
    Route::post('{trainingSession}/enrolments/group', [EnrolmentController::class, 'storeGroup'])->middleware(['can:sessions.sessions.update', 'throttle:sessions-trainees'])->name('enrolments.group');
    // Modules of the « Planning » tab: sessions.sessions.update on the session (SessionPolicy).
    Route::post('{trainingSession}/modules', [SessionModuleController::class, 'store'])->middleware('can:sessions.sessions.update')->name('modules.store');
    Route::put('{trainingSession}/modules/{sessionModule}', [SessionModuleController::class, 'update'])->middleware('can:sessions.sessions.update')->name('modules.update');
    Route::delete('{trainingSession}/modules/{sessionModule}', [SessionModuleController::class, 'destroy'])->middleware('can:sessions.sessions.update')->name('modules.destroy');
    Route::post('{trainingSession}/modules/{sessionModule}/mode', [SessionModuleController::class, 'mode'])->middleware('can:sessions.sessions.update')->name('modules.mode');
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
    Route::get('organisations', [DossierController::class, 'organisations'])->middleware(['can:sessions.dossiers.update', 'throttle:sessions-trainees'])->name('organisations');
    Route::get('people', [DossierController::class, 'people'])->middleware(['can:sessions.dossiers.update', 'throttle:sessions-trainees'])->name('people');
    Route::post('/', [DossierController::class, 'store'])->middleware('can:sessions.dossiers.update')->name('store');
    // « Créer le dossier » of an accepted quote (the quote seen through the CRM, else 404).
    Route::get('from-quote/{quoteId}', [DossierFromQuoteController::class, 'create'])->middleware('can:sessions.dossiers.update')->name('from-quote');
    Route::post('from-quote/{quoteId}', [DossierFromQuoteController::class, 'store'])->middleware('can:sessions.dossiers.update')->name('from-quote.store');
    Route::get('{dossier}', [DossierController::class, 'show'])->middleware('can:sessions.dossiers.view')->name('show');
    Route::get('{dossier}/edit', [DossierController::class, 'edit'])->middleware('can:sessions.dossiers.update')->name('edit');
    Route::put('{dossier}', [DossierController::class, 'update'])->middleware('can:sessions.dossiers.update')->name('update');
    Route::post('{dossier}/steps/{step}', [DossierController::class, 'step'])->whereIn('step', array_keys(DossierController::STEPS))->middleware('can:sessions.dossiers.update')->name('step');
    Route::post('{dossier}/cancel', [DossierController::class, 'cancel'])->middleware('can:sessions.dossiers.update')->name('cancel');
    Route::delete('{dossier}', [DossierController::class, 'destroy'])->middleware('can:sessions.dossiers.delete')->name('destroy');
    Route::post('{dossier}/restore', [DossierController::class, 'restore'])->middleware('can:sessions.dossiers.delete')->name('restore');
    // Fundings: the rights of the dossier (sessions.dossiers.update and its scope).
    Route::post('{dossier}/fundings', [FundingController::class, 'store'])->middleware('can:sessions.dossiers.update')->name('fundings.store');
    Route::put('{dossier}/fundings/{funding}', [FundingController::class, 'update'])->middleware('can:sessions.dossiers.update')->name('fundings.update');
    Route::delete('{dossier}/fundings/{funding}', [FundingController::class, 'destroy'])->middleware('can:sessions.dossiers.update')->name('fundings.destroy');
});

// « Salles et fermetures » of the organisme (sessions.rooms.manage).
Route::middleware(['auth', 'can:sessions.rooms.manage'])->prefix('admin/sessions-settings')->name('admin.sessions-settings.')->group(function () {
    Route::get('/', [ResourceSettingsController::class, 'index'])->name('index');
    Route::post('rooms', [ResourceSettingsController::class, 'storeRoom'])->name('rooms.store');
    Route::put('rooms/{room}', [ResourceSettingsController::class, 'updateRoom'])->name('rooms.update');
    Route::delete('rooms/{room}', [ResourceSettingsController::class, 'destroyRoom'])->name('rooms.destroy');
    Route::post('closures', [ResourceSettingsController::class, 'storeClosure'])->name('closures.store');
    Route::put('closures/{closurePeriod}', [ResourceSettingsController::class, 'updateClosure'])->name('closures.update');
    Route::delete('closures/{closurePeriod}', [ResourceSettingsController::class, 'destroyClosure'])->name('closures.destroy');
});

// « Formateurs » (sessions.trainers.manage): TMS users or trainers without account.
Route::middleware(['auth', 'can:sessions.trainers.manage'])->prefix('trainers')->name('trainers.')->group(function () {
    Route::get('/', [TrainerController::class, 'index'])->name('index');
    Route::post('/', [TrainerController::class, 'store'])->name('store');
    Route::get('{trainer}', [TrainerController::class, 'show'])->name('show');
    Route::put('{trainer}', [TrainerController::class, 'update'])->name('update');
    Route::delete('{trainer}', [TrainerController::class, 'destroy'])->name('destroy');
    Route::put('{trainer}/user', [TrainerController::class, 'link'])->name('user');
    Route::post('{trainer}/unavailabilities', [TrainerController::class, 'storeUnavailability'])->name('unavailabilities.store');
    Route::delete('{trainer}/unavailabilities/{unavailability}', [TrainerController::class, 'destroyUnavailability'])->name('unavailabilities.destroy');
});

// « Mon planning »: the trainer linked to the account (his own unavailabilities).
Route::middleware(['auth'])->prefix('mon-planning')->name('my-planning.')->group(function () {
    Route::get('/', [MyPlanningController::class, 'show'])->name('show');
    Route::post('unavailabilities', [MyPlanningController::class, 'storeUnavailability'])->name('unavailabilities.store');
    Route::delete('unavailabilities/{unavailability}', [MyPlanningController::class, 'destroyUnavailability'])->name('unavailabilities.destroy');
});
