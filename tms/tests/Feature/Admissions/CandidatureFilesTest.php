<?php

use App\Modules\Admissions\Candidatures\CandidatureFile;
use App\Modules\Admissions\Candidatures\CandidatureFiles;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\CustomFields\CustomFieldDefinitions;
use App\Modules\Core\Tenancy\TenantStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/Support/admissions.php';

beforeEach(function () {
    adSetUp();
    fakeImportDisk();
});

function adPdf(string $name = 'test.pdf', string $extra = ''): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n{$extra}");
}

it('stores a positioning test under the admissions zone of the tenant', function () {
    $c = adCandidature();
    $file = app(CandidatureFiles::class)->store($c, 'positioning', adPdf('positionnement.pdf'), admin());

    expect($file->path)->toStartWith(TenantStorage::path('admissions', $c->id).'/')
        ->and($file->name)->toBe('positionnement.pdf')
        ->and(Storage::disk(TenantStorage::DISK)->exists($file->path))->toBeTrue();

    // Several positioning files are kept.
    app(CandidatureFiles::class)->store($c, 'positioning', adPdf('second.pdf'), admin());
    expect(CandidatureFile::query()->where('candidature_id', $c->id)->count())->toBe(2);
});

it('refuses a file too big, of another type or of an unknown kind', function () {
    $c = adCandidature();
    $errors = fn (Closure $fn) => (function () use ($fn) {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors()['file'][0] ?? array_values($e->errors())[0][0];
        }

        return null;
    })();

    expect($errors(fn () => app(CandidatureFiles::class)->store($c, 'positioning', UploadedFile::fake()->create('gros.pdf', 21 * 1024, 'application/pdf'), admin())))
        ->toBe('Le fichier dépasse 20 Mo.')
        ->and($errors(fn () => app(CandidatureFiles::class)->store($c, 'positioning', UploadedFile::fake()->createWithContent('cv.docx', 'PK...'), admin())))
        ->toBe('Formats acceptés : PDF, JPG, PNG.')
        ->and($errors(fn () => app(CandidatureFiles::class)->store($c, 'photo', adPdf(), admin())))
        ->toBe('Type de pièce inconnu.');
});

it('keeps one signed contract and one file per custom field, the previous one leaving the disk', function () {
    $c = adCandidature();
    $first = app(CandidatureFiles::class)->store($c, 'contract', adPdf('v1.pdf'), admin());
    $second = app(CandidatureFiles::class)->store($c, 'contract', adPdf('v2.pdf', 'v2'), admin());

    expect(CandidatureFile::query()->where('kind', 'contract')->pluck('id')->all())->toBe([$second->id])
        ->and(Storage::disk(TenantStorage::DISK)->exists($first->path))->toBeFalse();

    $cv = app(CustomFieldDefinitions::class)->create('candidature', ['label' => 'CV', 'type' => 'file']);
    app(CandidatureFiles::class)->store($c, "custom:{$cv->id}", adPdf('cv.pdf'), admin());
    expect(fn () => app(CandidatureFiles::class)->store($c, 'custom:0190a8c4-0000-7000-8000-000000000000', adPdf(), admin()))
        ->toThrow(ValidationException::class);
});

it('uploads, serves and deletes through the routes, within the rights', function () {
    $c = adCandidature();
    $this->actingAs(commercial())->post("/admissions/candidatures/{$c->id}/files", ['kind' => 'positioning', 'file' => adPdf('pos.pdf')])->assertRedirect();
    $file = CandidatureFile::query()->sole();
    $this->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (AssertableInertia $p) => $p
        ->where('candidature.files.0.name', 'pos.pdf')->where('candidature.files.0.kind', 'positioning')
        ->where('candidature.files.0.href', "/admissions/candidatures/{$c->id}/files/{$file->id}"));

    $this->get("/admissions/candidatures/{$c->id}/files/{$file->id}")->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'no-store, private');

    $outsider = adUser([Permissions::CANDIDATURES_VIEW => AccessScope::Own]);
    $this->actingAs($outsider)->get("/admissions/candidatures/{$c->id}/files/{$file->id}")->assertNotFound();

    $other = adCandidature();
    $this->actingAs(commercial())->get("/admissions/candidatures/{$other->id}/files/{$file->id}")->assertNotFound();

    $this->delete("/admissions/candidatures/{$c->id}/files/{$file->id}")->assertRedirect();
    expect(CandidatureFile::query()->count())->toBe(0)
        ->and(Storage::disk(TenantStorage::DISK)->exists($file->path))->toBeFalse();
});

it('touches the candidature when a file comes or goes', function () {
    $c = adCandidature();
    $this->travel(1)->days();
    app(CandidatureFiles::class)->store($c, 'positioning', adPdf(), admin());

    expect($c->fresh()->updated_at->isToday())->toBeTrue();
});
