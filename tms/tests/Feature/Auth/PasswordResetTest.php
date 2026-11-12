<?php

use App\Modules\Core\Users\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

// Accounts belong to an organisme: the test body acts for the default tenant.
beforeEach(fn () => inTenant());

it('renders the reset password link screen', function () {
    $this->get('/forgot-password')->assertStatus(200);
});

it('sends a reset password link', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

it('renders the reset password screen', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $this->get('/reset-password/'.$notification->token)->assertStatus(200);

        return true;
    });
});

it('resets the password with a valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'nouveau-mot-de-passe-solide',
            'password_confirmation' => 'nouveau-mot-de-passe-solide',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

it('revokes every session and the remember token after a password reset', function () {
    Notification::fake();
    $user = User::factory()->create(['remember_token' => 'ancien-jeton']);
    $session = Illuminate\Support\Str::random(40);
    Illuminate\Support\Facades\DB::table('sessions')->insert(['id' => $session, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $this->post('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'nouveau-mot-de-passe-solide',
            'password_confirmation' => 'nouveau-mot-de-passe-solide',
        ])->assertRedirect(route('login'));

        return true;
    });

    expect(Illuminate\Support\Facades\DB::table('sessions')->where('id', $session)->exists())->toBeFalse()
        ->and($user->fresh()->remember_token)->not->toBe('ancien-jeton');
});
