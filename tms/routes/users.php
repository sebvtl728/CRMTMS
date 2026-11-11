<?php

use App\Modules\Core\Users\ActivationController;
use App\Modules\Core\Users\InvitationController;
use Illuminate\Support\Facades\Route;

// Account activation from an invitation link (no account yet: no auth).
Route::middleware('throttle:10,1')->group(function () {
    Route::get('activation/{token}', [ActivationController::class, 'show'])->name('activation.show');
    Route::post('activation/{token}', [ActivationController::class, 'store'])->name('activation.store');
});

Route::middleware(['auth', 'can:users.manage'])->prefix('admin/users')->name('admin.users.')->group(function () {
    Route::get('/', [InvitationController::class, 'index'])->name('index');
    Route::get('invite', [InvitationController::class, 'create'])->name('invite');
    Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
});
