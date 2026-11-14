<?php

use App\Modules\Connectors\Calendar\IcalFeedController;
use App\Modules\Core\Tenancy\ResolveTenant;
use Illuminate\Support\Facades\Route;

// Outside the "web" group: calendar clients send no cookie, so no session,
// CSRF or authentication; the host resolves the organisme, the token its user.
Route::middleware([ResolveTenant::class, 'throttle:calendar-feed'])
    ->get('calendar/{token}.ics', IcalFeedController::class)
    ->where('token', '[0-9a-f]{64}')
    ->name('calendar.feed');
