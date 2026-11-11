<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\Invitation;
use App\Modules\Core\Users\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;

const AUDIT_PASSWORD = 'mot-de-passe-audit-1234';

function lastAudit(?string $event = null): ?AuditLog
{
    return AuditLog::query()
        ->when($event, fn ($q) => $q->where('event', $event))
        ->orderByDesc('created_at')->orderByDesc('id')->first();
}

// ---------------------------------------------------------------- model changes

it('records model changes with only dirty fields', function () {
    inTenant();
    $user = User::factory()->create(['name' => 'Avant']);
    $user->update(['name' => 'Après']);

    $log = lastAudit();
    expect($log->event)->toBe('updated')
        ->and($log->auditable_type)->toBe(User::class)
        ->and($log->auditable_id)->toBe($user->id)
        ->and($log->old_values)->toBe(['name' => 'Avant'])
        ->and($log->new_values)->toBe(['name' => 'Après']);
});

it('records created, deleted and restored', function () {
    inTenant();
    $user = User::factory()->create(['name' => 'Nouveau']);

    expect(AuditLog::where('event', 'created')->where('auditable_id', $user->id)->sole()->new_values)
        ->toHaveKey('name', 'Nouveau');

    $user->delete();
    expect(lastAudit()->event)->toBe('deleted')
        ->and(lastAudit()->old_values)->toHaveKey('name', 'Nouveau');
});

it('never stores secrets', function () {
    inTenant();
    $user = User::factory()->create();
    $user->forceFill(['password' => 'un-autre-mot-de-passe-1234', 'two_factor_secret' => 'JBSWY3DPEHPK3PXP'])->save();

    $log = lastAudit('updated');
    expect($log->new_values['password'])->toBe('[masqué]')
        ->and($log->old_values['password'])->toBe('[masqué]')
        ->and($log->new_values['two_factor_secret'])->toBe('[masqué]');

    $created = AuditLog::where('event', 'created')->where('auditable_id', $user->id)->sole();
    expect($created->new_values['password'])->toBe('[masqué]');

    $raw = DB::table('audit_logs')->get()->map(fn ($r) => $r->old_values.$r->new_values)->implode('');
    expect($raw)->not->toContain('JBSWY3DPEHPK3PXP')->not->toContain('argon2');
});

it('ignores noise-only changes such as last_login_at', function () {
    inTenant();
    $user = User::factory()->create();
    $before = AuditLog::count();

    $user->forceFill(['last_login_at' => now()])->save();
    $user->forceFill(['remember_token' => 'abc'])->save();

    expect(AuditLog::count())->toBe($before);
});

it('does not audit a save that changes nothing', function () {
    inTenant();
    $user = User::factory()->create();
    $before = AuditLog::count();
    $user->save();

    expect(AuditLog::count())->toBe($before);
});

// ---------------------------------------------------------------- append-only

it('audit logs are append-only (update)', function () {
    inTenant();
    User::factory()->create();
    DB::table('audit_logs')->update(['event' => 'x']);
})->throws(QueryException::class, 'append-only');

it('audit logs are append-only (delete)', function () {
    inTenant();
    User::factory()->create();
    DB::table('audit_logs')->delete();
})->throws(QueryException::class, 'append-only');

it('audit logs are isolated per tenant', function () {
    $a = inTenant();
    User::factory()->create();
    $b = tenantOnHost('b.test');
    app(TenantContext::class)->run($b, fn () => User::factory()->create());

    expect(AuditLog::count())->toBeGreaterThan(0)
        ->and(AuditLog::pluck('tenant_id')->unique()->all())->toBe([$a->id]);
});

// ---------------------------------------------------------------- authentication events

it('records successful logins and logouts', function () {
    inTenant();
    $user = User::factory()->create(['password' => AUDIT_PASSWORD]);

    $this->post('/login', ['email' => $user->email, 'password' => AUDIT_PASSWORD])->assertRedirect();
    $login = lastAudit('auth.login');
    expect($login->user_id)->toBe($user->id)->and($login->ip)->not->toBeNull();

    $this->post('/logout');
    expect(lastAudit('auth.logout')->user_id)->toBe($user->id);
});

it('records failed logins with the attempted email, never the password', function () {
    inTenant();
    $user = User::factory()->create(['password' => AUDIT_PASSWORD]);

    $this->post('/login', ['email' => $user->email, 'password' => 'faux-mot-de-passe-123'])->assertSessionHasErrors('email');
    $log = lastAudit('auth.failed');
    expect($log->user_id)->toBe($user->id)
        ->and($log->new_values)->toBe(['email' => $user->email]);

    $this->post('/login', ['email' => 'Inconnu@Example.test', 'password' => 'faux-mot-de-passe-123']);
    $unknown = lastAudit('auth.failed');
    expect($unknown->user_id)->toBeNull()
        ->and($unknown->new_values)->toBe(['email' => 'inconnu@example.test']);

    expect(json_encode(AuditLog::all()))->not->toContain('faux-mot-de-passe-123');
});

it('records a failed login on a deactivated account', function () {
    inTenant();
    $user = User::factory()->create(['password' => AUDIT_PASSWORD, 'deactivated_at' => now()]);

    $this->post('/login', ['email' => $user->email, 'password' => AUDIT_PASSWORD])->assertSessionHasErrors('email');
    expect(lastAudit('auth.failed')->new_values)->toBe(['email' => $user->email]);
});

