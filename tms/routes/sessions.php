<?php

use App\Modules\Sessions\Attendance\AttendanceController;
use App\Modules\Sessions\Attendance\AttendanceSheetController;
use App\Modules\Sessions\Attendance\Signing\CatchUpController;
use App\Modules\Sessions\Attendance\Signing\PublicSigningController;
use App\Modules\Sessions\Attendance\Signing\SignedSheetController;
use App\Modules\Sessions\Attendance\Signing\SigningController;
use App\Modules\Sessions\Dossiers\DossierController;
use App\Modules\Sessions\Dossiers\DossierFromQuoteController;
use App\Modules\Sessions\Enrolments\EnrolmentController;
use App\Modules\Sessions\Fundings\FundingController;
use App\Modules\Sessions\Modules\SessionModuleController;
use App\Modules\Sessions\Notes\SessionNoteController;
use App\Modules\Sessions\Planning\PlacementController;
use App\Modules\Sessions\Planning\PlanningController;
use App\Modules\Sessions\Planning\SeanceController;
use App\Modules\Sessions\Resources\ResourceSettingsController;
use App\Modules\Sessions\Sessions\SessionController;
use App\Modules\Sessions\Sessions\SessionStatusController;
use App\Modules\Sessions\Settings\AbandonRuleController;
use App\Modules\Sessions\Trainers\MyPlanningController;
use App\Modules\Sessions\Trainers\TrainerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:settings.manage'])->prefix('admin/abandon-rule')->name('admin.abandon-rule.')->group(function () {
    Route::get('/', [AbandonRuleController::class, 'edit'])->name('edit');
    Route::put('/', [AbandonRuleController::class, 'update'])->name('update');
    Route::put('trainer-follow-up', [AbandonRuleController::class, 'updateTrainerFollowUp'])->name('trainer-follow-up');
});

