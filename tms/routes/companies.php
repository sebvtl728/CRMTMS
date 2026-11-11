<?php

use App\Modules\Core\Companies\CompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:companies.manage'])->prefix('admin/companies')->name('admin.companies.')->group(function () {
    Route::get('/', [CompanyController::class, 'index'])->name('index');
    Route::get('create', [CompanyController::class, 'create'])->name('create');
    Route::post('preview', [CompanyController::class, 'preview'])->name('preview');
    Route::post('/', [CompanyController::class, 'store'])->name('store');
    Route::get('{company}/edit', [CompanyController::class, 'edit'])->name('edit');
    Route::put('{company}', [CompanyController::class, 'update'])->name('update');
});
