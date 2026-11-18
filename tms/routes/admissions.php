<?php

use App\Modules\Admissions\Candidatures\CandidatureController;
use App\Modules\Admissions\Candidatures\CandidatureStatusController;
use Illuminate\Support\Facades\Route;

/*
 * Module Admissions: candidatures (scoped rights admissions.candidatures.*,
 * AdmissionsVisibility; the route binding answers 404 outside the view scope)
 * and the custom fields of the organisme (settings.manage).
 */
Route::middleware(['auth'])->prefix('admissions/candidatures')->name('admissions.candidatures.')->group(function () {
    Route::get('/', fn () => redirect('/dashboard'))->middleware('can:admissions.candidatures.view')->name('index');
    Route::get('create', [CandidatureController::class, 'create'])->middleware('can:admissions.candidatures.update')->name('create');
    Route::post('/', [CandidatureController::class, 'store'])->middleware('can:admissions.candidatures.update')->name('store');
    Route::get('{candidature}', [CandidatureController::class, 'show'])->middleware('can:admissions.candidatures.view')->name('show');
    Route::put('{candidature}', [CandidatureController::class, 'update'])->middleware('can:admissions.candidatures.update')->name('update');
    Route::post('{candidature}/status', CandidatureStatusController::class)->middleware('can:admissions.candidatures.update')->name('status');
    Route::delete('{candidature}', [CandidatureController::class, 'destroy'])->middleware('can:admissions.candidatures.delete')->name('destroy');
    Route::post('{candidature}/restore', [CandidatureController::class, 'restore'])->middleware('can:admissions.candidatures.delete')->name('restore');
});
