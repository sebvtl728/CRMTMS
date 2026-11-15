<?php

use App\Modules\Catalogue\Brochures;
use App\Modules\Catalogue\Brochures\Brochure;
use App\Modules\Catalogue\Brochures\BrochureVersion;
use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Training;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Audit\AuditRecorder;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

const BROCHURE_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

beforeEach(function () {
    tenant();
    fakeImportDisk(); // container-local disk: the host-mounted fake is flaky under Colima
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function brochureFile(string $name = 'Plaquette été.pdf', string $content = BROCHURE_PDF): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

function brochureDas(string $label = 'Hôtellerie-restauration'): string
{
    return crmItem(ReferenceList::Das, $label)->id;
}

function brochureTraining(string $title, ?string $dasLabel = null): Training
{
    return Training::query()->create(['title' => $title, 'das_id' => brochureDas($dasLabel ?? 'Hôtellerie-restauration')]);
}

/**
 * Deposits a brochure through the screen (as an administrator unless a user
 * is already acting) and returns it.
 *
 * @param  array<string, mixed>  $payload
 */
function depositBrochure(string $title = 'HACCP', array $payload = []): Brochure
{
    test()->post('/catalogue/brochures', ['title' => $title, 'file' => brochureFile(), ...$payload])->assertRedirect('/catalogue/brochures');

    return Brochure::query()->where('title', $title)->latest('id')->firstOrFail();
}

/**
 * @return list<string> every file on the private disk
 */
function brochureDiskFiles(): array
{
    return Storage::disk('local')->allFiles();
}

it('deposits a brochure: title, private pdf named by uuid, first version, journal', function () {
    $admin = admin(twoFactor: true);
    $this->actingAs($admin)->get('/catalogue/brochures/create')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('catalogue/brochures/form')->where('brochure', null)->has('options.das', 4));

    $brochure = depositBrochure('HACCP');
    $version = $brochure->currentVersion;

    expect($brochure->created_by)->toBe($admin->id)
        ->and($brochure->versions()->count())->toBe(1)
        ->and($version)->toBeInstanceOf(BrochureVersion::class)
        ->and($version->original_name)->toBe('Plaquette été.pdf')
        ->and($version->size)->toBe(strlen(BROCHURE_PDF))
        ->and($version->sha256)->toBe(hash('sha256', BROCHURE_PDF))
        ->and($version->uploaded_by)->toBe($admin->id)
        ->and($version->path)->toBe('tenants/'.tenant()->id.'/brochures/'.$brochure->id.'/'.$version->id.'.pdf')
        ->and(brochureDiskFiles())->toBe([$version->path])
        ->and(Storage::disk('local')->get($version->path))->toBe(BROCHURE_PDF)
        ->and(AuditLog::query()->where('event', 'brochure.version_added')->count())->toBe(1);

    // The journal never holds the original name nor the content.
    expect(json_encode(AuditLog::query()->get()->map->only(['old_values', 'new_values'])))->not->toContain('été')->not->toContain($version->path);
});

it('replaces the file: a new version, the previous one kept on disk and downloadable', function () {
    $this->actingAs(admin(twoFactor: true));
    $brochure = depositBrochure('HACCP');
    $first = $brochure->currentVersion;

    $this->put("/catalogue/brochures/{$brochure->id}", [
        'title' => 'HACCP 2027',
        'file' => brochureFile('Nouvelle.pdf', BROCHURE_PDF."% v2\n"),
    ])->assertRedirect('/catalogue/brochures');

    $brochure->refresh();
    $second = $brochure->currentVersion;
    expect($brochure->title)->toBe('HACCP 2027')
        ->and($second->id)->not->toBe($first->id)
        ->and($second->original_name)->toBe('Nouvelle.pdf')
        ->and($brochure->versions()->count())->toBe(2)
        ->and(brochureDiskFiles())->toHaveCount(2)
        ->and(Storage::disk('local')->exists($first->path))->toBeTrue()
        ->and(Storage::disk('local')->get($first->path))->toBe(BROCHURE_PDF);

    $old = $this->get("/catalogue/brochures/{$brochure->id}/versions/{$first->id}")->assertOk();
    expect($old->streamedContent())->toBe(BROCHURE_PDF);
    expect($this->get("/catalogue/brochures/{$brochure->id}/download")->streamedContent())->toBe(BROCHURE_PDF."% v2\n");

    // The edit screen lists the versions, the current one first.
    $this->get("/catalogue/brochures/{$brochure->id}/edit")->assertInertia(fn (Assert $page) => $page->component('catalogue/brochures/form')
        ->where('brochure.title', 'HACCP 2027')
        ->has('brochure.versions', 2)
        ->where('brochure.versions.0.id', $second->id)
        ->where('brochure.versions.0.current', true)
        ->where('brochure.versions.1.id', $first->id));
});

it('keeps the current version when an update sends no file', function () {
    $this->actingAs(admin(twoFactor: true));
    $brochure = depositBrochure('HACCP');
    $version = $brochure->currentVersion;

    $this->put("/catalogue/brochures/{$brochure->id}", ['title' => 'Renommée'])->assertRedirect('/catalogue/brochures');

    expect($brochure->refresh()->title)->toBe('Renommée')
        ->and($brochure->current_version_id)->toBe($version->id)
        ->and($brochure->versions()->count())->toBe(1)
        ->and(brochureDiskFiles())->toHaveCount(1);
});

it('refuses a booby-trapped, renamed, empty or oversized file with a french message and writes nothing', function (string $name, string $content, string $message) {
    $this->actingAs(admin(twoFactor: true));

    $this->from('/catalogue/brochures/create')->post('/catalogue/brochures', ['title' => 'Piège', 'file' => brochureFile($name, $content)])
        ->assertRedirect('/catalogue/brochures/create')
        ->assertSessionHasErrors(['file' => $message]);

    expect(Brochure::withTrashed()->count())->toBe(0)
        ->and(BrochureVersion::query()->count())->toBe(0)
        ->and(brochureDiskFiles())->toBe([]);
})->with([
    'executable renamed .pdf' => ['plaquette.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00PE", 'La plaquette doit être un fichier PDF.'],
    'text renamed .pdf' => ['plaquette.pdf', 'Bonjour, ceci n\'est pas un PDF.', 'La plaquette doit être un fichier PDF.'],
    'pdf content, wrong extension' => ['plaquette.exe', BROCHURE_PDF, 'La plaquette doit être un fichier PDF.'],
    'html renamed .pdf' => ['plaquette.pdf', '<script>alert(1)</script>', 'La plaquette doit être un fichier PDF.'],
    'empty file' => ['plaquette.pdf', '', 'Le fichier est vide.'],
    'more than 10 Mo' => ['plaquette.pdf', "%PDF-1.4\n".str_repeat('0', 10 * 1024 * 1024 + 10), 'La plaquette ne doit pas dépasser 10 Mo.'],
]);

it('refuses the same files when replacing, keeping the previous version', function () {
    $this->actingAs(admin(twoFactor: true));
    $brochure = depositBrochure('HACCP');

    $this->put("/catalogue/brochures/{$brochure->id}", ['title' => 'HACCP', 'file' => brochureFile('x.pdf', 'MZ not a pdf')])
        ->assertSessionHasErrors(['file' => 'La plaquette doit être un fichier PDF.']);

    expect($brochure->versions()->count())->toBe(1)
        ->and(brochureDiskFiles())->toHaveCount(1);
});

it('requires a title and a file to deposit', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->post('/catalogue/brochures', [])->assertSessionHasErrors(['title' => 'Ce champ est obligatoire.', 'file' => 'Choisissez un fichier PDF.']);
});

it('leaves nothing on the disk when the save fails', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->mock(AuditRecorder::class, fn ($mock) => $mock->shouldReceive('record')->andThrow(new RuntimeException('boom')));
    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/catalogue/brochures', ['title' => 'HACCP', 'file' => brochureFile()]))->toThrow(RuntimeException::class);

    expect(Brochure::withTrashed()->count())->toBe(0)
        ->and(brochureDiskFiles())->toBe([]);
});

