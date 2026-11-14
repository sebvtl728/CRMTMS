<?php

use App\Modules\Core\Access\RoleController;
use Illuminate\Support\Facades\Route;

// « Rôles et droits »: the administrator only (roles.manage); writes limited.
Route::middleware(['auth', 'can:roles.manage'])->prefix('admin/roles')->name('admin.roles.')->group(function () {
    Route::get('/', [RoleController::class, 'index'])->name('index');
    Route::post('{role}', [RoleController::class, 'update'])->middleware('throttle:roles-write')->name('update');
    Route::post('{role}/reset', [RoleController::class, 'reset'])->middleware('throttle:roles-write')->name('reset');
});
