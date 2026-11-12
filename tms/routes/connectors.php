<?php

use App\Modules\Connectors\Core\ConnectorController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:connectors.manage'])->prefix('admin/connectors')->name('admin.connectors.')->group(function () {
    Route::get('/', [ConnectorController::class, 'index'])->name('index');
    Route::post('runs/{run}/retry', [ConnectorController::class, 'retry'])->name('runs.retry');
    Route::get('{key}', [ConnectorController::class, 'edit'])->name('edit');
    Route::put('{key}', [ConnectorController::class, 'update'])->name('update');
    Route::post('{key}/test', [ConnectorController::class, 'test'])->name('test');
    Route::delete('{key}/secrets/{secret}', [ConnectorController::class, 'clearSecret'])->name('secrets.clear');
});
