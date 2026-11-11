<?php

use App\Modules\Core\Audit\AuditController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'can:audit.view'])->get('admin/audit', [AuditController::class, 'index'])->name('admin.audit.index');
