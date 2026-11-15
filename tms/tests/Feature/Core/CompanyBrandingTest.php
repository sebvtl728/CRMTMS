<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\CompanyBranding;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

// Container-local private disk (see fakeImportDisk: host-mounted storage is flaky under Colima).
beforeEach(fn () => fakeImportDisk());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function brandingPayload(Company $company, array $overrides = []): array
{
    return array_merge([
        'name' => $company->name,
        'legal_form' => 'sas',
        'capital_amount' => '10000',
        'capital_variable' => false,
        'siret' => $company->siret,
        'vat_regime' => 'subject',
        'address_country' => 'FR',
        'quote_prefix' => 'CAP',
        'quote_validity_days' => 45,
        'quote_terms' => "Paiement à 30 jours.\nPénalités de retard : 3 fois le taux légal.",
        'quote_footer' => 'CAP FORMATION – SCOP',
    ], $overrides);
}

function imageBytes(string $type): string
{
    $image = imagecreatetruecolor(4, 4);
    ob_start();
    $type === 'png' ? imagepng($image) : imagejpeg($image);

    return (string) ob_get_clean();
}

function logoUpload(string $name, string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

it('gives a new company the default quote settings', function () {
    inTenant();
    $company = Company::factory()->create()->fresh();

    expect($company->quote_prefix)->toBe('DEV')
        ->and($company->quote_validity_days)->toBe(30)
        ->and($company->quote_terms)->toBeNull()
        ->and($company->quote_footer)->toBeNull()
        ->and($company->logo_path)->toBeNull();
});

it('saves the quote settings of a company', function () {
    $this->actingAs(admin(twoFactor: true));
    $company = Company::factory()->default()->create();

    $this->put("/admin/companies/{$company->id}", brandingPayload($company, ['quote_prefix' => ' cap26 ']))
        ->assertSessionDoesntHaveErrors();

    $company->refresh();
    expect($company->quote_prefix)->toBe('CAP26')
        ->and($company->quote_validity_days)->toBe(45)
        ->and($company->quote_terms)->toStartWith('Paiement à 30 jours.')
        ->and($company->quote_footer)->toBe('CAP FORMATION – SCOP');

    $this->get("/admin/companies/{$company->id}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('company.quote_prefix', 'CAP26')
        ->where('company.quote_validity_days', 45)
        ->where('company.logo', null));
});

it('validates the quote settings in french', function (array $input, string $field, string $message) {
    $this->actingAs(admin(twoFactor: true));
    $company = Company::factory()->default()->create();

    $this->put("/admin/companies/{$company->id}", brandingPayload($company, $input))
        ->assertSessionHasErrors([$field => $message]);

    expect($company->fresh()->quote_prefix)->toBe('DEV');
})->with([
    'prefix with a dash' => [['quote_prefix' => 'DEV-1'], 'quote_prefix', 'Le préfixe ne peut contenir que des lettres majuscules et des chiffres (10 caractères au plus).'],
    'prefix too long' => [['quote_prefix' => 'ABCDEFGHIJK'], 'quote_prefix', 'Le préfixe ne peut contenir que des lettres majuscules et des chiffres (10 caractères au plus).'],
    'empty prefix' => [['quote_prefix' => ''], 'quote_prefix', 'Le préfixe de numérotation est obligatoire.'],
    'validity 0' => [['quote_validity_days' => 0], 'quote_validity_days', 'La durée de validité doit être comprise entre 1 et 365 jours.'],
    'validity 366' => [['quote_validity_days' => 366], 'quote_validity_days', 'La durée de validité doit être comprise entre 1 et 365 jours.'],
    'terms too long' => [['quote_terms' => str_repeat('a', 5001)], 'quote_terms', 'Les conditions ne peuvent pas dépasser 5 000 caractères.'],
    'footer too long' => [['quote_footer' => str_repeat('a', 5001)], 'quote_footer', 'Le pied de page ne peut pas dépasser 5 000 caractères.'],
]);

it('keeps one company per quote prefix in the organisme', function () {
    $this->actingAs(admin(twoFactor: true));
    $first = Company::factory()->default()->create();
    $second = Company::factory()->create();

    // Left with the default prefix, a new company takes the first free suffix.
    expect($first->quote_prefix)->toBe('DEV')->and($second->quote_prefix)->toBe('DEV2')
        ->and(Company::factory()->create()->quote_prefix)->toBe('DEV3');

    // A prefix typed in the form must be free (the company's own is fine).
    $this->put("/admin/companies/{$second->id}", brandingPayload($second, ['quote_prefix' => 'dev']))
        ->assertSessionHasErrors(['quote_prefix' => 'Ce préfixe est déjà celui d\'une autre société : choisissez-en un autre.']);
    $this->put("/admin/companies/{$first->id}", brandingPayload($first, ['quote_prefix' => 'DEV']))->assertSessionDoesntHaveErrors();

    // The database refuses it too; a deleted company frees its prefix.
    Company::factory()->create(['quote_prefix' => 'ABC']);
    expect(fn () => Company::factory()->create(['quote_prefix' => 'ABC']))->toThrow(UniqueConstraintViolationException::class);
    $second->delete();
    $this->put("/admin/companies/{$first->id}", brandingPayload($first, ['quote_prefix' => 'DEV2']))->assertSessionDoesntHaveErrors();

    // Another organisme may use the same prefix.
    $other = app(TenantContext::class)->run(Tenant::factory()->create(), fn () => Company::factory()->create(['quote_prefix' => 'DEV2']));
    expect($other->quote_prefix)->toBe('DEV2');
});

it('prefills the new company form with the first free quote prefix', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->get('/admin/companies/create')->assertInertia(fn (Assert $page) => $page->where('nextPrefix', 'DEV'));

    Company::factory()->default()->create();
    Company::factory()->create();
    $this->get('/admin/companies/create')->assertInertia(fn (Assert $page) => $page->where('nextPrefix', 'DEV3'));

    // The edit form shows the company's own prefix.
    $company = Company::factory()->create(['quote_prefix' => 'CAP']);
    $this->get("/admin/companies/{$company->id}/edit")->assertInertia(fn (Assert $page) => $page->where('company.quote_prefix', 'CAP'));
});

