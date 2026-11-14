<?php

use App\Modules\Core\Companies\Brand;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\CompanyBranding;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

// Container-local private disk (see fakeImportDisk: host-mounted storage is flaky under Colima).
beforeEach(fn () => fakeImportDisk());

function brandPng(int $size = 4): string
{
    $image = imagecreatetruecolor($size, $size);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function brandCompany(string $name = 'CAP FORMATION'): Company
{
    inTenant();
    Company::query()->where('is_default', true)->get()->each(fn (Company $c) => $c->update(['is_default' => false]));

    return Company::factory()->default()->create(['name' => $name]);
}

function brandLogo(Company $company, string $png): void
{
    app(CompanyBranding::class)->storeLogo($company, UploadedFile::fake()->createWithContent('logo.png', $png));
}

it('shares the name of the default company and no logo with a signed-in user', function () {
    brandCompany('CAP FORMATION');
    $this->actingAs(commercial());

    $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('brand.name', 'CAP FORMATION')
        ->where('brand.logoUrl', null));
});

it('falls back to the application name without a default company', function () {
    inTenant();
    Company::query()->get()->each(fn (Company $c) => $c->forceFill(['is_default' => false])->saveQuietly());
    Cache::flush();
    $this->actingAs(commercial());

    $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('brand.name', config('app.name'))
        ->where('brand.logoUrl', null));
});

it('does not reveal the organisme to guests', function () {
    brandCompany('CAP FORMATION');

    $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->where('brand', null));
});

it('serves the logo of the default company with browser cache headers', function () {
    $company = brandCompany();
    $png = brandPng();
    brandLogo($company, $png);
    $this->actingAs(commercial());

    $hash = hash('sha256', $png);
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('brand.logoUrl', '/branding/logo?v='.substr($hash, 0, 16)));

    $response = $this->get('/branding/logo?v='.substr($hash, 0, 16))->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('ETag', '"'.$hash.'"');
    expect($response->getContent())->toBe($png)
        ->and($response->headers->get('Cache-Control'))->toContain('private')->toContain('max-age=3600');

    $this->get('/branding/logo', ['If-None-Match' => '"'.$hash.'"'])->assertStatus(304);
});

it('answers 404 without a logo and sends guests to the login', function () {
    brandCompany();

    $this->get('/branding/logo')->assertRedirect('/login');

    $this->actingAs(commercial());
    $this->get('/branding/logo')->assertNotFound();
});

it('refreshes the cached brand when the company or its logo changes', function () {
    $company = brandCompany('Ancien nom');
    $this->actingAs(admin(twoFactor: true));
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('brand.name', 'Ancien nom'));

    $company->update(['name' => 'Nouveau nom']);
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('brand.name', 'Nouveau nom'));

    $first = brandPng(4);
    brandLogo($company->refresh(), $first);
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('brand.logoUrl', '/branding/logo?v='.substr(hash('sha256', $first), 0, 16)));

    $second = brandPng(6);
    brandLogo($company->refresh(), $second);
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('brand.logoUrl', '/branding/logo?v='.substr(hash('sha256', $second), 0, 16)));

    app(CompanyBranding::class)->deleteLogo($company->refresh());
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('brand.logoUrl', null));
});

it('caches the brand per organisme', function () {
    brandCompany('CAP FORMATION');
    $other = Tenant::factory()->create();
    app(TenantContext::class)->run($other, function (): void {
        Company::factory()->default()->create(['name' => 'Autre organisme']);
    });

    $mine = app(Brand::class)->current();
    $theirs = app(TenantContext::class)->run($other, fn () => app(Brand::class)->current());

    expect($mine['name'])->toBe('CAP FORMATION')
        ->and($theirs['name'])->toBe('Autre organisme')
        ->and(Brand::key(tenant()->id))->toStartWith(tenant()->id.':');
});
