<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Platform\Sandbox;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Core\Users\User;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    fakeImportDisk();
});

function sbOn(): void
{
    config(['tms.sandbox' => true]);
}

it('is no sandbox in production, even with TMS_SANDBOX, nor without the flag', function () {
    config(['tms.sandbox' => true]);
    app()->detectEnvironment(fn () => 'production');
    expect(Sandbox::enabled())->toBeFalse();

    app()->detectEnvironment(fn () => 'testing');
    config(['tms.sandbox' => false]);
    expect(Sandbox::enabled())->toBeFalse();
});

it('refuses to erase anything outside a sandbox: command, page and action', function () {
    Organisation::query()->create(['name' => 'Hôtel du Parc']);

    $this->artisan('tms:sandbox:reset', ['--mode' => 'work', '--force' => true])
        ->expectsOutputToContain('Réinitialisation refusée')->assertFailed();
    $this->actingAs(admin(twoFactor: true))->get('/admin/sandbox')->assertNotFound();
    $this->post('/admin/sandbox/reset', ['mode' => 'work', 'confirmation' => 'RÉINITIALISER'])->assertNotFound();

    expect(Organisation::query()->count())->toBe(1);
});

it('erases the working data, keeps the accounts and the settings, and makes the company fictitious', function () {
    sbOn();
    $admin = admin(twoFactor: true);
    $other = commercial();
    Organisation::query()->create(['name' => 'Hôtel du Parc']);
    makeOpportunity(Organisation::query()->firstOrFail());
    $company = Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create();
    $company->forceFill(['name' => 'CAP FORMATION', 'siret' => '73282932000074', 'quote_footer' => 'Vraies mentions'])->save();
    Storage::disk(TenantStorage::DISK)->put(TenantStorage::path('quotes', 'x.pdf'), 'pdf');
    $references = ReferenceItem::query()->count();

    $this->actingAs($admin)->post('/admin/sandbox/reset', ['mode' => 'work', 'confirmation' => 'reinitialiser'])
        ->assertSessionHasErrors(['confirmation' => 'Tapez RÉINITIALISER pour confirmer.']);
    expect(Organisation::query()->count())->toBe(1);

    $this->withoutExceptionHandling()->post('/admin/sandbox/reset', ['mode' => 'work', 'confirmation' => 'RÉINITIALISER'])->assertRedirect('/admin/sandbox');

    expect(Organisation::query()->count())->toBe(0)
        ->and(DB::table('opportunities')->count())->toBe(0)
        ->and(User::query()->whereKey([$admin->id, $other->id])->count())->toBe(2)
        ->and(ReferenceItem::query()->count())->toBe($references)
        ->and(Storage::disk(TenantStorage::DISK)->exists(TenantStorage::path('quotes', 'x.pdf')))->toBeFalse()
        ->and(Company::query()->count())->toBe(1)
        ->and(Company::query()->sole()->only(['name', 'siret', 'quote_footer', 'address_city']))
        ->toBe(['name' => 'Organisme de démonstration', 'siret' => null, 'quote_footer' => null, 'address_city' => 'Paris'])
        ->and(Tenant::query()->sole()->name)->toBe('Organisme de démonstration');
});

it('starts again from scratch, keeping only the administrator who asked, still signed in', function () {
    sbOn();
    $admin = admin(twoFactor: true);
    $password = $admin->password;
    commercial();
    Organisation::query()->create(['name' => 'Hôtel du Parc']);

    $this->actingAs($admin)->post('/admin/sandbox/reset', ['mode' => 'all', 'confirmation' => 'RÉINITIALISER'])->assertRedirect('/admin/sandbox');
    // The organisme was created again: read it in its own context.
    inTenant(Tenant::query()->sole());

    $users = User::query()->withoutGlobalScopes()->get();
    expect($users)->toHaveCount(1)
        ->and($users[0]->id)->toBe($admin->id)
        ->and($users[0]->password)->toBe($password)
        ->and($users[0]->hasRole(Role::AdminOrganisme->value))->toBeTrue()
        ->and(DB::table('organisations')->count())->toBe(0)
        ->and(Tenant::query()->sole()->name)->toBe('Organisme de démonstration')
        ->and(ReferenceItem::query()->withoutGlobalScopes()->count())->toBeGreaterThan(0)
        ->and(Company::query()->withoutGlobalScopes()->sole()->name)->toBe('Organisme de démonstration');

    // A new request reads his account again (his roles were created again).
    $this->actingAs(User::query()->findOrFail($admin->id))->get('/admin/sandbox')->assertOk();
});

it('shows the page to settings managers of a sandbox only', function () {
    sbOn();
    $this->actingAs(admin(twoFactor: true))->get('/admin/sandbox')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('admin/sandbox'));
    $this->actingAs(commercial())->get('/admin/sandbox')->assertForbidden();
});

it('keeps no table pointing to an erased one (else the reset fails rather than empty it silently)', function () {
    expect(Sandbox::keptTablesReferencingErased())->toBe([]);
});
