<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantStatus;
use App\Modules\Core\Users\Invitation;
use App\Modules\Core\Users\InvitationNotification;
use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * @return array<string, string>
 */
function installArgs(): array
{
    return [
        '--tenant-name' => 'CAP FORMATION',
        '--admin-email' => 'admin@cap.test',
        '--admin-name' => 'Admin',
    ];
}

/**
 * Fresh platform: the test case provisions a default tenant, an install
 * starts from an empty tenants table.
 */
function emptyPlatform(): void
{
    Tenant::query()->delete();
    app(TenantContext::class)->clear();
}

it('creates a tenant with domains and prints its uuid', function () {
    $this->artisan('tms:tenant:create', ['name' => 'CAP FORMATION', '--slug' => 'cap', '--domain' => ['cap.test']])
        ->assertSuccessful();

    $tenant = Tenant::where('slug', 'cap')->firstOrFail();
    expect($tenant->domains->pluck('host')->all())->toBe(['cap.test'])
        ->and($tenant->status)->toBe(TenantStatus::Active);
});

it('derives the slug from the name and seeds the roles', function () {
    $this->artisan('tms:tenant:create', ['name' => 'Cap Formation Sud'])
        ->expectsOutputToContain('cap-formation-sud')
        ->assertSuccessful();

    $tenant = Tenant::where('slug', 'cap-formation-sud')->firstOrFail();
    $roles = app(TenantContext::class)->run($tenant, fn () => Spatie\Permission\Models\Role::query()->count());
    expect($roles)->toBe(count(Role::cases()));
});

it('refuses a duplicate slug or domain', function () {
    tenantOnHost('taken.test');
    $slug = Tenant::query()->latest('created_at')->firstOrFail()->slug;

    $this->artisan('tms:tenant:create', ['name' => 'X', '--slug' => $slug])->assertFailed();
    $this->artisan('tms:tenant:create', ['name' => 'Y', '--slug' => 'other', '--domain' => ['taken.test']])->assertFailed();
    expect(Tenant::where('slug', 'other')->exists())->toBeFalse();
});

it('suspends and reactivates a tenant', function () {
    $this->artisan('tms:tenant:create', ['name' => 'CAP', '--slug' => 'cap'])->assertSuccessful();

    $this->artisan('tms:tenant:suspend', ['slug' => 'cap'])->assertSuccessful();
    expect(Tenant::where('slug', 'cap')->first()->status)->toBe(TenantStatus::Suspended);

    $this->artisan('tms:tenant:suspend', ['slug' => 'cap', '--reactivate' => true])->assertSuccessful();
    expect(Tenant::where('slug', 'cap')->first()->status)->toBe(TenantStatus::Active);

    $this->artisan('tms:tenant:suspend', ['slug' => 'unknown'])->assertFailed();
});

it('serves 503 to a suspended tenant', function () {
    config(['tms.mode' => 'multi']);
    $this->artisan('tms:tenant:create', ['name' => 'CAP', '--slug' => 'cap', '--domain' => ['cap.test']])->assertSuccessful();
    $this->artisan('tms:tenant:suspend', ['slug' => 'cap'])->assertSuccessful();

    $this->get('http://cap.test/login')->assertStatus(503)->assertSee('Organisme suspendu');
});

it('install is idempotent', function () {
    emptyPlatform();

    $this->artisan('tms:install', installArgs())->assertSuccessful();
    $this->artisan('tms:install', installArgs())
        ->expectsOutputToContain('Installation déjà initialisée.')
        ->assertFailed();

    expect(Tenant::count())->toBe(1);
    inTenant(Tenant::sole());
    expect(User::withoutGlobalScopes()->count())->toBe(1);
});

it('install refuses when any tenant exists and creates nothing', function () {
    $this->artisan('tms:install', installArgs())
        ->expectsOutputToContain('Installation déjà initialisée.')
        ->assertExitCode(1);

    inTenant();
    expect(User::withoutGlobalScopes()->count())->toBe(0);
});

