<?php

use App\Modules\Crm\Brochures\BrochureSendingController;
use Illuminate\Support\Facades\Route;

// « Envoyer une plaquette » from a fiche or an opportunity: the right to change actions on a fiche the user sees; 20 e-mails a minute per user.
Route::middleware(['auth', 'can:crm.activities.update'])->group(function () {
    Route::get('crm/brochures/options', [BrochureSendingController::class, 'options'])->name('crm.brochures.options');
    Route::post('crm/organisations/{organisation}/brochures/send', [BrochureSendingController::class, 'organisation'])->middleware('throttle:brochure-mail')->name('crm.organisations.brochures.send');
    Route::post('crm/people/{person}/brochures/send', [BrochureSendingController::class, 'person'])->middleware('throttle:brochure-mail')->name('crm.people.brochures.send');
    Route::post('crm/opportunities/{opportunity}/brochures/send', [BrochureSendingController::class, 'opportunity'])->middleware('throttle:brochure-mail')->name('crm.opportunities.brochures.send');
});
