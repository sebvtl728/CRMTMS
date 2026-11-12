<?php

use App\Modules\Crm\Organisations\OrganisationController;
use App\Modules\Crm\Visibility\UserDasController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::prefix('crm/organisations')->name('crm.organisations.')->group(function () {
        Route::get('/', [OrganisationController::class, 'index'])->middleware('can:crm.view')->name('index');
        Route::get('create', [OrganisationController::class, 'create'])->middleware('can:crm.create')->name('create');
        Route::post('/', [OrganisationController::class, 'store'])->middleware('can:crm.create')->name('store');
        Route::get('{organisation}', [OrganisationController::class, 'show'])->middleware('can:crm.view')->name('show');
        Route::get('{organisation}/edit', [OrganisationController::class, 'edit'])->middleware('can:crm.update')->name('edit');
        Route::put('{organisation}', [OrganisationController::class, 'update'])->middleware('can:crm.update')->name('update');
        Route::delete('{organisation}', [OrganisationController::class, 'destroy'])->middleware('can:crm.delete')->name('destroy');
        Route::post('{organisation}/restore', [OrganisationController::class, 'restore'])->middleware('can:crm.delete')->name('restore');
    });

    // DAS followed by a Responsable DAS, set from the users administration page.
    Route::put('admin/users/{user}/das', [UserDasController::class, 'update'])->middleware('can:users.manage')->name('admin.users.das');
});
