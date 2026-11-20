<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Access\RoleSeeder;
use App\Modules\Core\Companies\Company;
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
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\OpportunityStage;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteWriter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\PermissionRegistrar;
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
 * A user of the current tenant whose only role is a custom one holding
 * exactly $permissions (a role created directly, as an administrator would
 * from « Rôles et droits »).
 *
 * @param  list<string>  $permissions
 */
function userWithPermissions(array $permissions): User
{
    tenant();
    $role = RoleModel::create(['name' => 'custom_'.Str::uuid7(), 'guard_name' => RoleSeeder::GUARD]);
    $role->givePermissionTo($permissions);
    $user = User::factory()->create();
    $user->syncRoles([$role]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
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

/**
 * A private disk « local » in the container's own temporary directory: the
 * default fake lives on the host-mounted storage/, where a file just written
 * is now and then not yet readable (Colima file sharing), which made the
 * import tests flaky.
 */
function fakeImportDisk(): void
{
    $root = sys_get_temp_dir().'/tms-test-disk-'.getmypid();
    (new Filesystem)->deleteDirectory($root);
    Storage::set('local', Storage::createLocalDriver(['root' => $root, 'throw' => false]));
}

/*
|--------------------------------------------------------------------------
| CRM pipeline helpers (opportunities tests)
|--------------------------------------------------------------------------
*/

function pipelineStage(string $label): ReferenceItem
{
    return crmItem(ReferenceList::PipelineStage, $label);
}

/**
 * An opportunity created through the model (stage « Nouveau », DAS
 * « Hôtellerie-restauration » by default), with its first history row.
 *
 * @param  array<string, mixed>  $attributes
 */
function makeOpportunity(Organisation|Person $account, array $attributes = []): Opportunity
{
    return app(OpportunityStage::class)->create([
        $account instanceof Organisation ? 'organisation_id' : 'person_id' => $account->id,
        'title' => 'Formation HACCP',
        'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'stage_id' => pipelineStage('Nouveau')->id,
        ...$attributes,
    ]);
}

/*
|--------------------------------------------------------------------------
| CRM quote helpers
|--------------------------------------------------------------------------
*/

/**
 * A line of a quote as the form posts it (French typing).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function quoteLine(array $overrides = []): array
{
    return [
        'training_id' => null,
        'label' => 'Formation HACCP',
        'description' => null,
        'quantity' => '1',
        'unit' => 'stagiaire',
        'unit_price' => '1 000,00',
        'discount_percent' => '0',
        'vat_rate' => '20',
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>|null  $lines
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function quotePayload(Opportunity $opportunity, Company $company, ?array $lines = null, array $overrides = []): array
{
    return [
        'opportunity_id' => $opportunity->id,
        'company_id' => $company->id,
        'contact_person_id' => null,
        'issued_on' => now('Europe/Paris')->toDateString(),
        'valid_until' => now('Europe/Paris')->addDays(30)->toDateString(),
        'notes' => null,
        'lines' => $lines ?? [quoteLine()],
        ...$overrides,
    ];
}

/**
 * A draft quote written through QuoteWriter (number, totals), one line of
 * 1 000,00 € HT by default.
 *
 * @param  list<array<string, mixed>>|null  $lines  normalised lines (« 1000.00 »)
 */
function makeQuote(Opportunity $opportunity, ?Company $company = null, ?array $lines = null): Quote
{
    $company ??= Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create();

    return app(QuoteWriter::class)->create($opportunity, $company, [
        'contact_person_id' => null,
        'issued_on' => now('Europe/Paris')->toDateString(),
        'valid_until' => now('Europe/Paris')->addDays(30)->toDateString(),
        'notes' => null,
        'lines' => $lines ?? [[
            'training_id' => null, 'label' => 'Formation HACCP', 'description' => null, 'quantity' => '1.00',
            'unit' => 'stagiaire', 'unit_price' => '1000.00', 'discount_percent' => '0.00', 'vat_rate' => '20.00',
        ]],
    ]);
}

/**
 * Gotenberg answers a recognisable PDF (« %PDF-1.7 devis N », N = call).
 */
function fakeQuoteGotenberg(): void
{
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    $calls = 0;
    Http::fake(['gotenberg:3000/*' => function () use (&$calls) {
        $calls++;

        return Http::response("%PDF-1.7 devis {$calls}", 200, ['Content-Type' => 'application/pdf']);
    }]);
}

/**
 * A draft quote ready to send: company « CAP FORMATION » (prefix CF), the
 * entreprise « Le Bistrot » and its interlocutrice Léa Durand (e-mail) as
 * recipient, the opportunity at $stage.
 */
function sendableQuote(string $stage = 'Nouveau', ?string $email = 'lea.durand@example.test'): Quote
{
    $company = Company::query()->where('is_default', true)->first()
        ?? Company::factory()->default()->create(['name' => 'CAP FORMATION', 'quote_prefix' => 'CF']);
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot', 'city' => 'Annecy']);
    $contact = makePerson('Durand', ['first_name' => 'Léa', 'email' => $email]);
    affiliate($contact, $organisation);
    $opportunity = makeOpportunity($organisation, ['stage_id' => pipelineStage($stage)->id]);

    $quote = makeQuote($opportunity, $company);
    $quote->forceFill(['contact_person_id' => $contact->id])->save();

    return $quote->refresh();
}

/**
 * The fields of the « Envoyer le devis » window.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function quoteMessage(array $overrides = []): array
{
    return [
        'to' => 'lea.durand@example.test',
        'cc' => [],
        'subject' => 'Votre devis',
        'body' => "Bonjour,\n\nVoici notre devis.",
        ...$overrides,
    ];
}

/*
|--------------------------------------------------------------------------
| Online signature helpers
|--------------------------------------------------------------------------
*/

/** A drawn signature as the signature page sends it: a PNG data URI (a line on a transparent canvas, or blank). */
function sigDrawing(bool $blank = false, int $width = 600, int $height = 200): string
{
    $image = imagecreatetruecolor($width, $height);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    if (! $blank) {
        imagesetthickness($image, 3);
        imageline($image, 20, 150, 280, 40, imagecolorallocate($image, 20, 30, 60));
        imageline($image, 280, 40, 560, 160, imagecolorallocate($image, 20, 30, 60));
    }
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}