it('attaches trainings and das, replaces them on update and refuses foreign or unknown ones', function () {
    $this->actingAs(admin(twoFactor: true));
    $haccp = brochureTraining('HACCP');
    $incendie = brochureTraining('Incendie', 'Métiers supports');
    $das = brochureDas();

    $brochure = depositBrochure('Commune', ['training_ids' => [$haccp->id, $incendie->id], 'das_ids' => [$das]]);
    expect($brochure->trainings()->pluck('trainings.id')->sort()->values()->all())->toBe(collect([$haccp->id, $incendie->id])->sort()->values()->all())
        ->and($brochure->das()->pluck('reference_items.id')->all())->toBe([$das]);

    $this->put("/catalogue/brochures/{$brochure->id}", ['title' => 'Commune', 'training_ids' => [$haccp->id], 'das_ids' => []])->assertRedirect();
    expect($brochure->trainings()->pluck('trainings.id')->all())->toBe([$haccp->id])
        ->and($brochure->das()->count())->toBe(0);

    // A training of another organisme, an unknown one, a DAS from another list, a deleted training.
    $other = Tenant::factory()->create();
    CapFormationReferenceSeeder::run($other);
    $foreign = app(TenantContext::class)->run($other, fn () => Training::query()->create([
        'title' => 'Autre', 'das_id' => ReferenceItem::query()->where('list', ReferenceList::Das)->firstOrFail()->id,
    ]));
    $gone = brochureTraining('Supprimée');
    $gone->delete();

    foreach ([[$foreign->id], [(string) Str::uuid7()], ['pas-un-uuid'], [$gone->id]] as $ids) {
        $this->put("/catalogue/brochures/{$brochure->id}", ['title' => 'Commune', 'training_ids' => $ids])->assertSessionHasErrors('training_ids.0');
    }
    $this->put("/catalogue/brochures/{$brochure->id}", ['title' => 'Commune', 'das_ids' => [crmItem(ReferenceList::FundingMode, 'CPF')->id]])->assertSessionHasErrors('das_ids.0');
    expect($brochure->trainings()->pluck('trainings.id')->all())->toBe([$haccp->id]);
});

