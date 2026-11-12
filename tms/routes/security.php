<?php

use App\Modules\Core\Security\SecuritySettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:settings.manage'])->prefix('admin/security')->name('admin.security.')->group(function () {
    Route::get('/', [SecuritySettingsController::class, 'edit'])->name('edit');
    Route::put('/', [SecuritySettingsController::class, 'update'])->name('update');
});
