<?php

use App\Modules\Crm\Activities\ActivityController;
use App\Modules\Crm\Activities\PlanningController;
use App\Modules\Crm\Activities\QuickReportController;
use App\Modules\Crm\Activities\TodoController;
use App\Modules\Crm\Export\ExportController;
use App\Modules\Crm\Opportunities\KanbanController;
use App\Modules\Crm\Opportunities\OpportunityController;
use Illuminate\Support\Facades\Route;

// Pipeline: opportunities (list, kanban, Excel export, fiche, stage moves).
Route::middleware(['auth'])->prefix('crm/opportunities')->name('crm.opportunities.')->group(function () {
    Route::get('/', [OpportunityController::class, 'index'])->middleware('can:crm.view')->name('index');
    Route::get('kanban', KanbanController::class)->middleware('can:crm.view')->name('kanban');
    Route::get('export', [ExportController::class, 'opportunities'])->middleware(['can:crm.export', 'throttle:crm-export'])->name('export');
    Route::get('export/check', [ExportController::class, 'checkOpportunities'])->middleware(['can:crm.export', 'throttle:crm-export'])->name('export.check');
    Route::get('create', [OpportunityController::class, 'create'])->middleware('can:crm.create')->name('create');
    Route::post('/', [OpportunityController::class, 'store'])->middleware('can:crm.create')->name('store');
    Route::get('{opportunity}', [OpportunityController::class, 'show'])->middleware('can:crm.view')->name('show');
    Route::get('{opportunity}/edit', [OpportunityController::class, 'edit'])->middleware('can:crm.update')->name('edit');
    Route::put('{opportunity}', [OpportunityController::class, 'update'])->middleware('can:crm.update')->name('update');
    Route::delete('{opportunity}', [OpportunityController::class, 'destroy'])->middleware('can:crm.delete')->name('destroy');
    Route::post('{opportunity}/stage', [OpportunityController::class, 'stage'])->middleware('can:crm.update')->name('stage');
});

// « À faire »: my actions to do (the team's for direction and admin).
Route::middleware(['auth', 'can:crm.view'])->get('crm/todo', TodoController::class)->name('crm.todo');

// « Planning »: meetings and planned calls of a week (a colleague's for direction and admin).
Route::middleware(['auth', 'can:crm.view'])->get('crm/planning', PlanningController::class)->name('crm.planning');

// « Compte rendu de RDV »: one form (phone first) — done action, stage, next follow-up.
Route::middleware(['auth', 'can:crm.update'])->prefix('crm/report')->name('crm.report')->group(function () {
    Route::get('/', [QuickReportController::class, 'create']);
    Route::post('/', [QuickReportController::class, 'store'])->name('.store');
});

// Actions: quick entry from anywhere, « Fait » with the next follow-up, edit, removal.
Route::middleware(['auth'])->prefix('crm/activities')->name('crm.activities.')->group(function () {
    Route::get('options', [ActivityController::class, 'options'])->middleware('can:crm.create')->name('options');
    Route::post('/', [ActivityController::class, 'store'])->middleware('can:crm.create')->name('store');
    Route::get('{activity}', [ActivityController::class, 'show'])->middleware('can:crm.view')->name('show');
    Route::put('{activity}', [ActivityController::class, 'update'])->middleware('can:crm.update')->name('update');
    Route::delete('{activity}', [ActivityController::class, 'destroy'])->middleware('can:crm.update')->name('destroy');
    Route::patch('{activity}/due', [ActivityController::class, 'postpone'])->middleware('can:crm.update')->name('postpone');
    Route::post('{activity}/done', [ActivityController::class, 'complete'])->middleware('can:crm.update')->name('done');
});