it('marks the das of a brochure as in use', function () {
    $das = crmItem(ReferenceList::Das, 'Compétences transversales');
    expect(ReferenceUsage::isInUse($das))->toBeFalse();

    $this->actingAs(admin(twoFactor: true));
    depositBrochure('Transversale', ['das_ids' => [$das->id]]);

    expect(ReferenceUsage::isInUse($das))->toBeTrue();
});

it('lists on the training page its own brochures and those of its das, without duplicates or trashed ones', function () {
    $this->actingAs(admin(twoFactor: true));
    $training = brochureTraining('HACCP');
    $das = $training->das_id;

    $both = depositBrochure('Directe et DAS', ['training_ids' => [$training->id], 'das_ids' => [$das]]);
    $direct = depositBrochure('Directe', ['training_ids' => [$training->id]]);
    $viaDas = depositBrochure('Du DAS', ['das_ids' => [$das]]);
    $elsewhere = depositBrochure('Autre DAS', ['das_ids' => [brochureDas('Métiers supports')]]);
    $trashed = depositBrochure('Corbeille', ['training_ids' => [$training->id]]);
    $this->delete("/catalogue/brochures/{$trashed->id}")->assertRedirect();

    $this->get("/catalogue/{$training->id}/edit")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('catalogue/form')
            ->has('brochures', 3)
            ->where('brochures', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$both->id, $direct->id, $viaDas->id])->sort()->values()->all()
                && collect($rows)->firstWhere('id', $both->id)['source'] === 'training'
                && collect($rows)->firstWhere('id', $direct->id)['source'] === 'training'
                && collect($rows)->firstWhere('id', $viaDas->id)['source'] === 'das'));

    expect($elsewhere->exists)->toBeTrue();
});

