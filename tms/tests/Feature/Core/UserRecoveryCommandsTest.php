<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\Invitation;
use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Runs $fn in $tenant's context (RLS) and leaves no context behind, like the
 * console where the commands run.
 */
function inTenantOnce(Tenant $tenant, callable $fn): mixed
{
    return app(TenantContext::class)->run($tenant, $fn);
}

beforeEach(function () {
    config(['app.url' => 'https://tms.example.org']);
    $this->tenant = inTenant();
    app(TenantContext::class)->clear();
});

// ---------------------------------------------------------------- activation link

it('reissues an activation link for an account that never chose a password', function () {
    $user = inTenantOnce($this->tenant, function () {
        $user = User::factory()->create(['email' => 'admin@cap.test', 'password' => null]);
        $user->assignPrimaryRole(Role::AdminOrganisme);
        Invitation::issue('admin@cap.test', $user->name, RoleModel::forSystem(Role::AdminOrganisme));

        return $user;
    });

    $this->artisan('tms:user:activation-link', ['email' => 'Admin@Cap.test'])
        ->expectsOutputToContain('https://tms.example.org/activation/')
        ->doesntExpectOutputToContain('mot de passe :')
        ->assertSuccessful();

    inTenantOnce($this->tenant, function () use ($user) {
        $invitation = Invitation::query()->pending()->where('email', 'admin@cap.test')->sole();
        expect($invitation->role)->toBe('admin_organisme')
            ->and($user->fresh()->password)->toBeNull();

        $log = AuditLog::query()->where('event', 'users.activation_reissued')->sole();
        expect($log->new_values)->toMatchArray(['email' => 'admin@cap.test', 'role' => 'admin_organisme'])
            ->and(json_encode($log))->not->toContain($invitation->token_hash);
    });
});

it('invites a new address with the given role', function () {
    $this->artisan('tms:user:activation-link', ['email' => 'secours@cap.test', '--role' => 'admin_organisme'])
        ->expectsOutputToContain('/activation/')
        ->assertSuccessful();

    inTenantOnce($this->tenant, function () {
        expect(Invitation::query()->pending()->where('email', 'secours@cap.test')->sole()->role)->toBe('admin_organisme');
    });
});

it('the printed link activates the account with the given role', function () {
    Artisan::call('tms:user:activation-link', ['email' => 'secours@cap.test', '--role' => 'admin_organisme']);
    expect(preg_match('#/activation/([0-9a-f]{128})#', Artisan::output(), $m))->toBe(1);

    inTenant($this->tenant);
    $this->post("/activation/{$m[1]}", ['password' => 'un-mot-de-passe-solide', 'password_confirmation' => 'un-mot-de-passe-solide'])
        ->assertRedirect('/login');

    expect(User::query()->where('email', 'secours@cap.test')->sole()->role()?->systemRole())->toBe(Role::AdminOrganisme);
});

it('requires a role for a new address and refuses unknown roles', function () {
    $this->artisan('tms:user:activation-link', ['email' => 'secours@cap.test'])
        ->expectsOutputToContain('--role')
        ->assertFailed();
    $this->artisan('tms:user:activation-link', ['email' => 'secours@cap.test', '--role' => 'roi'])->assertFailed();

    inTenantOnce($this->tenant, fn () => expect(Invitation::query()->count())->toBe(0));
});

it('refuses an activated or deactivated account', function () {
    inTenantOnce($this->tenant, function () {
        User::factory()->create(['email' => 'actif@cap.test']);
        User::factory()->create(['email' => 'parti@cap.test', 'password' => null, 'deactivated_at' => now()]);
    });

    $this->artisan('tms:user:activation-link', ['email' => 'actif@cap.test'])
        ->expectsOutputToContain('déjà activé')
        ->assertFailed();
    $this->artisan('tms:user:activation-link', ['email' => 'parti@cap.test'])
        ->expectsOutputToContain('désactivé')
        ->assertFailed();

    inTenantOnce($this->tenant, fn () => expect(Invitation::query()->count())->toBe(0));
});

it('targets the tenant given by its slug and uses its domain in multi mode', function () {
    config(['tms.mode' => 'multi']);
    $other = tenantOnHost('autre.example.test');

    $this->artisan('tms:user:activation-link', ['email' => 'x@autre.test', '--role' => 'commercial', '--tenant' => $other->slug])
        ->expectsOutputToContain('https://autre.example.test/activation/')
        ->assertSuccessful();

    inTenantOnce($other, fn () => expect(Invitation::query()->count())->toBe(1));
    inTenantOnce($this->tenant, fn () => expect(Invitation::query()->count())->toBe(0));

    $this->artisan('tms:user:activation-link', ['email' => 'x@autre.test', '--role' => 'commercial', '--tenant' => 'inconnu'])
        ->expectsOutputToContain('Organisme introuvable')
        ->assertFailed();
});

// ---------------------------------------------------------------- reset 2FA

it('resets the two-factor authentication of an account and revokes its sessions', function () {
    $user = inTenantOnce($this->tenant, fn () => User::factory()->withRole(Role::AdminOrganisme)->withTwoFactor()->create(['email' => 'admin@cap.test']));
    $session = Str::random(40);
    DB::table('sessions')->insert(['id' => $session, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $token = $user->remember_token;

    $this->artisan('tms:user:reset-2fa', ['email' => 'admin@cap.test'])
        ->expectsOutputToContain('réinitialisée')
        ->assertSuccessful();

    $user = inTenantOnce($this->tenant, fn () => $user->fresh());
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and($user->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->remember_token)->not->toBe($token)
        ->and(DB::table('sessions')->where('id', $session)->exists())->toBeFalse();

    inTenantOnce($this->tenant, function () use ($user) {
        expect(AuditLog::query()->where('event', 'auth.2fa_reset_by_operator')->sole()->auditable_id)->toBe($user->id);
    });

    // Next login: an administrator is sent to the enrolment again.
    inTenant($this->tenant);
    $this->actingAs($user)->get('/dashboard')->assertRedirect('/two-factor/setup');
});

it('refuses to reset the two-factor authentication of an unknown account', function () {
    $this->artisan('tms:user:reset-2fa', ['email' => 'personne@cap.test'])
        ->expectsOutputToContain('Aucun compte')
        ->assertFailed();
});

it('recovery commands require --tenant in multi mode', function () {
    config(['tms.mode' => 'multi']);

    $this->artisan('tms:user:activation-link', ['email' => 'x@cap.test', '--role' => 'admin_organisme'])
        ->expectsOutputToContain('--tenant')
        ->assertFailed();
    $this->artisan('tms:user:reset-2fa', ['email' => 'x@cap.test'])
        ->expectsOutputToContain('--tenant')
        ->assertFailed();

    // Explicit tenant works.
    $this->artisan('tms:user:activation-link', ['email' => 'x@cap.test', '--role' => 'admin_organisme', '--tenant' => $this->tenant->slug])
        ->assertSuccessful();
});
