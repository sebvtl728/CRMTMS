<?php

use App\Modules\Core\Automation\AutomationSettingsController;
use Illuminate\Support\Facades\Route;

// Administration › Automatismes (2026-11-22).
Route::middleware(['auth', 'can:settings.manage'])->prefix('admin/automation')->name('admin.automation.')->group(function () {
    Route::get('/', [AutomationSettingsController::class, 'edit'])->name('edit');
    Route::put('/', [AutomationSettingsController::class, 'update'])->name('update');
    Route::post('run', [AutomationSettingsController::class, 'run'])->middleware('throttle:automation-run')->name('run');
});
