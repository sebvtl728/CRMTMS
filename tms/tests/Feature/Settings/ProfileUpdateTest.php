<?php

use App\Modules\Core\Users\User;

// Accounts belong to an organisme: the test body acts for the default tenant.
beforeEach(fn () => inTenant());

it('displays the profile page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/profile')->assertOk();
});

it('updates the profile information', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'current_password' => 'password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/profile');

    $user->refresh();

    expect($user->name)->toBe('Test User')
        ->and($user->email)->toBe('test@example.com')
        ->and($user->email_verified_at)->toBeNull();
});

it('keeps the verification status when the email is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/settings/profile');

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

it('requires the current password to change the email', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->from('/settings/profile')
        ->patch('/settings/profile', ['name' => $user->name, 'email' => 'autre@example.com'])
        ->assertSessionHasErrors('current_password');
    expect($user->refresh()->email)->not->toBe('autre@example.com');

    $this->actingAs($user)->from('/settings/profile')
        ->patch('/settings/profile', ['name' => $user->name, 'email' => 'autre@example.com', 'current_password' => 'mauvais-mot-de-passe'])
        ->assertSessionHasErrors('current_password');
    expect($user->refresh()->email)->not->toBe('autre@example.com');
});
