<?php

use App\Modules\Core\Platform\SandboxController;
use Illuminate\Support\Facades\Route;

// Administration › Bac à sable: 404 outside a local sandbox (Core\Platform\Sandbox::enabled).
Route::middleware(['auth', 'can:settings.manage'])->prefix('admin/sandbox')->name('admin.sandbox.')->group(function () {
    Route::get('/', [SandboxController::class, 'show'])->name('show');
    Route::post('reset', [SandboxController::class, 'reset'])->middleware('throttle:sandbox-reset')->name('reset');
});
