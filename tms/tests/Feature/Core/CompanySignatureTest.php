<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Companies\CompanyBranding;
use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => fakeImportDisk());

function signaturePng(): string
{
    $image = imagecreatetruecolor(6, 3);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('stores the signature and stamp of a company and prints it as a data URI', function () {
    $this->actingAs(admin(twoFactor: true));
    $company = Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create();

    $this->post("/admin/companies/{$company->id}/signature", ['signature' => UploadedFile::fake()->createWithContent('cachet.png', signaturePng())])
        ->assertSessionHasNoErrors();

    $company->refresh();
    expect($company->signature_path)->toStartWith(TenantStorage::path('companies', $company->id).'/signature.')
        ->and(Storage::disk(TenantStorage::DISK)->exists($company->signature_path))->toBeTrue()
        ->and(app(CompanyBranding::class)->signatureDataUri($company))->toStartWith('data:image/png;base64,')
        ->and(app(CompanyBranding::class)->logoDataUri($company))->toBeNull()
        ->and(AuditLog::query()->where('event', 'company.signature_changed')->exists())->toBeTrue();

    $path = $company->signature_path;
    $this->delete("/admin/companies/{$company->id}/signature")->assertSessionHasNoErrors();
    expect($company->fresh()->signature_path)->toBeNull()
        ->and(Storage::disk(TenantStorage::DISK)->exists($path))->toBeFalse()
        ->and(AuditLog::query()->where('event', 'company.signature_deleted')->exists())->toBeTrue();
});

it('refuses a disguised file or an SVG as a signature', function () {
    $this->actingAs(admin(twoFactor: true));
    $company = Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create();

    $this->post("/admin/companies/{$company->id}/signature", ['signature' => UploadedFile::fake()->createWithContent('cachet.png', 'MZ'.str_repeat("\0", 40))])
        ->assertSessionHasErrors(['signature' => 'La signature doit être une image PNG ou JPEG.']);
    $this->post("/admin/companies/{$company->id}/signature", ['signature' => UploadedFile::fake()->createWithContent('cachet.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')])
        ->assertSessionHasErrors('signature');
    expect($company->fresh()->signature_path)->toBeNull();
});

it('shows the signature on the company form', function () {
    $this->actingAs(admin(twoFactor: true));
    $company = Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create();
    app(CompanyBranding::class)->storeSignature($company, UploadedFile::fake()->createWithContent('cachet.png', signaturePng()));

    $this->get("/admin/companies/{$company->id}/edit")->assertInertia(fn ($p) => $p->where('company.signature', fn ($v) => str_starts_with((string) $v, 'data:image/png;base64,')));
});
