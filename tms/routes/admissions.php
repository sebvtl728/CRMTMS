<?php

use App\Modules\Admissions\Alternance\AlternanceController;
use App\Modules\Admissions\Booklet\BookletController;
use App\Modules\Admissions\Booklet\BookletGridController;
use App\Modules\Admissions\Booklet\BookletMonthController;
use App\Modules\Admissions\Booklet\BookletPageController;
use App\Modules\Admissions\Booklet\BookletVisitController;
use App\Modules\Admissions\Booklet\BookletSettingsController;
use App\Modules\Admissions\Booklet\DispensesController;
use App\Modules\Admissions\Candidatures\CandidatureController;
use App\Modules\Admissions\Candidatures\CandidatureFileController;
use App\Modules\Admissions\Candidatures\CandidatureStatusController;
use App\Modules\Admissions\Candidatures\DossierCreationController;
use App\Modules\Admissions\Candidatures\LookupController;
use App\Modules\Admissions\Cerfa\CerfaSheetController;
use App\Modules\Admissions\CustomFields\CustomFieldController;
use App\Modules\Admissions\OnlineTests\OnlinePassage;
use App\Modules\Admissions\OnlineTests\OnlineReviewController;
use App\Modules\Admissions\OnlineTests\OnlineTestController;
use App\Modules\Admissions\Positioning\PositioningController;
use App\Modules\Admissions\Positioning\PositioningUploadController;
use Illuminate\Support\Facades\Route;

/*
 * Module Admissions: candidatures (scoped rights admissions.candidatures.*,
 * AdmissionsVisibility; the route binding answers 404 outside the view scope)
 * and the custom fields of the organisme (settings.manage).
 */
Route::middleware(['auth'])->prefix('admissions/candidatures')->name('admissions.candidatures.')->group(function () {
    Route::get('/', [CandidatureController::class, 'index'])->middleware('can:admissions.candidatures.view')->name('index');
    Route::get('export', [CandidatureController::class, 'export'])->middleware(['can:admissions.candidatures.view', 'can:crm.export', 'throttle:crm-export'])->name('export');
    Route::get('lookup/organisations', [LookupController::class, 'organisations'])->middleware(['can:admissions.candidatures.update', 'throttle:sessions-trainees'])->name('lookup.organisations');
    Route::get('lookup/people', [LookupController::class, 'people'])->middleware(['can:admissions.candidatures.update', 'throttle:sessions-trainees'])->name('lookup.people');
    Route::post('people', [LookupController::class, 'storePerson'])->middleware(['can:admissions.candidatures.update', 'throttle:sessions-trainees'])->name('people.store');
    Route::get('create', [CandidatureController::class, 'create'])->middleware('can:admissions.candidatures.update')->name('create');
    Route::post('/', [CandidatureController::class, 'store'])->middleware('can:admissions.candidatures.update')->name('store');
    Route::get('{candidature}', [CandidatureController::class, 'show'])->middleware('can:admissions.candidatures.view')->name('show');
    Route::put('{candidature}', [CandidatureController::class, 'update'])->middleware('can:admissions.candidatures.update')->name('update');
    Route::post('{candidature}/status', CandidatureStatusController::class)->middleware('can:admissions.candidatures.update')->name('status');
    Route::post('{candidature}/files', [CandidatureFileController::class, 'store'])->middleware('can:admissions.candidatures.update')->name('files.store');
    Route::get('{candidature}/files/{file}', [CandidatureFileController::class, 'show'])->middleware('can:admissions.candidatures.view')->name('files.show');
    Route::delete('{candidature}/files/{file}', [CandidatureFileController::class, 'destroy'])->middleware('can:admissions.candidatures.update')->name('files.destroy');
    Route::get('{candidature}/online-attempts/{attempt}/pdf', [OnlineReviewController::class, 'pdf'])->middleware('can:admissions.candidatures.view')->name('online.pdf');
    Route::put('{candidature}/online-attempts/{attempt}/grades', [OnlineReviewController::class, 'grade'])->middleware('can:admissions.candidatures.update')->name('online.grade');
    Route::post('{candidature}/positioning/link', [PositioningController::class, 'link'])->middleware(['can:admissions.candidatures.update', 'throttle:positioning-send'])->name('positioning.link');
    Route::post('{candidature}/positioning/send', [PositioningController::class, 'send'])->middleware(['can:admissions.candidatures.update', 'throttle:positioning-send'])->name('positioning.send');
    Route::put('{candidature}/alternance', [AlternanceController::class, 'update'])->middleware('can:admissions.candidatures.update')->name('alternance.update');
    Route::get('{candidature}/livret.pdf', [BookletController::class, 'show'])->middleware(['can:admissions.candidatures.view', 'throttle:admissions-booklet'])->name('booklet');
    Route::get('{candidature}/cerfa.pdf', [CerfaSheetController::class, 'show'])->middleware(['can:admissions.candidatures.view', 'throttle:admissions-booklet'])->name('cerfa');
    Route::put('{candidature}/dispenses', [DispensesController::class, 'update'])->middleware('can:admissions.candidatures.update')->name('dispenses.update');
    Route::delete('{candidature}/alternance', [AlternanceController::class, 'destroy'])->middleware('can:admissions.candidatures.update')->name('alternance.destroy');
    Route::get('{candidature}/dossier/defaults', [DossierCreationController::class, 'defaults'])->middleware('can:admissions.candidatures.update')->name('dossier.defaults');
    Route::post('{candidature}/dossier', [DossierCreationController::class, 'store'])->middleware('can:admissions.candidatures.update')->name('dossier.store');
    Route::delete('{candidature}', [CandidatureController::class, 'destroy'])->middleware('can:admissions.candidatures.delete')->name('destroy');
    Route::post('{candidature}/restore', [CandidatureController::class, 'restore'])->middleware('can:admissions.candidatures.delete')->name('restore');
});

