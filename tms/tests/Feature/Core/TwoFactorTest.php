<?php

use App\Modules\Core\Users\TwoFactorAuthenticator;
use App\Modules\Core\Users\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

const TWO_FACTOR_PASSWORD = 'mot-de-passe-2fa-1234';

/**
 * Raw (encrypted) column values, as stored in the database.
 *
 * @return object{two_factor_secret: ?string, two_factor_recovery_codes: ?string}
 */
function rawTwoFactorColumns(User $user): object
{
    return DB::table('users')->where('id', $user->id)->first(['two_factor_secret', 'two_factor_recovery_codes']);
}

function loginWithPassword(User $user): TestResponse
{
    return test()->post('/login', ['email' => $user->email, 'password' => TWO_FACTOR_PASSWORD]);
}

// ---------------------------------------------------------------- mandatory enrolment

it('admin without 2fa is forced to enrol', fn () => $this->actingAs(admin())->get('/dashboard')->assertRedirect('/two-factor/setup'));

it('direction without 2fa is forced to enrol', fn () => $this->actingAs(direction())->get('/dashboard')->assertRedirect('/two-factor/setup'));

it('commercial without 2fa is not forced unless tenant forces it', function () {
    $this->actingAs(commercial())->get('/dashboard')->assertOk();
    tenant()->update(['settings->security->force_2fa' => true]);
    $this->actingAs(commercial())->get('/dashboard')->assertRedirect('/two-factor/setup');
});

it('lets an enrolled admin through', fn () => $this->actingAs(admin(twoFactor: true))->get('/dashboard')->assertOk());

it('forces enrolment on every route except setup and logout', function () {
    $admin = admin();

    $this->actingAs($admin)->get('/settings/profile')->assertRedirect('/two-factor/setup');
    $this->actingAs($admin)->post('/admin/users/invitations', ['email' => 'x@y.fr', 'name' => 'X', 'role' => 'commercial'])
        ->assertRedirect('/two-factor/setup');

    $this->actingAs($admin)->get('/two-factor/setup')->assertOk();
    $this->actingAs($admin)->post('/logout')->assertRedirect('/');
    $this->assertGuest();
});

// ---------------------------------------------------------------- enrolment

it('shows a QR code and stores the pending secret encrypted', function () {
    $admin = admin();

    $this->actingAs($admin)->get('/two-factor/setup')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/two-factor-setup')
            ->where('enabled', false)
            ->where('required', true)
            ->where('qrCodeSvg', fn (string $svg) => str_contains($svg, '<svg'))
            ->where('secret', fn (string $secret) => strlen(str_replace(' ', '', $secret)) >= 16));

    $admin->refresh();
    $raw = rawTwoFactorColumns($admin);

    expect($admin->two_factor_secret)->not->toBeNull()
        ->and($admin->two_factor_confirmed_at)->toBeNull()
        ->and($raw->two_factor_secret)->not->toBe($admin->two_factor_secret)
        ->and(Crypt::decryptString($raw->two_factor_secret))->toBe($admin->two_factor_secret);

    // Reloading the page keeps the same pending secret (the QR code already scanned stays valid).
    $this->actingAs($admin)->get('/two-factor/setup');
    expect($admin->fresh()->two_factor_secret)->toBe($admin->two_factor_secret);
});

it('refuses an invalid confirmation code', function () {
    $admin = admin();
    $this->actingAs($admin)->get('/two-factor/setup');

    $this->actingAs($admin)->post('/two-factor/confirm', ['code' => '000000'])
        ->assertSessionHasErrors(['code' => 'Le code de vérification est invalide.']);

    expect($admin->fresh()->hasTwoFactorEnabled())->toBeFalse();
    $this->actingAs($admin)->get('/dashboard')->assertRedirect('/two-factor/setup');
});

it('recovery codes are generated on 2fa confirmation', function () {
    $admin = admin();
    $this->actingAs($admin)->get('/two-factor/setup');
    $admin->refresh();

    $this->actingAs($admin)->post('/two-factor/confirm', ['code' => totp($admin)])
        ->assertRedirect('/two-factor/setup')
        ->assertSessionHas('two-factor.recovery-codes', fn (array $codes) => count($codes) === 8 && count(array_unique($codes)) === 8);

    $admin->refresh();
    $codes = session('two-factor.recovery-codes');
    $raw = rawTwoFactorColumns($admin);

    expect($admin->hasTwoFactorEnabled())->toBeTrue()
        ->and($admin->two_factor_recovery_codes)->toHaveCount(8)
        // stored encrypted, and only as hashes: no plain code anywhere in the column
        ->and(collect($codes)->contains(fn ($c) => str_contains(Crypt::decryptString($raw->two_factor_recovery_codes), $c)))->toBeFalse();

    // Shown once, on the setup page.
    $this->actingAs($admin)->get('/two-factor/setup')
        ->assertInertia(fn (Assert $page) => $page->where('enabled', true)->where('recoveryCodes', $codes));
    $this->actingAs($admin)->get('/dashboard')->assertOk();
});

