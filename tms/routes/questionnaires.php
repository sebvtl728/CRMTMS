<?php

use App\Modules\Questionnaires\Answering\SurveyAnswerController;
use App\Modules\Questionnaires\Campaigns\SurveyCampaignController;
use App\Modules\Questionnaires\Templates\SurveyTemplateController;
use Illuminate\Support\Facades\Route;

// Administration › Questionnaires: templates and delay of the cold questionnaire.
Route::middleware(['auth', 'can:questionnaires.manage'])->prefix('admin/questionnaires')->name('admin.questionnaires.')->group(function () {
    Route::get('/', [SurveyTemplateController::class, 'index'])->name('index');
    Route::get('create', [SurveyTemplateController::class, 'create'])->name('create');
    Route::post('/', [SurveyTemplateController::class, 'store'])->name('store');
    Route::put('delai', [SurveyTemplateController::class, 'updateDelay'])->name('delay');
    Route::get('{surveyTemplate}/edit', [SurveyTemplateController::class, 'edit'])->name('edit');
    Route::put('{surveyTemplate}', [SurveyTemplateController::class, 'update'])->name('update');
    Route::delete('{surveyTemplate}', [SurveyTemplateController::class, 'destroy'])->name('destroy');
    Route::post('{surveyTemplate}/default', [SurveyTemplateController::class, 'makeDefault'])->name('default');
});

// « Lancer le questionnaire » of a session (rights on the session, read by Sessions\Public\Surveys).
Route::middleware(['auth', 'throttle:survey-launch'])->group(function () {
    Route::post('sessions/{sessionId}/questionnaires/{kind}', [SurveyCampaignController::class, 'launch'])->whereUuid('sessionId')->name('questionnaires.launch');
});

// The public pages of a questionnaire (no account): the host gives the
// organisme, the token the invitation (or the session QR); any invalid link,
// the same 404.
Route::middleware('throttle:survey-public')->prefix('questionnaire')->name('survey.')->group(function () {
    Route::get('{token}', [SurveyAnswerController::class, 'show'])->where('token', '[0-9a-f]{64}')->name('show');
});
