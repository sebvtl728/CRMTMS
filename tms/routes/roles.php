<?php

use App\Modules\Core\Access\RoleController;
use Illuminate\Support\Facades\Route;

// « Rôles et droits »: the administrator only (roles.manage); writes limited.
// {role} = id of a role of the current organisme (404 otherwise).
Route::middleware(['auth', 'can:roles.manage'])->prefix('admin/roles')->name('admin.roles.')->group(function () {
    Route::get('/', [RoleController::class, 'index'])->name('index');
    Route::middleware('throttle:roles-write')->group(function () {
        Route::post('/', [RoleController::class, 'store'])->name('store');
        Route::patch('{role}', [RoleController::class, 'modify'])->whereUuid('role')->name('modify');
        Route::delete('{role}', [RoleController::class, 'destroy'])->whereUuid('role')->name('destroy');
        Route::post('{role}', [RoleController::class, 'update'])->whereUuid('role')->name('update');
        Route::post('{role}/reset', [RoleController::class, 'reset'])->whereUuid('role')->name('reset');
    });
});
