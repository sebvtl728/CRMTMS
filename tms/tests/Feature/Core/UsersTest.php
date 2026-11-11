<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\ExternalIdentity;
use App\Modules\Core\Users\Invitation;
use App\Modules\Core\Users\InvitationNotification;
use App\Modules\Core\Users\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

const PASSWORD_A = 'mot-de-passe-A-1234';
const PASSWORD_B = 'mot-de-passe-B-1234';

function sessionCookieName(): string
{
    return config('session.cookie');
}

// ---------------------------------------------------------------- login

it('same email in two tenants logs into the right one', function () {
    config(['tms.mode' => 'multi']);
    $a = tenantOnHost('a.test');
    $b = tenantOnHost('b.test');
    userIn($a, ['email' => 'x@y.fr', 'password' => PASSWORD_A]);
    userIn($b, ['email' => 'x@y.fr', 'password' => PASSWORD_B]);

    $this->post('http://a.test/login', ['email' => 'x@y.fr', 'password' => PASSWORD_A])->assertRedirect('/dashboard');
    $this->assertAuthenticated();

    $this->freshProcess();
    $this->post('http://b.test/login', ['email' => 'x@y.fr', 'password' => PASSWORD_A])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->freshProcess();
    $this->post('http://b.test/login', ['email' => 'x@y.fr', 'password' => PASSWORD_B])->assertRedirect('/dashboard');
});

it('treats a session cookie from tenant A as a guest on tenant B', function () {
    config(['tms.mode' => 'multi', 'session.driver' => 'database']);
    $a = tenantOnHost('a.test');
    tenantOnHost('b.test');
    userIn($a, ['email' => 'x@y.fr', 'password' => PASSWORD_A]);

    $login = $this->post('http://a.test/login', ['email' => 'x@y.fr', 'password' => PASSWORD_A])->assertRedirect('/dashboard');
    $sessionId = $login->getCookie(sessionCookieName())->getValue();

    // The cookie does authenticate on its own tenant…
    $this->freshProcess();
    $this->withCookie(sessionCookieName(), $sessionId)->get('http://a.test/dashboard')->assertOk();

    // …but replayed on tenant B, RLS on users makes the lookup return nothing.
    $this->freshProcess();
    $this->withCookie(sessionCookieName(), $sessionId)->get('http://b.test/dashboard')->assertRedirect('http://b.test/login');
    $this->assertGuest();
});

it('issues a host-only session cookie (never shared across tenant subdomains)', function () {
    inTenant();
    User::factory()->create(['email' => 'x@y.fr', 'password' => PASSWORD_A]);

    $cookie = $this->post('/login', ['email' => 'x@y.fr', 'password' => PASSWORD_A])
        ->getCookie(sessionCookieName(), false);

    expect(config('session.domain'))->toBeNull()
        ->and($cookie->getDomain())->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');
});

