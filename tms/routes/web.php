<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// No public landing page: guests go to the login, signed-in users to the dashboard.
Route::get('/', fn () => auth()->check() ? to_route('dashboard') : to_route('login'))->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
require __DIR__.'/users.php';
require __DIR__.'/two-factor.php';
require __DIR__.'/audit.php';
require __DIR__.'/companies.php';
require __DIR__.'/references.php';
require __DIR__.'/connectors.php';
require __DIR__.'/calendar.php';
require __DIR__.'/security.php';
require __DIR__.'/crm.php';
require __DIR__.'/crm-import.php';
require __DIR__.'/crm-pipeline.php';
require __DIR__.'/crm-quotes.php';
require __DIR__.'/catalogue.php';
