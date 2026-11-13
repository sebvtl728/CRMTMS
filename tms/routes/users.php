<?php

use App\Modules\Core\Users\ActivationController;
use App\Modules\Core\Users\InvitationController;
use App\Modules\Core\Users\UserController;
use Illuminate\Support\Facades\Route;

// Account activation from an invitation link (no account yet: no auth).
Route::middleware('throttle:activation')->group(function () {
    Route::get('activation/{token}', [ActivationController::class, 'show'])->name('activation.show');
    Route::post('activation/{token}', [ActivationController::class, 'store'])->name('activation.store');
});

Route::middleware(['auth', 'can:users.manage'])->prefix('admin/users')->name('admin.users.')->group(function () {
    Route::get('/', [InvitationController::class, 'index'])->name('index');
    Route::get('invite', [InvitationController::class, 'create'])->name('invite');
    Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
});

Route::middleware(['auth', 'can:users.manage'])->prefix('admin')->group(function () {
    Route::patch('users/{user}/role', [UserController::class, 'updateRole'])->name('admin.users.role');
    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('admin.users.deactivate');
    Route::post('users/{user}/reactivate', [UserController::class, 'reactivate'])->name('admin.users.reactivate');
    Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])->name('admin.invitations.resend');
    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('admin.invitations.destroy');
});
