<?php

use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\Hash;

// Accounts belong to an organisme: the test body acts for the default tenant.
beforeEach(fn () => inTenant());

it('updates the password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/settings/password')
        ->put('/settings/password', [
            'current_password' => 'password',
            'password' => 'nouveau-mot-de-passe-solide',
            'password_confirmation' => 'nouveau-mot-de-passe-solide',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/password');

    expect(Hash::check('nouveau-mot-de-passe-solide', $user->refresh()->password))->toBeTrue();
});

it('requires the current password to update the password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/settings/password')
        ->put('/settings/password', [
            'current_password' => 'wrong-password',
            'password' => 'nouveau-mot-de-passe-solide',
            'password_confirmation' => 'nouveau-mot-de-passe-solide',
        ])
        ->assertSessionHasErrors('current_password')
        ->assertRedirect('/settings/password');
});
