<?php

use App\Modules\Connectors\Calendar\CalendarTokenController;
use Illuminate\Support\Facades\Route;

// « Mon agenda »: private link of the iCal feed (the feed itself: routes/calendar-feed.php).
Route::middleware(['auth', 'can:crm.view'])->prefix('settings/calendar')->name('calendar.')->group(function () {
    Route::get('/', [CalendarTokenController::class, 'edit'])->name('settings');
    Route::post('/', [CalendarTokenController::class, 'store'])->name('store');
    Route::delete('/', [CalendarTokenController::class, 'destroy'])->name('destroy');
});
