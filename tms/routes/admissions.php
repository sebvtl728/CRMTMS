<?php

use App\Modules\Admissions\Alternance\AlternanceController;
use App\Modules\Admissions\Candidatures\CandidatureController;
use App\Modules\Admissions\Candidatures\CandidatureFileController;
use App\Modules\Admissions\Candidatures\CandidatureStatusController;
use App\Modules\Admissions\Candidatures\DossierCreationController;
use App\Modules\Admissions\Candidatures\LookupController;
use App\Modules\Admissions\CustomFields\CustomFieldController;
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
    Route::post('{candidature}/positioning/send', [PositioningController::class, 'send'])->middleware(['can:admissions.candidatures.update', 'throttle:positioning-send'])->name('positioning.send');
    Route::put('{candidature}/alternance', [AlternanceController::class, 'update'])->middleware('can:admissions.candidatures.update')->name('alternance.update');
    Route::delete('{candidature}/alternance', [AlternanceController::class, 'destroy'])->middleware('can:admissions.candidatures.update')->name('alternance.destroy');
    Route::get('{candidature}/dossier/defaults', [DossierCreationController::class, 'defaults'])->middleware('can:admissions.candidatures.update')->name('dossier.defaults');
    Route::post('{candidature}/dossier', [DossierCreationController::class, 'store'])->middleware('can:admissions.candidatures.update')->name('dossier.store');
    Route::delete('{candidature}', [CandidatureController::class, 'destroy'])->middleware('can:admissions.candidatures.delete')->name('destroy');
    Route::post('{candidature}/restore', [CandidatureController::class, 'restore'])->middleware('can:admissions.candidatures.delete')->name('restore');
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
Route::middleware('throttle:positioning-upload')->prefix('test-positionnement')->name('positioning.')->group(function () {
    Route::get('{token}', [PositioningUploadController::class, 'show'])->where('token', '[0-9a-f]{64}')->name('show');
    Route::get('{token}/tests/{version}', [PositioningUploadController::class, 'download'])->where('token', '[0-9a-f]{64}')->name('download');
    Route::post('{token}', [PositioningUploadController::class, 'store'])->where('token', '[0-9a-f]{64}')->name('store');
});
