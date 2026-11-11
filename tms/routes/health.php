<?php

use App\Modules\Core\Health\HealthController;
use App\Modules\Core\Health\ThrottleHealth;
use Illuminate\Support\Facades\Route;

// Deliberately outside the "web" group: no session, cookie or CSRF middleware,
// so the probe never touches Redis/DB through the session store.
Route::get('health', HealthController::class)->middleware(ThrottleHealth::class)->name('health');
