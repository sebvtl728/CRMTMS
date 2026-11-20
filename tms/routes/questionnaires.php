<?php

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