// Online follow-up of the apprenticeship booklet (trainers: « Ses séances »).
Route::middleware(['auth', 'can:admissions.booklet.manage'])->prefix('admissions/livret')->name('admissions.booklet.')->group(function () {
    Route::get('/', [BookletPageController::class, 'index'])->name('index');
    Route::get('{bookletCandidature}', [BookletPageController::class, 'show'])->name('show');
    Route::get('{bookletCandidature}/livret.pdf', [BookletPageController::class, 'pdf'])->middleware('throttle:admissions-booklet')->name('pdf');
    Route::put('{bookletCandidature}/grille', [BookletGridController::class, 'update'])->name('grid');
    Route::put('{bookletCandidature}/mois/{month}/{place}/observations', [BookletMonthController::class, 'observations'])->where(['month' => '\d{4}-\d{2}', 'place' => 'company|centre'])->name('months.observations');
    Route::post('{bookletCandidature}/mois/{month}/{place}/visa', [BookletMonthController::class, 'visa'])->where(['month' => '\d{4}-\d{2}', 'place' => 'company|centre'])->name('months.visa');
    Route::delete('{bookletCandidature}/mois/{month}/{place}/visa', [BookletMonthController::class, 'unvisa'])->where(['month' => '\d{4}-\d{2}', 'place' => 'company|centre'])->name('months.unvisa');
    Route::post('{bookletCandidature}/mois/{month}/papier/{signer}', [BookletMonthController::class, 'paper'])->where(['month' => '\d{4}-\d{2}', 'signer' => 'apprentice|tutor'])->name('months.paper');
    Route::post('{bookletCandidature}/visites', [BookletVisitController::class, 'store'])->name('visits.store');
    Route::put('{bookletCandidature}/visites/{visit}', [BookletVisitController::class, 'update'])->whereUuid('visit')->name('visits.update');
    Route::delete('{bookletCandidature}/visites/{visit}', [BookletVisitController::class, 'destroy'])->whereUuid('visit')->name('visits.destroy');
});

Route::middleware(['auth', 'can:settings.manage'])->prefix('admin/livret-apprentissage')->name('admin.booklet.')->group(function () {
    Route::get('/', [BookletSettingsController::class, 'edit'])->name('edit');
    Route::put('/', [BookletSettingsController::class, 'update'])->name('update');
    Route::post('annexes', [BookletSettingsController::class, 'storeAnnex'])->middleware('throttle:import-upload')->name('annexes.store');
    Route::delete('annexes/{annex}', [BookletSettingsController::class, 'destroyAnnex'])->name('annexes.destroy');
});

Route::middleware(['auth', 'can:settings.manage'])->prefix('admin/custom-fields')->name('admin.custom-fields.')->group(function () {
    Route::get('/', [CustomFieldController::class, 'index'])->name('index');
    Route::post('/', [CustomFieldController::class, 'store'])->name('store');
    Route::post('reorder', [CustomFieldController::class, 'reorder'])->name('reorder');
    Route::put('{customField}', [CustomFieldController::class, 'update'])->name('update');
    Route::post('{customField}/hide', [CustomFieldController::class, 'hide'])->name('hide');
    Route::post('{customField}/unhide', [CustomFieldController::class, 'unhide'])->name('unhide');
    Route::delete('{customField}', [CustomFieldController::class, 'destroy'])->name('destroy');
});

// The candidate's personal link to a positioning test (no account): the host
// gives the organisme, the token the sending; any invalid link, the same page.
Route::middleware('throttle:positioning-test')->prefix('test-positionnement')->name('positioning.')->group(function () {
    Route::get('{token}', [PositioningUploadController::class, 'show'])->where('token', '[0-9a-f]{64}')->name('show');
    Route::get('{token}/tests/{version}', [PositioningUploadController::class, 'download'])->where('token', '[0-9a-f]{64}')->name('download');
    Route::post('{token}', [PositioningUploadController::class, 'store'])->middleware('throttle:positioning-upload')->where('token', '[0-9a-f]{64}')->name('store');
    // Online test: start, answers saved as typed, traces, submission.
    Route::post('{token}/start', [OnlinePassage::class, 'start'])->where('token', '[0-9a-f]{64}')->name('online.start');
    Route::put('{token}/answers', [OnlinePassage::class, 'answers'])->where('token', '[0-9a-f]{64}')->name('online.answers');
    Route::post('{token}/events', [OnlinePassage::class, 'event'])->where('token', '[0-9a-f]{64}')->name('online.events');
    Route::post('{token}/submit', [OnlinePassage::class, 'submit'])->where('token', '[0-9a-f]{64}')->name('online.submit');
});

// Online positioning tests (as the library: catalogue.manage).
Route::middleware(['auth', 'can:catalogue.manage'])->prefix('admissions/online-tests')->name('admissions.online-tests.')->group(function () {
    Route::get('/', [OnlineTestController::class, 'index'])->name('index');
    Route::post('/', [OnlineTestController::class, 'store'])->name('store');
    Route::post('from-pdf', [OnlineTestController::class, 'fromPdf'])->middleware('throttle:ai')->name('from-pdf');
    Route::get('{test}/edit', [OnlineTestController::class, 'edit'])->name('edit');
    Route::put('{test}', [OnlineTestController::class, 'update'])->name('update');
    Route::post('{test}/publish', [OnlineTestController::class, 'publish'])->name('publish');
    Route::delete('{test}', [OnlineTestController::class, 'destroy'])->name('destroy');
});