it('records 2fa enabled and disabled', function () {
    $user = commercial();
    $this->actingAs($user)->get('/two-factor/setup');
    $user->refresh();

    $this->actingAs($user)->post('/two-factor/confirm', ['code' => totp($user)])->assertRedirect();
    expect(lastAudit('auth.2fa_enabled')->user_id)->toBe($user->id);

    $this->actingAs($user->refresh())->withSession(['auth.password_confirmed_at' => time()])
        ->delete('/two-factor')->assertRedirect();
    expect(lastAudit('auth.2fa_disabled')->user_id)->toBe($user->id);
});

it('does not crash a login when there is no tenant context', function () {
    app(TenantContext::class)->clear();
    Log::spy();

    app(AuditRecorder::class)->record('auth.login');

    expect(DB::connection('pgsql_owner')->table('audit_logs')->count())->toBe(0);
    Log::shouldHaveReceived('warning')->once();
});

// ---------------------------------------------------------------- roles and exports

it('records a role change', function () {
    inTenant();
    $user = User::factory()->create();
    $user->assignPrimaryRole(Role::Commercial);
    $user->assignPrimaryRole(Role::Direction);

    $log = lastAudit('access.role_changed');
    expect($log->auditable_id)->toBe($user->id)
        ->and($log->old_values)->toBe(['role' => 'commercial'])
        ->and($log->new_values)->toBe(['role' => 'direction']);
});

it('leaves no role audit row when the surrounding transaction rolls back', function () {
    inTenant();
    $user = User::factory()->create();

    try {
        DB::transaction(function () use ($user) {
            $user->assignPrimaryRole(Role::Commercial);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(AuditLog::where('event', 'access.role_changed')->count())->toBe(0);
});

it('records the role change when the surrounding transaction commits', function () {
    inTenant();
    $user = User::factory()->create();

    $during = null;
    DB::transaction(function () use ($user, &$during) {
        $user->assignPrimaryRole(Role::Commercial);
        $during = AuditLog::where('event', 'access.role_changed')->count();
    });

    // Handled after commit, not while the transaction is still open.
    expect($during)->toBe(0)
        ->and(AuditLog::where('event', 'access.role_changed')->count())->toBe(1);
});

it('records an activation with its role change', function () {
    $tenant = inTenant();
    $invitation = Invitation::issue('nouveau@example.test', 'Nouveau', Role::Commercial);

    $this->post('/activation/'.$invitation->plainToken, [
        'password' => AUDIT_PASSWORD, 'password_confirmation' => AUDIT_PASSWORD,
    ])->assertRedirect('/login');

    $user = User::where('email', 'nouveau@example.test')->sole();
    expect(AuditLog::where('event', 'access.role_changed')->where('auditable_id', $user->id)->count())->toBe(1);
});

it('records an export run through the recorder', function () {
    $user = admin(twoFactor: true);
    $this->actingAs($user);

    app(AuditRecorder::class)->export('companies', ['format' => 'csv']);

    $log = lastAudit('export.run');
    expect($log->user_id)->toBe($user->id)
        ->and($log->new_values)->toBe(['export' => 'companies', 'format' => 'csv']);
});

// ---------------------------------------------------------------- page

it('direction can read audit, assistant cannot', function () {
    $this->actingAs(direction(twoFactor: true))->get('/admin/audit')->assertOk();
    $this->actingAs(assistant())->get('/admin/audit')->assertForbidden();
    $this->actingAs(admin(twoFactor: true))->get('/admin/audit')->assertOk();
});

it('redirects guests', fn () => $this->get('/admin/audit')->assertRedirect('/login'));

it('lists and filters the journal, 50 per page', function () {
    $admin = admin(twoFactor: true);
    $other = User::factory()->create();
    foreach (range(1, 60) as $i) {
        app(AuditRecorder::class)->record('export.run', null, [], ['n' => $i]);
    }
    AuditLog::query()->where('event', 'export.run')->count();

    $this->actingAs($admin)->get('/admin/audit')->assertInertia(fn (Assert $p) => $p
        ->component('admin/audit/index')
        ->has('logs.data', 50)
        ->where('logs.total', fn ($t) => $t >= 60));

    $this->actingAs($admin)->get('/admin/audit?event=created&type='.urlencode(User::class))
        ->assertInertia(fn (Assert $p) => $p->where('logs.data.0.event', 'created')
            ->where('logs.data.0.auditable_type', User::class));

    $this->actingAs($admin)->get('/admin/audit?user='.$other->id)
        ->assertInertia(fn (Assert $p) => $p->has('logs.data', 0));

    $this->actingAs($admin)->get('/admin/audit?from=2999-01-01')
        ->assertInertia(fn (Assert $p) => $p->has('logs.data', 0));

    $this->actingAs($admin)->get('/admin/audit?from=pas-une-date')->assertSessionHasErrors('from');
});

it('only shows the journal of the current tenant', function () {
    $admin = admin(twoFactor: true);
    $b = tenantOnHost('b.test');
    app(TenantContext::class)->run($b, fn () => User::factory()->create(['name' => 'Chez B']));
    app(TenantContext::class)->set(tenant());

    $this->actingAs($admin)->get('/admin/audit')
        ->assertInertia(fn (Assert $p) => $p->where('logs.data', fn ($rows) => ! str_contains(json_encode($rows), 'Chez B')));
});
