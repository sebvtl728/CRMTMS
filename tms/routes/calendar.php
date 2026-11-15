<?php

use App\Modules\Connectors\Calendar\CalendarTokenController;
use App\Modules\Connectors\Calendar\OutlookController;
use Illuminate\Support\Facades\Route;

// « Mon agenda »: private link of the iCal feed (the feed itself: routes/calendar-feed.php).
Route::middleware(['auth', 'can:crm.activities.view'])->prefix('settings/calendar')->name('calendar.')->group(function () {
    Route::get('/', [CalendarTokenController::class, 'edit'])->name('settings');
    Route::post('/', [CalendarTokenController::class, 'store'])->name('store');
    Route::delete('/', [CalendarTokenController::class, 'destroy'])->name('destroy');

    // « Connecter Outlook » (Microsoft 365, OAuth code + PKCE).
    Route::post('outlook/connect', [OutlookController::class, 'connect'])->middleware('throttle:outlook-oauth')->name('outlook.connect');
    Route::get('outlook/callback', [OutlookController::class, 'callback'])->middleware('throttle:outlook-oauth')->name('outlook.callback');
    Route::delete('outlook', [OutlookController::class, 'disconnect'])->name('outlook.disconnect');
});