it('regenerates recovery codes after password confirmation', function () {
    $admin = admin(twoFactor: true);
    $before = $admin->two_factor_recovery_codes;

    $this->actingAs($admin)->post('/two-factor/recovery-codes')->assertRedirect('/confirm-password');

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
        ->post('/two-factor/recovery-codes')
        ->assertRedirect('/two-factor/setup')
        ->assertSessionHas('two-factor.recovery-codes', fn (array $codes) => count($codes) === 8);

    expect($admin->fresh()->two_factor_recovery_codes)->toHaveCount(8)->not->toBe($before);
});

it('an admin cannot disable a mandatory 2fa, a commercial can disable an optional one', function () {
    $admin = admin(twoFactor: true);
    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/two-factor')->assertForbidden();
    expect($admin->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $commercial = commercial(twoFactor: true);
    $this->actingAs($commercial)->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/two-factor')->assertRedirect('/two-factor/setup');
    $commercial->refresh();
    expect($commercial->hasTwoFactorEnabled())->toBeFalse()
        ->and($commercial->two_factor_secret)->toBeNull()
        ->and($commercial->two_factor_recovery_codes)->toBeNull();
});

it('never exposes the secret or recovery codes in serialized users', function () {
    $admin = admin(twoFactor: true);

    expect($admin->toArray())->not->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes']);

    $this->actingAs($admin)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->missing('auth.user.two_factor_secret')->missing('auth.user.two_factor_recovery_codes'));
});

// ---------------------------------------------------------------- login challenge

it('asks for the code after the password when 2fa is confirmed', function () {
    $admin = admin(twoFactor: true);
    $admin->forceFill(['password' => TWO_FACTOR_PASSWORD])->save();

    loginWithPassword($admin)->assertRedirect('/two-factor-challenge');
    $this->assertGuest();

    $this->get('/two-factor-challenge')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/two-factor-challenge'));
    $sessionBefore = session()->getId();

    $this->post('/two-factor-challenge', ['code' => totp($admin)])->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($admin);
    expect(session()->getId())->not->toBe($sessionBefore)
        ->and(session()->has('login.id'))->toBeFalse()
        ->and($admin->fresh()->last_login_at)->not->toBeNull();
});

it('logs in without challenge when 2fa is not enabled', function () {
    $commercial = commercial();
    $commercial->forceFill(['password' => TWO_FACTOR_PASSWORD])->save();

    loginWithPassword($commercial)->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($commercial);
});

it('rejects a wrong code', function () {
    $admin = admin(twoFactor: true);
    $admin->forceFill(['password' => TWO_FACTOR_PASSWORD])->save();
    loginWithPassword($admin);

    $this->post('/two-factor-challenge', ['code' => '123456'])
        ->assertSessionHasErrors(['code' => 'Le code de vérification est invalide.']);
    $this->assertGuest();
});

