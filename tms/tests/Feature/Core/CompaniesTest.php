<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\LegalForm;
use App\Modules\Core\Companies\VatRegime;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function companyPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'CAP FORMATION',
        'legal_form' => 'scop',
        'scop_underlying_form' => 'sarl',
        'capital_amount' => null,
        'capital_variable' => true,
        'siret' => '73282932000074',
        'rcs_city' => 'Lyon',
        'vat_number' => 'FR12732829320',
        'vat_regime' => 'subject',
        'nda_number' => '84691234569',
        'address_line1' => '1 rue de la Paix',
        'address_postal_code' => '69001',
        'address_city' => 'Lyon',
        'address_country' => 'FR',
        'email' => 'contact@cap.test',
        'phone' => '04 72 00 00 00',
    ], $overrides);
}

it('rejects an invalid siret', function () {
    $this->actingAs(admin(twoFactor: true))
        ->post('/admin/companies', companyPayload(['siret' => '12345678901234']))
        ->assertSessionHasErrors('siret');

    expect(Company::count())->toBe(0);
});

it('requires a siret when saving through the form', function () {
    $this->actingAs(admin(twoFactor: true))
        ->post('/admin/companies', companyPayload(['siret' => '']))
        ->assertSessionHasErrors('siret');
});

it('requires the underlying form for a SCOP or SCIC', function () {
    $this->actingAs(admin(twoFactor: true))
        ->post('/admin/companies', companyPayload(['scop_underlying_form' => null]))
        ->assertSessionHasErrors('scop_underlying_form');

    $this->post('/admin/companies', companyPayload(['legal_form' => 'scic', 'scop_underlying_form' => 'sarl']))
        ->assertSessionDoesntHaveErrors();

    $this->post('/admin/companies', companyPayload(['scop_underlying_form' => 'eurl']))
        ->assertSessionHasErrors('scop_underlying_form');
});

it('requires a positive fixed capital for capital-based forms', function () {
    $this->actingAs(admin(twoFactor: true));
    $sas = ['legal_form' => 'sas', 'scop_underlying_form' => null, 'capital_variable' => false];

    $this->post('/admin/companies', companyPayload($sas + ['capital_amount' => null]))->assertSessionHasErrors('capital_amount');
    $this->post('/admin/companies', companyPayload($sas + ['capital_amount' => '0']))->assertSessionHasErrors('capital_amount');
    $this->post('/admin/companies', companyPayload($sas + ['capital_amount' => '-5']))->assertSessionHasErrors('capital_amount');
    expect(Company::count())->toBe(0);

    $this->post('/admin/companies', companyPayload($sas + ['capital_amount' => '10000']))->assertSessionDoesntHaveErrors();
});

it('keeps capital optional for variable capital and non capital forms', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->post('/admin/companies', companyPayload(['capital_variable' => true, 'capital_amount' => null]))->assertSessionDoesntHaveErrors();
    $this->post('/admin/companies', companyPayload(['legal_form' => 'association', 'scop_underlying_form' => null, 'capital_variable' => false, 'capital_amount' => null]))->assertSessionDoesntHaveErrors();
    expect(Company::count())->toBe(2);
});

it('drops the underlying form for other legal forms', function () {
    $this->actingAs(admin(twoFactor: true))
        ->post('/admin/companies', companyPayload(['legal_form' => 'sas', 'scop_underlying_form' => 'sarl', 'capital_variable' => false, 'capital_amount' => '10000']))
        ->assertSessionDoesntHaveErrors();

    expect(Company::sole()->scop_underlying_form)->toBeNull();
});

it('creates a company, normalises identifiers and journals it', function () {
    $this->actingAs(admin(twoFactor: true))
        ->post('/admin/companies', companyPayload(['siret' => '732 829 320 00074', 'vat_number' => 'fr 12 732829320']))
        ->assertRedirect(route('admin.companies.index'));

    $company = Company::sole();
    expect($company->siret)->toBe('73282932000074')
        ->and($company->vat_number)->toBe('FR12732829320')
        ->and($company->legal_form)->toBe(LegalForm::Scop)
        ->and($company->vat_regime)->toBe(VatRegime::Subject)
        ->and($company->is_default)->toBeTrue() // the first one is the default
        ->and(AuditLog::where('event', 'created')->where('auditable_id', $company->id)->exists())->toBeTrue();
});

it('keeps a single default company per tenant', function () {
    $this->actingAs(admin(twoFactor: true));

    $first = Company::factory()->default()->create();
    $second = Company::factory()->create();

    $this->put("/admin/companies/{$second->id}", companyPayload(['is_default' => true]))->assertSessionDoesntHaveErrors();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and(Company::where('is_default', true)->count())->toBe(1);
});

it('unsets the previous default atomically when a new default is created', function () {
    inTenant();
    $first = Company::factory()->default()->create();
    $second = Company::factory()->default()->create();

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue();
});

