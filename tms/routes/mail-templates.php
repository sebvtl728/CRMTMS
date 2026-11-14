<?php

use App\Modules\Core\Mail\MailTemplateController;
use Illuminate\Support\Facades\Route;

// « Modèles d'e-mails »: settings.manage (the administrator by default).
Route::middleware(['auth', 'can:settings.manage'])->prefix('admin/mail-templates')->name('admin.mail-templates.')->group(function () {
    Route::get('/', [MailTemplateController::class, 'index'])->name('index');
    Route::get('{key}', [MailTemplateController::class, 'edit'])->name('edit');
    Route::put('{key}', [MailTemplateController::class, 'update'])->middleware('throttle:mail-templates-write')->name('update');
    Route::delete('{key}', [MailTemplateController::class, 'destroy'])->middleware('throttle:mail-templates-write')->name('destroy');
    Route::post('{key}/preview', [MailTemplateController::class, 'preview'])->middleware('throttle:mail-templates-write')->name('preview');
    Route::post('{key}/test', [MailTemplateController::class, 'test'])->middleware('throttle:mail-template-test')->name('test');
});
