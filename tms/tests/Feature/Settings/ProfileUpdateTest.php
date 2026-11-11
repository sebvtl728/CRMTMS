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

it('lets users delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete('/settings/profile', ['password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

it('requires the correct password to delete the account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/settings/profile')
        ->delete('/settings/profile', ['password' => 'wrong-password'])
        ->assertSessionHasErrors('password')
        ->assertRedirect('/settings/profile');

    expect($user->fresh())->not->toBeNull();
});