it('stores the logo under the tenant directory and serves it as a data uri', function () {
    $tenant = tenant();
    $this->actingAs(admin(twoFactor: true));
    $company = Company::factory()->default()->create();
    $png = imageBytes('png');

    $this->post("/admin/companies/{$company->id}/logo", ['logo' => logoUpload('logo.png', $png)])
        ->assertSessionDoesntHaveErrors()->assertRedirect();

    $company->refresh();
    expect($company->logo_path)->toBe("tenants/{$tenant->id}/companies/{$company->id}/logo.png");
    Storage::disk('local')->assertExists($company->logo_path);
    expect(app(CompanyBranding::class)->logoDataUri($company))->toBe('data:image/png;base64,'.base64_encode($png));

    // The change is journaled, never the file content.
    $audit = AuditLog::query()->where('auditable_id', $company->id)->where('event', 'company.logo_changed')->sole();
    expect($audit->old_values)->toBe(['logo' => null])
        ->and($audit->new_values)->toBe(['logo' => 'logo.png', 'size' => strlen($png), 'sha256' => hash('sha256', $png)])
        ->and(json_encode($audit->getAttributes()))->not->toContain(base64_encode($png))
        ->and(AuditLog::query()->where('auditable_id', $company->id)->where('event', 'updated')->exists())->toBeFalse();

    $this->get("/admin/companies/{$company->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('company.logo', 'data:image/png;base64,'.base64_encode($png)));
});

it('replaces the previous logo and deletes it on request', function () {
    tenant();
    $this->actingAs(admin(twoFactor: true));
    $company = Company::factory()->default()->create();

    $this->post("/admin/companies/{$company->id}/logo", ['logo' => logoUpload('logo.png', imageBytes('png'))])->assertSessionDoesntHaveErrors();
    $first = $company->fresh()->logo_path;

    $jpeg = imageBytes('jpeg');
    $this->post("/admin/companies/{$company->id}/logo", ['logo' => logoUpload('logo.jpg', $jpeg)])->assertSessionDoesntHaveErrors();
    $company->refresh();

    expect($company->logo_path)->toEndWith("/companies/{$company->id}/logo.jpg")
        ->and(app(CompanyBranding::class)->logoDataUri($company))->toBe('data:image/jpeg;base64,'.base64_encode($jpeg));
    Storage::disk('local')->assertMissing($first);

    // A replacement under the same name is journaled too.
    $this->post("/admin/companies/{$company->id}/logo", ['logo' => logoUpload('logo.jpeg', $jpeg)])->assertSessionDoesntHaveErrors();
    expect(AuditLog::query()->where('auditable_id', $company->id)->where('event', 'company.logo_changed')->count())->toBe(3);

    $this->delete("/admin/companies/{$company->id}/logo")->assertRedirect();
    expect(AuditLog::query()->where('auditable_id', $company->id)->where('event', 'company.logo_deleted')->sole()->old_values)->toBe(['logo' => 'logo.jpg']);
    Storage::disk('local')->assertMissing((string) $company->logo_path);
    expect($company->fresh()->logo_path)->toBeNull()
        ->and(app(CompanyBranding::class)->logoDataUri($company->fresh()))->toBeNull();
});

it('refuses svg, renamed or oversized logos', function (string $name, Closure $content, string $message) {
    $this->actingAs(admin(twoFactor: true));
    $company = Company::factory()->default()->create();

    $this->post("/admin/companies/{$company->id}/logo", ['logo' => logoUpload($name, $content())])
        ->assertSessionHasErrors(['logo' => $message]);

    expect($company->fresh()->logo_path)->toBeNull();
    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'svg' => ['logo.svg', fn () => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'Le logo doit être une image PNG ou JPEG.'],
    'svg renamed png' => ['logo.png', fn () => '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'Le logo doit être une image PNG ou JPEG.'],
    'php renamed png' => ['logo.png', fn () => '<?php echo system($_GET["c"]);', 'Le logo doit être une image PNG ou JPEG.'],
    'png renamed jpg' => ['logo.jpg', fn () => imageBytes('png'), 'Le logo doit être une image PNG ou JPEG.'],
    'gif' => ['logo.gif', fn () => "GIF89a\x01\x00\x01\x00\x00\x00\x00;", 'Le logo doit être une image PNG ou JPEG.'],
    'over 1 Mo' => ['logo.png', fn () => imageBytes('png').str_repeat("\0", 1024 * 1024), 'Le logo ne doit pas dépasser 1 Mo.'],
]);

it('keeps the logo actions to companies.manage and to the organisme', function () {
    tenant();
    $company = Company::factory()->default()->create();

    $this->actingAs(assistant())->post("/admin/companies/{$company->id}/logo", ['logo' => logoUpload('logo.png', imageBytes('png'))])->assertForbidden();
    $this->actingAs(assistant())->delete("/admin/companies/{$company->id}/logo")->assertForbidden();

    $other = Tenant::factory()->create();
    $foreign = app(TenantContext::class)->run($other, fn () => Company::factory()->default()->create());
    $this->actingAs(admin(twoFactor: true))->post("/admin/companies/{$foreign->id}/logo", ['logo' => logoUpload('logo.png', imageBytes('png'))])->assertNotFound();
    $this->delete("/admin/companies/{$foreign->id}/logo")->assertNotFound();
});

it('ignores a stored path outside the company directory', function () {
    tenant();
    $company = Company::factory()->create();
    Storage::disk('local')->put('tenants/elsewhere/secret.png', imageBytes('png'));
    $company->forceFill(['logo_path' => 'tenants/elsewhere/secret.png'])->save();

    expect(app(CompanyBranding::class)->logoDataUri($company))->toBeNull();
});

it('keeps the previous logo intact when saving the replacement fails', function () {
    inTenant();
    $company = Company::factory()->create();
    $first = imageBytes('png');
    app(CompanyBranding::class)->storeLogo($company, logoUpload('logo.png', $first));
    $path = $company->refresh()->logo_path;

    Company::saving(function (): void {
        throw new RuntimeException('database down');
    });
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($image);
    $second = (string) ob_get_clean();
    expect(fn () => app(CompanyBranding::class)->storeLogo($company, logoUpload('logo.png', $second)))->toThrow(RuntimeException::class);

    $disk = Storage::disk('local');
    expect($disk->get($path))->toBe($first)
        ->and($disk->files(dirname($path)))->toBe([$path]);
});
