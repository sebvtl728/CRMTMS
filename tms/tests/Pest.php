<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantDomain;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\Import\FieldMap;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Import\ImportUploads;
use App\Modules\Crm\Import\RunImport;
use App\Modules\Crm\Import\SimulationReport;
use App\Modules\Crm\Import\Simulator;
use App\Modules\Crm\Import\ValueMapper;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\RefreshDatabaseAsOwner;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabaseAsOwner::class)
    ->in('Feature', 'Unit', 'Arch');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Sets the tenant context for the test body (default tenant when null) and
 * returns the tenant. Requests restore it afterwards (Tests\TestCase::call).
 */
function inTenant(?Tenant $tenant = null): Tenant
{
    $tenant ??= Tenant::query()->where('slug', config('tms.default_tenant'))->sole();
    app(TenantContext::class)->set($tenant);

    return $tenant;
}

/**
 * A tenant reachable on $host (multi mode).
 */
function tenantOnHost(string $host): Tenant
{
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->for($tenant)->create(['host' => $host]);

    return $tenant;
}

/**
 * Creates a user inside $tenant without changing the current context.
 *
 * @param  array<string, mixed>  $attributes
 */
function userIn(Tenant $tenant, array $attributes = []): User
{
    return app(TenantContext::class)
        ->run($tenant, fn () => User::factory()->create($attributes));
}

/**
 * The tenant the test body acts for: the current context, or the default
 * tenant (which then becomes the context).
 */
function tenant(): Tenant
{
    return app(TenantContext::class)->current() ?? inTenant();
}

/**
 * A user of the current tenant (see tenant()) holding $role. Without
 * two-factor authentication unless $twoFactor (then confirmed, with a secret
 * and 8 recovery codes).
 */
function userWithRole(Role $role, bool $twoFactor = false): User
{
    tenant();
    $factory = User::factory()->withRole($role);

    return ($twoFactor ? $factory->withTwoFactor() : $factory)->create();
}

function admin(bool $twoFactor = false): User
{
    return userWithRole(Role::AdminOrganisme, $twoFactor);
}

function direction(bool $twoFactor = false): User
{
    return userWithRole(Role::Direction, $twoFactor);
}

function commercial(bool $twoFactor = false): User
{
    return userWithRole(Role::Commercial, $twoFactor);
}

function assistant(bool $twoFactor = false): User
{
    return userWithRole(Role::Assistant, $twoFactor);
}

/**
 * Current TOTP code of a user with a two-factor secret.
 */
function totp(User $user, ?int $timestamp = null): string
{
    $google2fa = new Google2FA;

    return $timestamp === null
        ? $google2fa->getCurrentOtp((string) $user->two_factor_secret)
        : $google2fa->oathTotp((string) $user->two_factor_secret, intdiv($timestamp, 30));
}

/*
|--------------------------------------------------------------------------
| CRM helpers (people and affiliations tests)
|--------------------------------------------------------------------------
*/

function crmItem(ReferenceList $list, string $label): ReferenceItem
{
    return ReferenceItem::query()->where('list', $list)->where('label', $label)->firstOrFail();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function makePerson(string $lastName, array $attributes = []): Person
{
    return Person::query()->create(['last_name' => $lastName, ...$attributes]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function affiliate(Person $person, Organisation $organisation, array $attributes = []): Affiliation
{
    return Affiliation::query()->create(['person_id' => $person->id, 'organisation_id' => $organisation->id, ...$attributes]);
}

function followDas(User $user, ReferenceItem $das): void
{
    DB::table('user_das')->insert(['tenant_id' => tenant()->id, 'user_id' => $user->id, 'das_id' => $das->id]);
}

/*
|--------------------------------------------------------------------------
| CRM import helpers
|--------------------------------------------------------------------------
*/

/**
 * Path of a fixture file, or of a temporary file holding $content.
 */
function importSource(string $fixtureOrContent, string $extension = 'csv'): string
{
    $fixture = base_path('tests/Fixtures/imports/'.$fixtureOrContent);
    if (! str_contains($fixtureOrContent, "\n") && is_file($fixture)) {
        return $fixture;
    }
    $path = tempnam(sys_get_temp_dir(), 'imp').'.'.$extension;
    file_put_contents($path, $fixtureOrContent);

    return $path;
}

/**
 * An uploaded batch for the authenticated user.
 *
 * @param  array<string, mixed>  $options
 */
function uploadImport(string $fixtureOrContent, ImportTarget $target, array $options = [], ?string $name = null): ImportBatch
{
    $path = importSource($fixtureOrContent);
    $name ??= is_file(base_path('tests/Fixtures/imports/'.$fixtureOrContent)) ? $fixtureOrContent : 'fichier.csv';
    $user = auth()->user();
    assert($user instanceof User);

    return app(ImportUploads::class)->store($user, $path, $name, $target, $options);
}

/**
 * Simulates with the proposed mapping (overridden by $map entries).
 *
 * @param  array<string, string|null>  $map  column => field
 * @param  array<string, array<string, string>>  $values
 */
function simulateImport(ImportBatch $batch, array $map = [], array $values = []): SimulationReport
{
    $guess = FieldMap::guess($batch->target, $batch->reader()->headers())->toArray();
    $fieldMap = FieldMap::fromArray($batch->target, array_merge($guess, $map));

    return app(Simulator::class)->run($batch, $fieldMap, new ValueMapper($values));
}

/**
 * Uploads, simulates and runs (sync queue); returns the refreshed batch.
 *
 * @param  array<string, mixed>  $options
 * @param  array<string, string|null>  $map
 * @param  array<string, array<string, string>>  $values
 */
function runImport(string $fixtureOrContent, ImportTarget $target, array $options = [], array $map = [], array $values = []): ImportBatch
{
    $batch = uploadImport($fixtureOrContent, $target, $options);
    simulateImport($batch, $map, $values);
    RunImport::dispatch($batch->id);

    return $batch->refresh();
}

/**
 * @return list<string> messages of the import row at $line
 */
function rowMessages(ImportBatch $batch, int $line): array
{
    $row = $batch->rows()->where('line', $line)->firstOrFail();

    return array_map(fn (array $m) => $m['text'], $row->messages);
}