it('install creates an admin who must activate via a single-use link', function () {
    emptyPlatform();
    Notification::fake();

    $this->artisan('tms:install', installArgs() + ['--domain' => 'cap.test'])
        ->expectsOutputToContain('/activation/')
        ->assertSuccessful();

    $tenant = Tenant::sole();
    expect($tenant->name)->toBe('CAP FORMATION')
        ->and($tenant->domains->pluck('host')->all())->toBe(['cap.test']);

    inTenant($tenant); // RLS: users are only visible inside their tenant
    $admin = User::withoutGlobalScopes()->firstWhere('email', 'admin@cap.test');
    expect($admin->password)->toBeNull()
        ->and($admin->tenant_id)->toBe($tenant->id);
    expect($admin->fresh()->hasRole(Role::AdminOrganisme->value))->toBeTrue();

    $invitation = Invitation::query()->pending()->where('email', 'admin@cap.test')->sole();
    expect($invitation->role)->toBe(Role::AdminOrganisme->value)
        ->and($invitation->expires_at->isBetween(now()->addHours(23), now()->addHours(25)))->toBeTrue();

    Notification::assertSentOnDemand(InvitationNotification::class);
});

it('install prints a usable activation url without a password', function () {
    emptyPlatform();
    config(['app.url' => 'https://tms.example.org']);

    $this->artisan('tms:install', installArgs())
        ->expectsOutputToContain('https://tms.example.org/activation/')
        ->assertSuccessful();
});

it('install uses the given slug so single mode finds the tenant', function () {
    emptyPlatform();

    $this->artisan('tms:install', installArgs() + ['--slug' => 'cap-formation'])->assertSuccessful();

    expect(Tenant::sole()->slug)->toBe('cap-formation');
});

it('install rejects an unusable slug and creates nothing', function () {
    emptyPlatform();

    $this->artisan('tms:install', installArgs() + ['--slug' => 'Bad Slug!'])
        ->expectsOutputToContain('Identifiant')
        ->assertFailed();

    expect(Tenant::count())->toBe(0);
});

it('rejects malformed domains', function (string $bad) {
    $this->artisan('tms:tenant:create', ['name' => 'Bad', '--slug' => 'bad', '--domain' => [$bad]])
        ->expectsOutputToContain('Domaine invalide')
        ->assertFailed();
    expect(Tenant::where('slug', 'bad')->exists())->toBeFalse();

    emptyPlatform();
    $this->artisan('tms:install', installArgs() + ['--domain' => $bad])->assertFailed();
    expect(Tenant::count())->toBe(0);
})->with(['http://x.test', 'a b.test', 'cap.test/path', 'foo.test:8080', 'nodot', '-a.test', 'a..test', ' ']);

it('accepts localhost and dotted hosts', function () {
    $this->artisan('tms:tenant:create', ['name' => 'L', '--slug' => 'l', '--domain' => ['localhost', 'Sub.Cap-1.test']])
        ->assertSuccessful();
    expect(Tenant::where('slug', 'l')->first()->domains->pluck('host')->sort()->values()->all())
        ->toBe(['localhost', 'sub.cap-1.test']);
});

it('install is atomic: a failure after the tenant leaves nothing and can be retried', function () {
    emptyPlatform();
    $fail = true;
    Invitation::creating(function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => Artisan::call('tms:install', installArgs()))->toThrow(RuntimeException::class);
    expect(Tenant::count())->toBe(0);

    $fail = false;
    $this->artisan('tms:install', installArgs())->assertSuccessful();
    expect(Tenant::count())->toBe(1);
});

it('tenant:create is atomic when role seeding fails', function () {
    Tenant::created(fn () => throw new RuntimeException('boom'));

    expect(fn () => Artisan::call('tms:tenant:create', ['name' => 'Z', '--slug' => 'zed']))->toThrow(RuntimeException::class);
    expect(Tenant::where('slug', 'zed')->exists())->toBeFalse();
});

it('turns a concurrent unique violation into a French error', function () {
    // Simulates a racing process: the conflicting row appears after the checks.
    Tenant::creating(function () {
        DB::table('tenants')->insert([
            'id' => (string) Str::uuid(), 'name' => 'Racer', 'slug' => 'race', 'status' => 'active',
            'settings' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $this->artisan('tms:tenant:create', ['name' => 'Race', '--slug' => 'race'])
        ->expectsOutputToContain('déjà utilisé')
        ->assertFailed();
});
