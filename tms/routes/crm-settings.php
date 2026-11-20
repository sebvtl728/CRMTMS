<?php

use App\Modules\Crm\Dashboard\DashboardsController;
use App\Modules\Crm\Settings\CrmSettingsController;
use Illuminate\Support\Facades\Route;

// Administration › CRM: follow-up delays and dashboard settings (lot 1e).
Route::middleware(['auth', 'can:settings.manage'])->prefix('admin/crm-settings')->name('admin.crm-settings.')->group(function () {
    Route::get('/', [CrmSettingsController::class, 'edit'])->name('edit');
    Route::put('/', [CrmSettingsController::class, 'update'])->name('update');
});

// « Tableaux de bord » (lot 1e): any CRM view right; each card follows its own right.
Route::middleware(['auth', 'can:crm.any-view'])->get('crm/dashboards', [DashboardsController::class, 'index'])->name('crm.dashboards');