it('a TOTP code cannot be replayed', function () {
    $admin = admin(twoFactor: true);
    $admin->forceFill(['password' => TWO_FACTOR_PASSWORD])->save();
    $code = totp($admin);

    loginWithPassword($admin);
    $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/dashboard');
    $this->post('/logout');
    $this->freshProcess();

    loginWithPassword($admin);
    $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('a recovery code works once', function () {
    $admin = admin(twoFactor: true);
    $admin->forceFill(['password' => TWO_FACTOR_PASSWORD])->save();
    $code = UserFactory::$lastRecoveryCodes[0];

    loginWithPassword($admin);
    $this->post('/two-factor-challenge', ['recovery_code' => $code])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($admin);
    expect($admin->fresh()->two_factor_recovery_codes)->toHaveCount(7);

    $this->post('/logout');
    $this->freshProcess();

    loginWithPassword($admin);
    $this->post('/two-factor-challenge', ['recovery_code' => $code])
        ->assertSessionHasErrors(['recovery_code' => 'Le code de récupération est invalide.']);
    $this->assertGuest();
});

it('throttles the challenge', function () {
    $admin = admin(twoFactor: true);
    $admin->forceFill(['password' => TWO_FACTOR_PASSWORD])->save();
    loginWithPassword($admin);

    foreach (range(1, 5) as $i) {
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
    }

    $this->post('/two-factor-challenge', ['code' => totp($admin)])->assertStatus(429);
    $this->assertGuest();
});

it('the challenge requires a pending password login', function () {
    tenant();
    $this->get('/two-factor-challenge')->assertRedirect('/login');
    $this->post('/two-factor-challenge', ['code' => '123456'])->assertRedirect('/login');
});

it('a pending login expires', function () {
    $admin = admin(twoFactor: true);
    $admin->forceFill(['password' => TWO_FACTOR_PASSWORD])->save();
    loginWithPassword($admin);

    $this->travel(11)->minutes();

    $this->post('/two-factor-challenge', ['code' => totp($admin)])->assertRedirect('/login');
    $this->assertGuest();
});

it('a user deactivated during the challenge cannot complete it', function () {
    $admin = admin(twoFactor: true);
    $admin->forceFill(['password' => TWO_FACTOR_PASSWORD])->save();
    loginWithPassword($admin);

    $admin->deactivate();

    $this->post('/two-factor-challenge', ['code' => totp($admin)])->assertRedirect('/login');
    $this->assertGuest();
});

// ---------------------------------------------------------------- fix round 1: concurrency and sessions

it('a recovery code consumed through one instance is rejected through a stale one', function () {
    $admin = admin(twoFactor: true);
    $code = UserFactory::$lastRecoveryCodes[0];
    $stale = User::query()->findOrFail($admin->id); // loaded before the first use, like a parallel request
    $authenticator = app(TwoFactorAuthenticator::class);

    expect($authenticator->useRecoveryCode($admin, $code))->toBeTrue()
        ->and($authenticator->useRecoveryCode($stale, $code))->toBeFalse()
        ->and($admin->fresh()->two_factor_recovery_codes)->toHaveCount(7);
});

it('two different recovery codes used through stale instances are both removed', function () {
    $admin = admin(twoFactor: true);
    [$first, $second] = UserFactory::$lastRecoveryCodes;
    $one = User::query()->findOrFail($admin->id);
    $two = User::query()->findOrFail($admin->id);
    $authenticator = app(TwoFactorAuthenticator::class);

    expect($authenticator->useRecoveryCode($one, $first))->toBeTrue()
        ->and($authenticator->useRecoveryCode($two, $second))->toBeTrue();

    $remaining = $admin->fresh()->two_factor_recovery_codes;
    expect($remaining)->toHaveCount(6)
        ->not->toContain(TwoFactorAuthenticator::hashRecoveryCode($first))
        ->not->toContain(TwoFactorAuthenticator::hashRecoveryCode($second));
});

/**
 * Inserts a database session row for $user (another browser).
 */
function otherSessionOf(User $user): string
{
    $id = Str::random(40);
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    return $id;
}

it('enabling 2fa revokes the other sessions and the remember token', function () {
    $admin = admin();
    $this->actingAs($admin)->get('/two-factor/setup');
    $admin->refresh();
    $other = otherSessionOf($admin);
    $token = $admin->remember_token;

    $this->actingAs($admin)->post('/two-factor/confirm', ['code' => totp($admin)])->assertRedirect('/two-factor/setup');

    expect(DB::table('sessions')->where('id', $other)->exists())->toBeFalse()
        ->and($admin->fresh()->remember_token)->not->toBe($token);
    $this->assertAuthenticatedAs($admin);
});

it('regenerating recovery codes and disabling 2fa revoke the other sessions', function () {
    $commercial = commercial(twoFactor: true);
    $other = otherSessionOf($commercial);
    $token = $commercial->remember_token;

    $this->actingAs($commercial)->withSession(['auth.password_confirmed_at' => time()])
        ->post('/two-factor/recovery-codes')->assertRedirect('/two-factor/setup');

    expect(DB::table('sessions')->where('id', $other)->exists())->toBeFalse()
        ->and($commercial->fresh()->remember_token)->not->toBe($token);

    $other = otherSessionOf($commercial);
    $this->actingAs($commercial)->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/two-factor')->assertRedirect('/two-factor/setup');

    expect(DB::table('sessions')->where('id', $other)->exists())->toBeFalse();
});