it('offers brochures to other modules through the public service', function () {
    $this->actingAs(admin(twoFactor: true));
    $haccp = brochureTraining('HACCP');
    $incendie = brochureTraining('Incendie', 'Métiers supports');
    $both = depositBrochure('Directe et DAS', ['training_ids' => [$haccp->id], 'das_ids' => [$haccp->das_id]]);
    $das = depositBrochure('Du DAS', ['das_ids' => [$haccp->das_id]]);
    $other = depositBrochure('Incendie seule', ['training_ids' => [$incendie->id]]);
    $trashed = depositBrochure('Corbeille', ['training_ids' => [$haccp->id]]);
    $this->delete("/catalogue/brochures/{$trashed->id}");

    $service = app(Brochures::class);
    $rows = $service->forTrainings([$haccp->id, $haccp->id]);

    expect(collect($rows)->pluck('brochure_id')->sort()->values()->all())->toBe(collect([$both->id, $das->id])->sort()->values()->all())
        ->and($rows[0])->toHaveKeys(['brochure_id', 'version_id', 'title', 'original_name', 'size'])
        ->and(collect($rows)->firstWhere('brochure_id', $both->id)['version_id'])->toBe($both->current_version_id)
        ->and(collect($rows)->firstWhere('brochure_id', $both->id)['size'])->toBe(strlen(BROCHURE_PDF))
        // A training of the same DAS plus another: each brochure still once.
        ->and($service->forTrainings([$haccp->id, $incendie->id]))->toHaveCount(3)
        ->and($service->forTrainings([]))->toBe([])
        ->and($service->forTrainings(['pas-un-uuid']))->toBe([]);

    $versions = $service->versions([$both->current_version_id, $trashed->current_version_id, 'pas-un-uuid']);
    expect($versions)->toHaveCount(2)
        ->and(collect($versions)->firstWhere('version_id', $trashed->current_version_id))->toHaveKeys(['version_id', 'path', 'original_name', 'size'])
        ->and(collect($versions)->firstWhere('version_id', $both->current_version_id)['path'])->toBe($both->currentVersion->path);

    expect(collect($service->options(null))->pluck('title')->sort()->values()->all())->toBe(['Directe et DAS', 'Du DAS', 'Incendie seule'])
        ->and(collect($service->options('incendie'))->pluck('title')->all())->toBe(['Incendie seule'])
        ->and($service->options('incendie')[0])->toBe(['brochure_id' => $other->id, 'version_id' => $other->current_version_id, 'title' => 'Incendie seule']);
});

it('keeps a replaced version reachable from the service', function () {
    $this->actingAs(admin(twoFactor: true));
    $brochure = depositBrochure('HACCP');
    $first = $brochure->current_version_id;
    $this->put("/catalogue/brochures/{$brochure->id}", ['title' => 'HACCP', 'file' => brochureFile('v2.pdf')])->assertRedirect();

    $rows = app(Brochures::class)->versions([$first]);
    expect($rows)->toHaveCount(1)->and(Storage::disk('local')->exists($rows[0]['path']))->toBeTrue()
        ->and(app(Brochures::class)->options(null)[0]['version_id'])->not->toBe($first);
});

