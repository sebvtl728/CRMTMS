<?php

use App\Modules\Crm\Revenue\RevenueController;
use Illuminate\Support\Facades\Route;

// Revenue (chiffre d'affaires) per DAS: the scope of crm.revenue.view is applied by the services.
Route::middleware(['auth'])->prefix('crm/revenue')->name('crm.revenue.')->group(function () {
    Route::get('/', [RevenueController::class, 'index'])->middleware('can:crm.revenue.view')->name('index');
    Route::put('targets', [RevenueController::class, 'setTarget'])->middleware('can:crm.revenue.manage')->name('targets');
    Route::get('{das}/sources', [RevenueController::class, 'sources'])->middleware('can:crm.revenue.view')->name('sources');
});
