<?php

use App\Modules\Crm\Dashboard\DashboardExportController;
use App\Modules\Crm\Dashboard\DashboardsController;
use App\Modules\Crm\Dashboard\DoneActionsController;
use App\Modules\Crm\Settings\CrmSettingsController;
use Illuminate\Support\Facades\Route;

// Administration › CRM: follow-up delays and dashboard settings (lot 1e).
Route::middleware(['auth', 'can:settings.manage'])->prefix('admin/crm-settings')->name('admin.crm-settings.')->group(function () {
    Route::get('/', [CrmSettingsController::class, 'edit'])->name('edit');
    Route::put('/', [CrmSettingsController::class, 'update'])->name('update');
});

// « Tableaux de bord » (lot 1e): its own right, set in the roles; each card follows the right of its domain.
Route::middleware(['auth', 'can:crm.dashboards.view'])->get('crm/dashboards', [DashboardsController::class, 'index'])->name('crm.dashboards');
// Detail of the « Actions faites » and « Rendez-vous faits » cards.
Route::middleware(['auth', 'can:crm.dashboards.view', 'can:crm.activities.view'])->get('crm/dashboards/actions', DoneActionsController::class)->name('crm.dashboards.actions');
Route::middleware(['auth', 'can:crm.dashboards.view', 'can:crm.export', 'throttle:crm-export'])->prefix('crm/dashboards')->name('crm.dashboards.')->group(function () {
    Route::get('export.csv', [DashboardExportController::class, 'csv'])->name('csv');
    Route::get('export.pdf', [DashboardExportController::class, 'pdf'])->name('pdf');
});
