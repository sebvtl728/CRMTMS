<?php

use App\Modules\Core\Companies\BrandLogoController;
use Illuminate\Support\Facades\Route;

// Logo of the organisme in the sidebar: signed-in users only (not shown before login).
Route::get('branding/logo', BrandLogoController::class)->middleware('auth')->name('branding.logo');