// Identity searches (people with their birth date, entreprises): the named
// limiter sessions-trainees, answers never cached (Cache-Control: no-store).
// Training sessions (intra / inter): scoped rights sessions.sessions.* (SessionPolicy).
Route::middleware(['auth'])->prefix('sessions')->name('sessions.')->group(function () {
    Route::get('/', [SessionController::class, 'index'])->middleware('can:sessions.sessions.view')->name('index');
    Route::get('calendar', [SessionController::class, 'calendar'])->middleware('can:sessions.sessions.view')->name('calendar');
    // Sessions → Planning: the séances of the scope of sessions.seances.view, month or week.
    Route::get('planning', [PlanningController::class, 'index'])->middleware('can:sessions.seances.view')->name('planning');
    Route::get('create', [SessionController::class, 'create'])->middleware('can:sessions.sessions.update')->name('create');
    Route::get('organisations', [SessionController::class, 'organisations'])->middleware(['can:sessions.sessions.update', 'throttle:sessions-trainees'])->name('organisations');
    Route::post('/', [SessionController::class, 'store'])->middleware('can:sessions.sessions.update')->name('store');
    Route::get('{trainingSession}', [SessionController::class, 'show'])->middleware('can:sessions.sessions.view')->name('show');
    Route::get('{trainingSession}/edit', [SessionController::class, 'edit'])->middleware('can:sessions.sessions.update')->name('edit');
    Route::put('{trainingSession}', [SessionController::class, 'update'])->middleware('can:sessions.sessions.update')->name('update');
    Route::post('{trainingSession}/status', SessionStatusController::class)->middleware('can:sessions.sessions.update')->name('status');
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
    // « Reprendre les modules de la formation »: preview, then replace (409 once a module has activity).
    Route::get('{trainingSession}/modules/catalogue', [SessionModuleController::class, 'catalogue'])->middleware('can:sessions.sessions.update')->name('modules.catalogue');
    Route::post('{trainingSession}/modules/catalogue', [SessionModuleController::class, 'fromCatalogue'])->middleware('can:sessions.sessions.update')->name('modules.from-catalogue');
    Route::post('{trainingSession}/modules/{sessionModule}/mode', [SessionModuleController::class, 'mode'])->middleware('can:sessions.sessions.update')->name('modules.mode');
    // Tasks and notes of the « Synthèse »: seen with the session, written with the update right (checked by SessionNotes).
    Route::post('{trainingSession}/tasks', [SessionNoteController::class, 'storeTask'])->middleware('can:sessions.sessions.view')->name('tasks.store');
    Route::put('{trainingSession}/tasks/{sessionTask}', [SessionNoteController::class, 'updateTask'])->middleware('can:sessions.sessions.view')->name('tasks.update');
    Route::post('{trainingSession}/tasks/{sessionTask}/toggle', [SessionNoteController::class, 'toggleTask'])->middleware('can:sessions.sessions.view')->name('tasks.toggle');
    Route::delete('{trainingSession}/tasks/{sessionTask}', [SessionNoteController::class, 'destroyTask'])->middleware('can:sessions.sessions.view')->name('tasks.destroy');
    Route::post('{trainingSession}/notes', [SessionNoteController::class, 'storeNote'])->middleware('can:sessions.sessions.view')->name('notes.store');
    Route::put('{trainingSession}/notes/{sessionNote}', [SessionNoteController::class, 'updateNote'])->middleware('can:sessions.sessions.view')->name('notes.update');
    Route::delete('{trainingSession}/notes/{sessionNote}', [SessionNoteController::class, 'destroyNote'])->middleware('can:sessions.sessions.view')->name('notes.destroy');
    // Séances, the generation assistant and placements: sessions.seances.manage and its scope on the session.
    Route::middleware('can:sessions.seances.manage')->group(function () {
        Route::post('{trainingSession}/seances', [SeanceController::class, 'store'])->name('seances.store');
        Route::post('{trainingSession}/seances/check', [SeanceController::class, 'check'])->name('seances.check');
        Route::post('{trainingSession}/seances/batch', [SeanceController::class, 'batchUpdate'])->name('seances.batch');
        Route::post('{trainingSession}/seances/batch-cancel', [SeanceController::class, 'batchCancel'])->name('seances.batch-cancel');
        Route::post('{trainingSession}/seances/batch-delete', [SeanceController::class, 'batchDelete'])->name('seances.batch-delete');
        Route::put('{trainingSession}/seances/{seance}', [SeanceController::class, 'update'])->name('seances.update');
        Route::post('{trainingSession}/seances/{seance}/cancel', [SeanceController::class, 'cancel'])->name('seances.cancel');
        Route::delete('{trainingSession}/seances/{seance}', [SeanceController::class, 'destroy'])->name('seances.destroy');
        Route::post('{trainingSession}/modules/{sessionModule}/seances/preview', [SeanceController::class, 'preview'])->name('seances.preview');
        Route::post('{trainingSession}/modules/{sessionModule}/seances/generate', [SeanceController::class, 'generate'])->name('seances.generate');
        Route::get('{trainingSession}/placements/organisations', [PlacementController::class, 'organisations'])->middleware('throttle:sessions-trainees')->name('placements.organisations');
        Route::post('{trainingSession}/placements', [PlacementController::class, 'store'])->name('placements.store');
        Route::put('{trainingSession}/placements/{placementPeriod}', [PlacementController::class, 'update'])->name('placements.update');
        Route::delete('{trainingSession}/placements/{placementPeriod}', [PlacementController::class, 'destroy'])->name('placements.destroy');
        Route::put('{trainingSession}/placements/{placementPeriod}/hosts/{enrolment}', [PlacementController::class, 'host'])->name('placements.hosts.update');
        Route::delete('{trainingSession}/placements/{placementPeriod}/hosts/{enrolment}', [PlacementController::class, 'removeHost'])->name('placements.hosts.destroy');
    });
    // « Présences » tab: sessions.attendance.manage or .close on the session (JSON).
    Route::get('{trainingSession}/attendance', [AttendanceController::class, 'session'])->name('attendance');
    Route::post('{trainingSession}/monthly-hours', [AttendanceController::class, 'monthly'])->middleware('can:sessions.attendance.manage')->name('monthly-hours');
    Route::post('{trainingSession}/closures', [AttendanceController::class, 'close'])->middleware('can:sessions.attendance.close')->name('closures.store');
    Route::post('{trainingSession}/closures/reopen', [AttendanceController::class, 'reopen'])->middleware('can:sessions.attendance.close')->name('closures.reopen');
    // Signed sheet of a month (émargement en ligne): generated by a manager of the attendance.
    Route::post('{trainingSession}/signed-sheets', [SignedSheetController::class, 'store'])->middleware(['can:sessions.attendance.manage', 'throttle:attendance-sheet'])->name('signed-sheets.store');
});

Route::get('signed-sheets/{sheet}', [SignedSheetController::class, 'show'])->middleware(['auth', 'can:sessions.attendance.manage'])->name('signed-sheets.show');

Route::middleware(['auth', 'can:sessions.attendance.manage'])->prefix('sessions/emargement/{slot}')->name('sessions.signing.')->group(function () {
    Route::get('/', [SigningController::class, 'show'])->name('show');
    Route::get('state', [SigningController::class, 'state'])->name('state');
    Route::post('close', [SigningController::class, 'close'])->name('close');
    Route::post('validate', [SigningController::class, 'validate'])->name('validate');
    Route::delete('signatures/{signature}', [SigningController::class, 'erase'])->name('erase');
    Route::post('catch-up', [SigningController::class, 'openCatchUp'])->name('catch-up');
    Route::post('catch-up/close', [SigningController::class, 'closeCatchUp'])->name('catch-up.close');
    Route::post('catch-up-links', [SigningController::class, 'sendCatchUpLink'])->middleware('throttle:attendance-signing-open')->name('catch-up-links');
});

