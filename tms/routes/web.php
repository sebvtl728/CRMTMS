<?php

use App\Http\Controllers\DashboardController;
use App\Modules\Core\Todo\TodoController;
use Illuminate\Support\Facades\Route;

// No public landing page: guests go to the login, signed-in users to the dashboard.
Route::get('/', fn () => auth()->check() ? to_route('dashboard') : to_route('login'))->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('a-traiter', [TodoController::class, 'index'])->name('todo.index');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
require __DIR__.'/users.php';
require __DIR__.'/roles.php';
require __DIR__.'/mail-templates.php';
require __DIR__.'/two-factor.php';
require __DIR__.'/audit.php';
require __DIR__.'/companies.php';
require __DIR__.'/branding.php';
require __DIR__.'/references.php';
require __DIR__.'/connectors.php';
require __DIR__.'/calendar.php';
require __DIR__.'/security.php';
require __DIR__.'/sandbox.php';
require __DIR__.'/backups.php';
require __DIR__.'/crm.php';
require __DIR__.'/crm-import.php';
require __DIR__.'/crm-pipeline.php';
require __DIR__.'/crm-quotes.php';
require __DIR__.'/crm-brochures.php';
require __DIR__.'/crm-revenue.php';
require __DIR__.'/crm-settings.php';
require __DIR__.'/automation.php';
require __DIR__.'/catalogue.php';
require __DIR__.'/sessions.php';
require __DIR__.'/finance.php';
require __DIR__.'/admissions.php';
require __DIR__.'/qualite.php';
require __DIR__.'/documents.php';
require __DIR__.'/questionnaires.php';
require __DIR__.'/signatures.php';