it('deactivated user cannot log in', function () {
    inTenant();
    User::factory()->create(['email' => 'x@y.fr', 'password' => PASSWORD_A, 'deactivated_at' => now()]);

    $this->post('/login', ['email' => 'x@y.fr', 'password' => PASSWORD_A])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('a user who has not activated the account (no password) cannot log in', function () {
    inTenant();
    User::factory()->create(['email' => 'x@y.fr', 'password' => null]);

    $this->post('/login', ['email' => 'x@y.fr', 'password' => ''])->assertSessionHasErrors();
    $this->post('/login', ['email' => 'x@y.fr', 'password' => 'nimporte-quoi-1234'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('login is rate limited after 5 failures per email+ip', function () {
    inTenant();
    User::factory()->create(['email' => 'x@y.fr', 'password' => PASSWORD_A]);

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => 'x@y.fr', 'password' => 'mauvais-mot-de-passe'])
            ->assertSessionHasErrors(['email' => __('auth.failed')]);
    }

    // 6th attempt, even with the right password: throttled.
    $this->post('/login', ['email' => 'x@y.fr', 'password' => PASSWORD_A])->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('Tentatives de connexion trop nombreuses');
    $this->assertGuest();
});

it('records the last login time', function () {
    inTenant();
    $user = User::factory()->create(['email' => 'x@y.fr', 'password' => PASSWORD_A]);
    expect($user->last_login_at)->toBeNull();

    $this->post('/login', ['email' => 'x@y.fr', 'password' => PASSWORD_A])->assertRedirect('/dashboard');

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('public registration is disabled', function () {
    expect(Route::has('register'))->toBeFalse();

    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'X', 'email' => 'x@y.fr', 'password' => PASSWORD_A])->assertNotFound();
});

// ---------------------------------------------------------------- deactivation

it('deactivate() revokes the user sessions', function () {
    config(['session.driver' => 'database']);
    inTenant();
    $user = User::factory()->create(['email' => 'x@y.fr', 'password' => PASSWORD_A, 'remember_token' => 'ancien-jeton']);

    $sessionId = $this->post('/login', ['email' => 'x@y.fr', 'password' => PASSWORD_A])
        ->getCookie(sessionCookieName())->getValue();
    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);

    $user->deactivate();

    expect($user->fresh()->deactivated_at)->not->toBeNull()
        ->and($user->fresh()->remember_token)->not->toBe('ancien-jeton')
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

    $this->freshProcess();
    $this->withCookie(sessionCookieName(), $sessionId)->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

it('a session that survived deactivation no longer authenticates', function () {
    config(['session.driver' => 'database']);
    inTenant();
    $user = User::factory()->create(['email' => 'x@y.fr', 'password' => PASSWORD_A]);

    $sessionId = $this->post('/login', ['email' => 'x@y.fr', 'password' => PASSWORD_A])
        ->getCookie(sessionCookieName())->getValue();

    // e.g. a session store that cannot be purged (redis): only the flag changes.
    $user->forceFill(['deactivated_at' => now()])->save();

    $this->freshProcess();
    $this->withCookie(sessionCookieName(), $sessionId)->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

// ---------------------------------------------------------------- passwords

it('hashes passwords with argon2id', function () {
    inTenant();
    $user = User::factory()->create(['password' => PASSWORD_A]);

    expect(password_get_info($user->password)['algoName'])->toBe('argon2id')
        ->and(Hash::check(PASSWORD_A, $user->password))->toBeTrue();
});

it('a reset token issued on tenant A cannot reset the same-email user of tenant B', function () {
    config(['tms.mode' => 'multi']);
    Notification::fake();
    $a = tenantOnHost('a.test');
    $b = tenantOnHost('b.test');
    $userA = userIn($a, ['email' => 'x@y.fr', 'password' => PASSWORD_A]);
    $userB = userIn($b, ['email' => 'x@y.fr', 'password' => PASSWORD_B]);

    $this->post('http://a.test/forgot-password', ['email' => 'x@y.fr'])->assertSessionHasNoErrors();

    $token = null;
    Notification::assertSentTo($userA, ResetPassword::class, function (ResetPassword $n) use (&$token) {
        $token = $n->token;

        return true;
    });
    Notification::assertNotSentTo($userB, ResetPassword::class);

    $reset = ['token' => $token, 'email' => 'x@y.fr', 'password' => 'nouveau-mot-de-passe-solide', 'password_confirmation' => 'nouveau-mot-de-passe-solide'];

    // Replayed on tenant B: the token does not exist there.
    $this->post('http://b.test/reset-password', $reset)->assertSessionHasErrors('email');
    app(TenantContext::class)->run($b, fn () => expect(Hash::check(PASSWORD_B, $userB->fresh()->password))->toBeTrue());

    // A reset requested on B does not overwrite A's pending token.
    $this->post('http://b.test/forgot-password', ['email' => 'x@y.fr'])->assertSessionHasNoErrors();
    foreach ([$a, $b] as $tenant) {
        app(TenantContext::class)->run($tenant, fn () => expect(DB::table('password_reset_tokens')->where('email', 'x@y.fr')->pluck('tenant_id')->all())
            ->toBe([$tenant->id]));
    }

    // On its own tenant the token works.
    $this->post('http://a.test/reset-password', $reset)->assertSessionHasNoErrors()->assertRedirect('http://a.test/login');
    app(TenantContext::class)->run($a, fn () => expect(Hash::check('nouveau-mot-de-passe-solide', $userA->fresh()->password))->toBeTrue());
});

// ---------------------------------------------------------------- invitations & activation

it('stores invitation tokens hashed', function () {
    inTenant();
    $invitation = Invitation::issue('nouveau@cap.test', 'Nouveau Venu', Role::Commercial);
    $plain = $invitation->plainToken;

    $stored = Invitation::sole();
    expect($plain)->toHaveLength(128)
        ->and($stored->token_hash)->not->toBe($plain)
        ->and($stored->token_hash)->toBe(hash('sha256', $plain))
        ->and($stored->role)->toBe(Role::Commercial)
        ->and($stored->expires_at->between(now()->addHours(24)->subMinute(), now()->addHours(24)))->toBeTrue();
});

it('shows the activation page for a valid invitation', function () {
    inTenant();
    $invitation = Invitation::issue('nouveau@cap.test', 'Nouveau Venu', Role::Commercial);

    $this->get("/activation/{$invitation->plainToken}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/activate')
            ->where('email', 'nouveau@cap.test')
            ->where('name', 'Nouveau Venu')
            ->where('token', $invitation->plainToken));
});

it('activation creates the user with an argon2id password', function () {
    inTenant();
    $invitation = Invitation::issue('nouveau@cap.test', 'Nouveau Venu', Role::Commercial);

    $this->post("/activation/{$invitation->plainToken}", [
        'password' => 'un-mot-de-passe-solide', 'password_confirmation' => 'un-mot-de-passe-solide',
    ])->assertSessionHasNoErrors()->assertRedirect('/login');

    $user = User::sole();
    expect($user->email)->toBe('nouveau@cap.test')
        ->and($user->name)->toBe('Nouveau Venu')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(password_get_info($user->password)['algoName'])->toBe('argon2id')
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($invitation->fresh()->user_id)->toBe($user->id);

    $this->post('/login', ['email' => 'nouveau@cap.test', 'password' => 'un-mot-de-passe-solide'])->assertRedirect('/dashboard');
});

it('activation sets the password of an existing account without one', function () {
    inTenant();
    $pending = User::factory()->create(['email' => 'admin@cap.test', 'password' => null]);
    $invitation = Invitation::issue('admin@cap.test', 'Admin', Role::AdminOrganisme);

    $this->post("/activation/{$invitation->plainToken}", [
        'password' => 'un-mot-de-passe-solide', 'password_confirmation' => 'un-mot-de-passe-solide',
    ])->assertSessionHasNoErrors();

    expect(User::count())->toBe(1)
        ->and(Hash::check('un-mot-de-passe-solide', $pending->fresh()->password))->toBeTrue();
});

it('rejects passwords shorter than 12 characters', function () {
    inTenant();
    $invitation = Invitation::issue('nouveau@cap.test', 'Nouveau Venu', Role::Commercial);

    $this->post("/activation/{$invitation->plainToken}", ['password' => 'Court123!', 'password_confirmation' => 'Court123!'])
        ->assertSessionHasErrors('password');

    expect(User::count())->toBe(0)->and($invitation->fresh()->accepted_at)->toBeNull();
});

it('rejects compromised passwords', function () {
    inTenant();
    $this->markPasswordAsCompromised('motdepasse-tres-connu');
    $invitation = Invitation::issue('nouveau@cap.test', 'Nouveau Venu', Role::Commercial);

    $this->post("/activation/{$invitation->plainToken}", ['password' => 'motdepasse-tres-connu', 'password_confirmation' => 'motdepasse-tres-connu'])
        ->assertSessionHasErrors('password');

    expect(User::count())->toBe(0);
});

it('invitation token is single use and expires after 24h', function () {
    inTenant();
    $first = Invitation::issue('un@cap.test', 'Un', Role::Commercial);
    $second = Invitation::issue('deux@cap.test', 'Deux', Role::Assistant);
    $password = ['password' => 'un-mot-de-passe-solide', 'password_confirmation' => 'un-mot-de-passe-solide'];

    $this->post("/activation/{$first->plainToken}", $password)->assertRedirect('/login');
    $this->post("/activation/{$first->plainToken}", $password)->assertNotFound();
    $this->get("/activation/{$first->plainToken}")->assertNotFound();

    $this->travel(25)->hours();

    $this->get("/activation/{$second->plainToken}")->assertStatus(410);
    $this->post("/activation/{$second->plainToken}", $password)->assertStatus(410);
    expect(User::where('email', 'deux@cap.test')->exists())->toBeFalse();
});

it('an invitation of tenant A cannot be used on tenant B', function () {
    config(['tms.mode' => 'multi']);
    $a = tenantOnHost('a.test');
    tenantOnHost('b.test');
    $invitation = app(TenantContext::class)->run($a, fn () => Invitation::issue('x@y.fr', 'X', Role::Commercial));

    $this->get("http://b.test/activation/{$invitation->plainToken}")->assertNotFound();
    $this->post("http://b.test/activation/{$invitation->plainToken}", [
        'password' => 'un-mot-de-passe-solide', 'password_confirmation' => 'un-mot-de-passe-solide',
    ])->assertNotFound();
});

it('an unknown token gives 404', function () {
    $this->get('/activation/'.str_repeat('a', 128))->assertNotFound();
});

// ---------------------------------------------------------------- administration

it('lists only the users of the current tenant', function () {
    config(['tms.mode' => 'multi']);
    $a = tenantOnHost('a.test');
    $b = tenantOnHost('b.test');
    $admin = app(TenantContext::class)->run($a, fn () => User::factory()
        ->withRole(Role::AdminOrganisme)->withTwoFactor()->create(['name' => 'Admin A']));
    userIn($a, ['name' => 'Collègue A']);
    userIn($b, ['name' => 'Intrus B']);
    app(TenantContext::class)->run($a, fn () => Invitation::issue('invite@a.test', 'Invité A', Role::Assistant));

    $this->actingAs($admin)->get('http://a.test/admin/users')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/users/index')
            ->has('users', 2)
            ->where('users.0.name', 'Admin A')
            ->where('users.1.name', 'Collègue A')
            ->has('invitations', 1)
            ->where('invitations.0.email', 'invite@a.test')
            ->where('invitations.0.role', 'Assistant'));
});

it('invites a user by e-mail', function () {
    Notification::fake();
    $admin = admin(twoFactor: true);

    $this->actingAs($admin)->get('/admin/users/invite')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/users/invite')->has('roles', count(Role::cases())));

    $this->actingAs($admin)->post('/admin/users/invitations', [
        'email' => 'Nouveau@Cap.test', 'name' => 'Nouveau Venu', 'role' => 'commercial',
    ])->assertSessionHasNoErrors()->assertRedirect('/admin/users');

    $invitation = Invitation::sole();
    expect($invitation->email)->toBe('nouveau@cap.test')
        ->and($invitation->role)->toBe(Role::Commercial)
        ->and($invitation->invited_by)->toBe($admin->id);

    Notification::assertSentOnDemand(InvitationNotification::class, function (InvitationNotification $n, array $channels, AnonymousNotifiable $to) use ($invitation) {
        return $to->routes['mail'] === 'nouveau@cap.test'
            && hash('sha256', basename($n->url)) === $invitation->token_hash
            && str_contains($n->url, '/activation/');
    });
});

it('validates invitations', function () {
    $admin = admin(twoFactor: true);
    User::factory()->create(['email' => 'deja@cap.test']);

    $this->actingAs($admin)->post('/admin/users/invitations', ['email' => 'deja@cap.test', 'name' => 'Doublon', 'role' => 'commercial'])
        ->assertSessionHasErrors('email');
    $this->actingAs($admin)->post('/admin/users/invitations', ['email' => 'pas-un-email', 'name' => '', 'role' => 'pape'])
        ->assertSessionHasErrors(['email', 'name', 'role']);

    expect(Invitation::count())->toBe(0);
});

it('a new invitation replaces the pending one for the same e-mail', function () {
    inTenant();
    $old = Invitation::issue('x@y.fr', 'X', Role::Commercial);
    Invitation::issue('x@y.fr', 'X', Role::Assistant);

    expect(Invitation::count())->toBe(1);
    $this->get("/activation/{$old->plainToken}")->assertNotFound();
});

it('refuses user administration without the users.manage permission', function () {
    $user = commercial();

    $this->actingAs($user)->get('/admin/users')->assertForbidden();
    $this->actingAs($user)->get('/admin/users/invite')->assertForbidden();
    $this->actingAs($user)->post('/admin/users/invitations', ['email' => 'x@y.fr', 'name' => 'X', 'role' => 'commercial'])->assertForbidden();
});

it('requires authentication for user administration', function () {
    $this->get('/admin/users')->assertRedirect('/login');
});

// ---------------------------------------------------------------- external identities

it('external identities belong to a user of the current tenant', function () {
    $a = inTenant();
    $user = User::factory()->create();
    $identity = $user->externalIdentities()->create(['provider' => 'oidc', 'subject' => 'sub-123']);

    expect($identity->tenant_id)->toBe($a->id)
        ->and($identity->user->is($user))->toBeTrue()
        ->and(ExternalIdentity::count())->toBe(1);

    app(TenantContext::class)->clear();
    expect(ExternalIdentity::withoutGlobalScopes()->count())->toBe(0);
});
