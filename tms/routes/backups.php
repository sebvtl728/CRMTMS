<?php

use App\Modules\Core\Backups\BackupController;
use Illuminate\Support\Facades\Route;

// « Sauvegardes »: settings.manage, self-hosted install only (404 in multi mode, checked by the controller).
Route::middleware(['auth', 'can:settings.manage'])->prefix('admin/backups')->name('admin.backups.')->group(function () {
    Route::get('/', [BackupController::class, 'show'])->name('show');
    Route::post('request', [BackupController::class, 'request'])->name('request');
});