it('sends a brochure to the trash without erasing any file', function () {
    $this->actingAs(admin(twoFactor: true));
    $brochure = depositBrochure('HACCP');
    $path = $brochure->currentVersion->path;

    $this->delete("/catalogue/brochures/{$brochure->id}")->assertRedirect('/catalogue/brochures');

    expect(Brochure::query()->count())->toBe(0)
        ->and(Brochure::withTrashed()->count())->toBe(1)
        ->and(BrochureVersion::query()->count())->toBe(1)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
    $this->get('/catalogue/brochures')->assertInertia(fn (Assert $page) => $page->has('brochures.data', 0));
    $this->get("/catalogue/brochures/{$brochure->id}/download")->assertNotFound();
});

it('keeps a training deletable when a brochure is attached: the attachment disappears', function () {
    $this->actingAs(admin(twoFactor: true));
    $training = brochureTraining('HACCP');
    $brochure = depositBrochure('HACCP', ['training_ids' => [$training->id]]);

    $this->delete("/catalogue/{$training->id}")->assertRedirect('/catalogue');

    expect($training->refresh()->trashed())->toBeTrue()
        ->and($brochure->trainings()->count())->toBe(0)
        ->and(Brochure::query()->count())->toBe(1);
});

it('serves the download from the private disk with the original name', function () {
    $this->actingAs(admin(twoFactor: true));
    $brochure = depositBrochure('HACCP');

    $response = $this->get("/catalogue/brochures/{$brochure->id}/download")->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($response->headers->get('Content-Disposition'))->toContain("filename*=utf-8''Plaquette%20%C3%A9t%C3%A9.pdf")
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen(BROCHURE_PDF))
        ->and($response->streamedContent())->toBe(BROCHURE_PDF);

    Storage::disk('local')->delete($brochure->currentVersion->path);
    $this->get("/catalogue/brochures/{$brochure->id}/download")->assertNotFound();
});

it('never serves a version through another brochure', function () {
    $this->actingAs(admin(twoFactor: true));
    $one = depositBrochure('Une');
    $two = depositBrochure('Deux');

    $this->get("/catalogue/brochures/{$one->id}/versions/{$two->current_version_id}")->assertNotFound();
    $this->get("/catalogue/brochures/{$one->id}/versions/pas-un-uuid")->assertNotFound();
});

it('applies catalogue.view, manage and delete', function () {
    $this->actingAs(admin(twoFactor: true));
    $brochure = depositBrochure('HACCP');

    // Reading and downloading: catalogue.view.
    $this->actingAs(userWithPermissions(['catalogue.view']));
    $this->get('/catalogue/brochures')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('catalogue/brochures/index')->has('brochures.data', 1)->where('can.manage', false)->where('can.delete', false));
    $this->get("/catalogue/brochures/{$brochure->id}/download")->assertOk();
    $this->get("/catalogue/brochures/{$brochure->id}/versions/{$brochure->current_version_id}")->assertOk();

    // Depositing and changing: catalogue.manage.
    $this->get('/catalogue/brochures/create')->assertForbidden();
    $this->post('/catalogue/brochures', ['title' => 'Nope', 'file' => brochureFile()])->assertForbidden();
    $this->get("/catalogue/brochures/{$brochure->id}/edit")->assertForbidden();
    $this->put("/catalogue/brochures/{$brochure->id}", ['title' => 'Nope'])->assertForbidden();
    $this->delete("/catalogue/brochures/{$brochure->id}")->assertForbidden();

    // manage without delete.
    $this->actingAs(userWithPermissions(['catalogue.view', 'catalogue.manage']));
    $this->get('/catalogue/brochures')->assertInertia(fn (Assert $page) => $page->where('can.manage', true)->where('can.delete', false));
    $this->put("/catalogue/brochures/{$brochure->id}", ['title' => 'Renommée'])->assertRedirect();
    $this->delete("/catalogue/brochures/{$brochure->id}")->assertForbidden();

    // Without catalogue.view: nothing.
    $this->actingAs(userWithPermissions(['catalogue.manage']));
    $this->get('/catalogue/brochures')->assertForbidden();
    $this->get("/catalogue/brochures/{$brochure->id}/download")->assertForbidden();

    expect(Brochure::query()->count())->toBe(1);

    $this->actingAs(direction(twoFactor: true))->delete("/catalogue/brochures/{$brochure->id}")->assertRedirect();
    expect(Brochure::query()->count())->toBe(0);
});

