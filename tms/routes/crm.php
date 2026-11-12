<?php

use App\Modules\Crm\Affiliations\AffiliationController;
use App\Modules\Crm\Duplicates\MergeController;
use App\Modules\Crm\Export\ExportController;
use App\Modules\Crm\Organisations\OrganisationController;
use App\Modules\Crm\People\PersonController;
use App\Modules\Crm\Search\SavedFilterController;
use App\Modules\Crm\Search\SearchController;
use App\Modules\Crm\Timeline\AttachmentController;
use App\Modules\Crm\Timeline\NoteController;
use App\Modules\Crm\Timeline\TimelineController;
use App\Modules\Crm\Visibility\UserDasController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::prefix('crm/organisations')->name('crm.organisations.')->group(function () {
        Route::get('/', [OrganisationController::class, 'index'])->middleware('can:crm.view')->name('index');
        Route::get('export', [ExportController::class, 'organisations'])->middleware(['can:crm.export', 'throttle:crm-export'])->name('export');
        Route::get('export/check', [ExportController::class, 'checkOrganisations'])->middleware(['can:crm.export', 'throttle:crm-export'])->name('export.check');
        Route::get('create', [OrganisationController::class, 'create'])->middleware('can:crm.create')->name('create');
        Route::post('/', [OrganisationController::class, 'store'])->middleware('can:crm.create')->name('store');
        Route::get('{organisation}', [OrganisationController::class, 'show'])->middleware('can:crm.view')->name('show');
        Route::get('{organisation}/edit', [OrganisationController::class, 'edit'])->middleware('can:crm.update')->name('edit');
        Route::put('{organisation}', [OrganisationController::class, 'update'])->middleware('can:crm.update')->name('update');
        Route::delete('{organisation}', [OrganisationController::class, 'destroy'])->middleware('can:crm.delete')->name('destroy');
        Route::post('{organisation}/restore', [OrganisationController::class, 'restore'])->middleware('can:crm.delete')->name('restore');
    });

    Route::post('crm/organisations/{organisation}/affiliations', [AffiliationController::class, 'store'])->middleware('can:crm.update')->name('crm.affiliations.store');
    Route::put('crm/affiliations/{affiliation}', [AffiliationController::class, 'update'])->middleware('can:crm.update')->name('crm.affiliations.update');
    Route::delete('crm/affiliations/{affiliation}', [AffiliationController::class, 'destroy'])->middleware('can:crm.update')->name('crm.affiliations.destroy');

    // Historique: feed, notes and attachments of a fiche.
    Route::get('crm/organisations/{organisation}/timeline', [TimelineController::class, 'organisation'])->middleware('can:crm.view')->name('crm.organisations.timeline');
    Route::get('crm/people/{person}/timeline', [TimelineController::class, 'person'])->middleware('can:crm.view')->name('crm.people.timeline');
    Route::post('crm/organisations/{organisation}/notes', [NoteController::class, 'storeForOrganisation'])->middleware('can:crm.update')->name('crm.organisations.notes.store');
    Route::post('crm/people/{person}/notes', [NoteController::class, 'storeForPerson'])->middleware('can:crm.update')->name('crm.people.notes.store');
    Route::delete('crm/notes/{note}', [NoteController::class, 'destroy'])->middleware('can:crm.update')->name('crm.notes.destroy');
    Route::post('crm/organisations/{organisation}/attachments', [AttachmentController::class, 'storeForOrganisation'])->middleware('can:crm.update')->name('crm.organisations.attachments.store');
    Route::post('crm/people/{person}/attachments', [AttachmentController::class, 'storeForPerson'])->middleware('can:crm.update')->name('crm.people.attachments.store');
    Route::get('crm/attachments/{attachment}', [AttachmentController::class, 'download'])->middleware('can:crm.view')->name('crm.attachments.download');
    Route::delete('crm/attachments/{attachment}', [AttachmentController::class, 'destroy'])->middleware('can:crm.update')->name('crm.attachments.destroy');

    Route::prefix('crm/people')->name('crm.people.')->group(function () {
        Route::get('/', [PersonController::class, 'index'])->middleware('can:crm.view')->name('index');
        Route::get('search', [PersonController::class, 'search'])->middleware('can:crm.view')->name('search');
        Route::get('export', [ExportController::class, 'people'])->middleware(['can:crm.export', 'throttle:crm-export'])->name('export');
        Route::get('export/check', [ExportController::class, 'checkPeople'])->middleware(['can:crm.export', 'throttle:crm-export'])->name('export.check');
        Route::get('create', [PersonController::class, 'create'])->middleware('can:crm.create')->name('create');
        Route::post('/', [PersonController::class, 'store'])->middleware('can:crm.create')->name('store');
        Route::get('{person}', [PersonController::class, 'show'])->middleware('can:crm.view')->name('show');
        Route::get('{person}/edit', [PersonController::class, 'edit'])->middleware('can:crm.update')->name('edit');
        Route::put('{person}', [PersonController::class, 'update'])->middleware('can:crm.update')->name('update');
        Route::delete('{person}', [PersonController::class, 'destroy'])->middleware('can:crm.delete')->name('destroy');
        Route::post('{person}/restore', [PersonController::class, 'restore'])->middleware('can:crm.delete')->name('restore');
    });

    // Global search (Ctrl+K), saved filters; the Excel exports are above, next to their lists.
    Route::get('crm/search', SearchController::class)->middleware(['can:crm.view', 'throttle:60,1'])->name('crm.search');
    Route::prefix('crm/saved-filters')->name('crm.saved-filters.')->middleware('can:crm.view')->group(function () {
        Route::get('/', [SavedFilterController::class, 'index'])->name('index');
        Route::post('/', [SavedFilterController::class, 'store'])->name('store');
        Route::put('{savedFilter}', [SavedFilterController::class, 'update'])->name('update');
        Route::delete('{savedFilter}', [SavedFilterController::class, 'destroy'])->name('destroy');
    });

    // Merge of two duplicate fiches (irreversible).
    Route::get('crm/merge/{type}/{keep}/{drop}', [MergeController::class, 'show'])->whereIn('type', ['organisation', 'person'])->middleware('can:crm.merge')->name('crm.merge.show');
    Route::post('crm/merge/{type}', [MergeController::class, 'store'])->whereIn('type', ['organisation', 'person'])->middleware('can:crm.merge')->name('crm.merge.store');

    // DAS followed by a Responsable DAS, set from the users administration page.
    Route::put('admin/users/{user}/das', [UserDasController::class, 'update'])->middleware('can:users.manage')->name('admin.users.das');
});
