<?php

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Qualite\Qualiopi\QualityProof;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

const QUALITY_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

beforeEach(fn () => fakeImportDisk());

it('shows an indicator: its official text, its state and its proofs', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/qualite/qualiopi/8/proofs', ['kind' => 'link', 'title' => 'Procédure de positionnement', 'url' => 'https://intranet.example.test/positionnement']);

    $this->get('/qualite/qualiopi/8')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('qualite/qualiopi/show')
        ->where('indicator.number', 8)
        ->where('indicator.title', 'Positionnement à l’entrée')
        ->where('indicator.status', 'todo')
        ->has('proofs', 1)
        ->where('proofs.0.title', 'Procédure de positionnement')
        ->where('proofs.0.url', 'https://intranet.example.test/positionnement')
        ->has('users'));
    $this->get('/qualite/qualiopi/34')->assertNotFound();
});

it('adds a file, a link or a note as proof, and refuses a disguised file or a dangerous link', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->post('/qualite/qualiopi/12/proofs', ['kind' => 'file', 'title' => 'Procédure VSS', 'file' => UploadedFile::fake()->createWithContent('procedure.pdf', QUALITY_PDF)])
        ->assertRedirect()->assertSessionHas('status', 'Preuve ajoutée.');
    $proof = QualityProof::query()->sole();
    expect($proof->path)->toStartWith(TenantStorage::path('quality').'/')
        ->and(Storage::disk(TenantStorage::DISK)->exists($proof->path))->toBeTrue()
        ->and($proof->original_name)->toBe('procedure.pdf');
    $this->get("/qualite/qualiopi/proofs/{$proof->id}")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $this->post('/qualite/qualiopi/12/proofs', ['kind' => 'file', 'title' => 'Piège', 'file' => UploadedFile::fake()->createWithContent('piege.pdf', 'MZ'.str_repeat("\0", 40))])
        ->assertSessionHasErrors(['file' => 'Formats acceptés : PDF, images (JPG, PNG), Word, Excel, PowerPoint, LibreOffice.']);
    $this->post('/qualite/qualiopi/12/proofs', ['kind' => 'link', 'title' => 'Lien', 'url' => 'javascript:alert(1)'])
        ->assertSessionHasErrors(['url' => 'Indiquez une adresse web qui commence par http:// ou https://.']);
    $this->post('/qualite/qualiopi/12/proofs', ['kind' => 'note', 'title' => 'Référente VSS', 'note' => 'Mme Martin, désignée en CA du 3 octobre.'])->assertSessionHasNoErrors();
    $this->post('/qualite/qualiopi/12/proofs', ['kind' => 'link', 'title' => ''])->assertSessionHasErrors('title');

    expect(QualityProof::query()->count())->toBe(2);
});

it('deletes a proof and its file', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/qualite/qualiopi/12/proofs', ['kind' => 'file', 'title' => 'Procédure', 'file' => UploadedFile::fake()->createWithContent('p.pdf', QUALITY_PDF)]);
    $proof = QualityProof::query()->sole();

    $this->delete("/qualite/qualiopi/proofs/{$proof->id}")->assertRedirect()->assertSessionHas('status', 'Preuve retirée.');
    expect(QualityProof::query()->count())->toBe(0)->and(Storage::disk(TenantStorage::DISK)->exists($proof->path))->toBeFalse();
});

it('lets a reader download the proofs but not change them, and isolates the organismes', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/qualite/qualiopi/12/proofs', ['kind' => 'file', 'title' => 'Procédure', 'file' => UploadedFile::fake()->createWithContent('p.pdf', QUALITY_PDF)]);
    $proof = QualityProof::query()->sole();

    $reader = userWithPermissions(['qualite.view']);
    $this->actingAs($reader)->get("/qualite/qualiopi/proofs/{$proof->id}")->assertOk();
    $this->actingAs($reader)->post('/qualite/qualiopi/12/proofs', ['kind' => 'note', 'title' => 'x', 'note' => 'y'])->assertForbidden();
    $this->actingAs($reader)->delete("/qualite/qualiopi/proofs/{$proof->id}")->assertForbidden();

    $other = Tenant::factory()->create();
    app(TenantContext::class)->run($other, fn () => expect(QualityProof::query()->find($proof->id))->toBeNull());
});