it('lets every crm role read and download the library', function () {
    $this->actingAs(admin(twoFactor: true));
    $brochure = depositBrochure('HACCP');

    foreach ([commercial(), assistant()] as $user) {
        $this->actingAs($user)->get('/catalogue/brochures')->assertOk();
        $this->get("/catalogue/brochures/{$brochure->id}/download")->assertOk();
        $this->post('/catalogue/brochures', ['title' => 'Nope', 'file' => brochureFile()])->assertForbidden();
    }
});

it('searches the library by title and keeps the filter in the url', function () {
    $this->actingAs(admin(twoFactor: true));
    depositBrochure('Plaquette HACCP');
    depositBrochure('Incendie');

    $this->get('/catalogue/brochures?q=haccp')->assertInertia(fn (Assert $page) => $page->has('brochures.data', 1)->where('brochures.data.0.title', 'Plaquette HACCP')->where('filters.q', 'haccp'));
    $this->get('/catalogue/brochures')->assertInertia(fn (Assert $page) => $page->has('brochures.data', 2));
});

it('isolates organismes: no read, download, change or deletion of another one\'s brochure', function () {
    $this->actingAs(admin(twoFactor: true));
    $mine = depositBrochure('Mienne');

    $other = Tenant::factory()->create();
    $foreign = app(TenantContext::class)->run($other, function () {
        $brochure = Brochure::query()->create(['title' => 'Étrangère']);
        $version = $brochure->versions()->create([
            'path' => 'tenants/'.tenant()->id.'/brochures/'.$brochure->id.'/x.pdf', 'original_name' => 'secret.pdf', 'size' => 10, 'sha256' => str_repeat('a', 64),
        ]);
        $brochure->forceFill(['current_version_id' => $version->id])->save();
        Storage::disk('local')->put($version->path, BROCHURE_PDF);

        return $brochure;
    });
    inTenant();

    $this->get('/catalogue/brochures')->assertInertia(fn (Assert $page) => $page->has('brochures.data', 1)->where('brochures.data.0.id', $mine->id));
    $this->get("/catalogue/brochures/{$foreign->id}/edit")->assertNotFound();
    $this->get("/catalogue/brochures/{$foreign->id}/download")->assertNotFound();
    $this->get("/catalogue/brochures/{$foreign->id}/versions/{$foreign->current_version_id}")->assertNotFound();
    $this->put("/catalogue/brochures/{$foreign->id}", ['title' => 'Piratée'])->assertNotFound();
    $this->delete("/catalogue/brochures/{$foreign->id}")->assertNotFound();
    $this->get('/catalogue/brochures/pas-un-uuid/download')->assertNotFound();

    $service = app(Brochures::class);
    expect($service->versions([$foreign->current_version_id]))->toBe([])
        ->and(collect($service->options(null))->pluck('title')->all())->toBe(['Mienne']);
    app(TenantContext::class)->run($other, fn () => expect(Brochure::query()->sole()->title)->toBe('Étrangère'));
});

it('stores an original name that is valid UTF-8 even when the browser sent broken bytes', function () {
    $this->actingAs(admin(twoFactor: true));

    $brochure = depositBrochure('HACCP', ['file' => brochureFile("Plaquette \xE9t\xE9 \xFF.pdf")]);
    $name = $brochure->currentVersion->original_name;

    expect(mb_check_encoding($name, 'UTF-8'))->toBeTrue()
        ->and($name)->toEndWith('.pdf');
    $this->get("/catalogue/brochures/{$brochure->id}/download")->assertOk();
});