// Catch-ups of the online signing (2026-11-21): an attendance manager confirms or refuses them.
Route::middleware(['auth', 'can:sessions.attendance.manage'])->prefix('sessions/rattrapages/{signature}')->name('sessions.catch-ups.')->group(function () {
    Route::post('confirm', [CatchUpController::class, 'confirm'])->name('confirm');
    Route::post('refuse', [CatchUpController::class, 'refuse'])->name('refuse');
    Route::get('dessin', [CatchUpController::class, 'drawing'])->name('drawing');
});

// The trainees' page of a half-day (no account; the host gives the organisme): per link and address.
Route::prefix('emargement')->name('signing.public.')->group(function () {
    Route::get('l/{token}', [PublicSigningController::class, 'showRemote'])->middleware('throttle:attendance-signing')->where('token', '[A-Za-z0-9]{40}')->name('remote.show');
    Route::post('l/{token}/code', [PublicSigningController::class, 'codeRemote'])->middleware('throttle:attendance-signing-post')->where('token', '[A-Za-z0-9]{40}')->name('remote.code');
    Route::post('l/{token}', [PublicSigningController::class, 'storeRemote'])->middleware('throttle:attendance-signing-post')->where('token', '[A-Za-z0-9]{40}')->name('remote.store');
    Route::get('r/{token}', [PublicSigningController::class, 'showCatchUp'])->middleware('throttle:attendance-signing')->where('token', '[A-Za-z0-9]{40}')->name('catch-up.show');
    Route::post('r/{token}', [PublicSigningController::class, 'storeCatchUp'])->middleware('throttle:attendance-signing-post')->where('token', '[A-Za-z0-9]{40}')->name('catch-up.store');
    Route::get('{token}', [PublicSigningController::class, 'show'])->middleware('throttle:attendance-signing')->where('token', '[A-Za-z0-9]{40}')->name('show');
    Route::post('{token}', [PublicSigningController::class, 'store'])->middleware('throttle:attendance-signing-post')->where('token', '[A-Za-z0-9]{40}')->name('store');
});

// Attendance of a séance (JSON): sessions.attendance.manage within its scope
// on the séance (« Ses séances » for a trainer), else 404.
Route::middleware(['auth', 'can:sessions.attendance.manage'])->prefix('seances/{seance}/attendance')->name('seances.attendance.')->group(function () {
    Route::get('/', [AttendanceController::class, 'grid'])->name('show');
    Route::post('/', [AttendanceController::class, 'record'])->name('store');
    Route::post('all-present', [AttendanceController::class, 'allPresent'])->name('all-present');
    Route::delete('{enrolmentId}', [AttendanceController::class, 'clear'])->name('destroy');
});

// Online signing (émargement en ligne): a half-day of the séance's day, opened from the séance
// (sessions.attendance.manage within its scope on one séance of the half-day, else 404).
Route::post('seances/{seance}/signing-slots', [SigningController::class, 'open'])
    ->middleware(['auth', 'can:sessions.attendance.manage', 'throttle:attendance-signing-open'])->name('seances.signing-slots');

// Printable attendance sheet (?day=1: the whole day of the session) and signed scans of a séance.
Route::middleware(['auth', 'can:sessions.attendance.manage'])->prefix('seances/{seance}')->name('seances.')->group(function () {
    Route::get('sheet', [AttendanceSheetController::class, 'pdf'])->middleware('throttle:attendance-sheet')->name('sheet');
    Route::post('scans', [AttendanceSheetController::class, 'store'])->middleware('throttle:attendance-scan')->name('scans.store');
    Route::get('scans/{scan}', [AttendanceSheetController::class, 'show'])->name('scans.show');
    Route::delete('scans/{scan}', [AttendanceSheetController::class, 'destroy'])->name('scans.destroy');
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
    Route::put('{trainer}/das', [TrainerController::class, 'updateDas'])->name('das');
    Route::put('{trainer}/user', [TrainerController::class, 'link'])->name('user');
    Route::post('{trainer}/unavailabilities', [TrainerController::class, 'storeUnavailability'])->name('unavailabilities.store');
    Route::delete('{trainer}/unavailabilities/{unavailability}', [TrainerController::class, 'destroyUnavailability'])->name('unavailabilities.destroy');
});

// « Mon planning »: the trainer linked to the account (his séances, his own unavailabilities).
Route::middleware(['auth'])->prefix('mon-planning')->name('my-planning.')->group(function () {
    Route::get('/', [MyPlanningController::class, 'show'])->name('show');
    Route::post('unavailabilities', [MyPlanningController::class, 'storeUnavailability'])->name('unavailabilities.store');
    Route::delete('unavailabilities/{unavailability}', [MyPlanningController::class, 'destroyUnavailability'])->name('unavailabilities.destroy');
});