it('enforces one default per tenant in the database', function () {
    $tenant = inTenant();
    Company::factory()->default()->create();

    expect(fn () => DB::table('companies')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'X', 'legal_form' => 'autre',
        'vat_regime' => 'subject', 'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('scopes defaults per tenant', function () {
    $other = Tenant::factory()->create();
    app(TenantContext::class)->run($other, fn () => Company::factory()->default()->create());

    inTenant();
    Company::factory()->default()->create();

    expect(Company::count())->toBe(1)
        ->and(app(TenantContext::class)->run($other, fn () => Company::count()))->toBe(1);
});

it('does not let the default flag be removed without choosing another', function () {
    $this->actingAs(admin(twoFactor: true));
    $company = Company::factory()->default()->create();

    $this->put("/admin/companies/{$company->id}", companyPayload(['is_default' => false]))->assertSessionDoesntHaveErrors();

    expect($company->fresh()->is_default)->toBeTrue();
});

it('lists companies and renders the edit page', function () {
    $this->actingAs(admin(twoFactor: true));
    $company = Company::factory()->default()->create(['name' => 'ACME']);

    $this->get('/admin/companies')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/companies/index')
        ->has('companies', 1)
        ->where('companies.0.name', 'ACME')
        ->where('companies.0.is_default', true));

    $this->get("/admin/companies/{$company->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/companies/edit')
        ->where('company.id', $company->id)
        ->has('legalForms', 12)
        ->has('mentions'));

    $this->get('/admin/companies/create')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/companies/edit')->where('company', null));
});

it('previews the legal mentions of unsaved input', function () {
    $this->actingAs(admin(twoFactor: true))
        ->postJson('/admin/companies/preview', companyPayload())
        ->assertOk()
        ->assertJson(['mentions' => "CAP FORMATION – SCOP SARL à capital variable – RCS Lyon 732\u{00A0}829\u{00A0}320 – SIRET 732\u{00A0}829\u{00A0}320\u{00A0}00074"]);

    expect(Company::count())->toBe(0);
});

it('is reserved to companies.manage', function () {
    inTenant();
    $company = Company::factory()->create();

    $this->actingAs(assistant())->get('/admin/companies')->assertForbidden();
    $this->actingAs(assistant())->post('/admin/companies', companyPayload())->assertForbidden();
    $this->actingAs(assistant())->put("/admin/companies/{$company->id}", companyPayload())->assertForbidden();
    $this->actingAs(assistant())->postJson('/admin/companies/preview', companyPayload())->assertForbidden();
});

it('cannot reach a company of another tenant', function () {
    $other = Tenant::factory()->create();
    $foreign = app(TenantContext::class)->run($other, fn () => Company::factory()->default()->create());

    $this->actingAs(admin(twoFactor: true))->get("/admin/companies/{$foreign->id}/edit")->assertNotFound();
});

it('creates exactly one default company when installing', function () {
    Tenant::query()->delete();
    app(TenantContext::class)->clear();

    $this->artisan('tms:install', ['--tenant-name' => 'CAP FORMATION', '--admin-email' => 'a@cap.test', '--admin-name' => 'Admin'])
        ->assertSuccessful();

    $tenant = Tenant::sole();
    $companies = app(TenantContext::class)->run($tenant, fn () => Company::all());
    expect($companies)->toHaveCount(1)
        ->and($companies[0]->name)->toBe('CAP FORMATION')
        ->and($companies[0]->legal_form)->toBe(LegalForm::Autre)
        ->and($companies[0]->is_default)->toBeTrue()
        ->and($companies[0]->vat_regime)->toBe(VatRegime::Subject)
        ->and($companies[0]->siret)->toBeNull();
});

it('creates a default company with tms:tenant:create, exempt on demand', function () {
    $this->artisan('tms:tenant:create', ['name' => 'Alpha', '--slug' => 'alpha'])->assertSuccessful();
    $this->artisan('tms:tenant:create', ['name' => 'Beta', '--slug' => 'beta', '--vat-exempt' => true])->assertSuccessful();

    foreach (['alpha' => VatRegime::Subject, 'beta' => VatRegime::Exempt] as $slug => $regime) {
        $companies = app(TenantContext::class)->run(Tenant::where('slug', $slug)->sole(), fn () => Company::all());
        expect($companies)->toHaveCount(1)
            ->and($companies[0]->is_default)->toBeTrue()
            ->and($companies[0]->vat_regime)->toBe($regime);
    }
});

it('rolls the default company back with a failed install', function () {
    Tenant::query()->delete();
    app(TenantContext::class)->clear();

    $this->artisan('tms:install', ['--tenant-name' => 'CAP FORMATION', '--admin-email' => 'a@cap.test', '--admin-name' => 'Admin', '--domain' => 'https://bad'])
        ->assertFailed();

    expect(DB::table('companies')->count())->toBe(0);
});

it('stores the bank details of a company: IBAN checked by its key, BIC by its format', function () {
    $this->actingAs(admin(twoFactor: true))
        ->post('/admin/companies', companyPayload(['bank_name' => 'Crédit Coopératif', 'iban' => 'fr76 3000 6000 0112 3456 7890 189', 'bic' => 'bnpafrppxxx']))
        ->assertSessionHasNoErrors();

    $company = Company::query()->sole();
    expect($company->iban)->toBe('FR7630006000011234567890189')
        ->and($company->bic)->toBe('BNPAFRPPXXX')
        ->and($company->bank_name)->toBe('Crédit Coopératif');
});

it('refuses an IBAN with a wrong key, a BIC of the wrong shape, and a BIC without IBAN', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->post('/admin/companies', companyPayload(['iban' => 'FR7630006000011234567890188']))->assertSessionHasErrors(['iban' => 'L\'IBAN est invalide : vérifiez sa clé et sa longueur.']);
    $this->post('/admin/companies', companyPayload(['iban' => 'FR7630006000011234567890189', 'bic' => 'BNP']))->assertSessionHasErrors(['bic' => 'Le BIC doit compter 8 ou 11 caractères (ex. BNPAFRPP).']);
    $this->post('/admin/companies', companyPayload(['bic' => 'BNPAFRPP']))->assertSessionHasErrors(['iban' => 'Indiquez l\'IBAN avec le BIC.']);
    expect(Company::count())->toBe(0);
});
