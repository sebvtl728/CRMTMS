<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\RoleModel;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Timeline\Attachment;
use App\Modules\Crm\Timeline\AttachmentsProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    tenant();
    Storage::fake('local');
});

const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

function attOrg(): Organisation
{
    return Organisation::query()->create(['name' => 'Hôtel du Lac']);
}

function pdf(string $name = 'Devis été.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, PDF);
}

it('stores an upload on the private disk under the tenant, named by uuid', function () {
    $org = attOrg();
    $user = commercial();

    $this->actingAs($user)->post("/crm/organisations/{$org->id}/attachments", ['file' => pdf()])->assertRedirect();

    $attachment = Attachment::query()->sole();
    expect($attachment->original_name)->toBe('Devis été.pdf')
        ->and($attachment->mime)->toBe('application/pdf')
        ->and($attachment->size)->toBe(strlen(PDF))
        ->and($attachment->uploaded_by)->toBe($user->id)
        ->and($attachment->notable_id)->toBe($org->id)
        ->and($attachment->path)->toStartWith('tenants/'.tenant()->id.'/attachments/')
        ->and(basename($attachment->path))->toMatch('/^[0-9a-f-]{36}$/')
        ->and($attachment->path)->not->toContain('Devis');
    Storage::disk('local')->assertExists($attachment->path);
    expect(Storage::disk('local')->get($attachment->path))->toBe(PDF);
});

it('attaches to a person too', function () {
    $person = makePerson('Roux');
    $this->actingAs(commercial())->post("/crm/people/{$person->id}/attachments", ['file' => pdf('cv.pdf')])->assertRedirect();
    expect(Attachment::query()->sole()->notable_id)->toBe($person->id);
});

it('refuses a script renamed .pdf, and any content that does not match its extension', function () {
    $org = attOrg();
    $this->actingAs(commercial());

    $this->postJson("/crm/organisations/{$org->id}/attachments", ['file' => UploadedFile::fake()->createWithContent('facture.pdf', '<?php echo system($_GET["c"]);')])
        ->assertJsonValidationErrors('file');
    $this->postJson("/crm/organisations/{$org->id}/attachments", ['file' => UploadedFile::fake()->createWithContent('page.txt', '<html><script>alert(1)</script></html>')])
        ->assertJsonValidationErrors('file');
    $this->postJson("/crm/organisations/{$org->id}/attachments", ['file' => UploadedFile::fake()->createWithContent('photo.png', PDF)])
        ->assertJsonValidationErrors('file');
    $this->postJson("/crm/organisations/{$org->id}/attachments", ['file' => UploadedFile::fake()->createWithContent('vide.txt', '')])
        ->assertJsonValidationErrors('file');

    expect(Attachment::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses extensions outside the list, double extensions and oversize files', function () {
    $org = attOrg();
    $this->actingAs(commercial());

    foreach (['malware.exe', 'run.php', 'page.html', 'icone.svg', 'archive.zip', 'sans-extension', 'devis.pdf.php'] as $name) {
        $this->postJson("/crm/organisations/{$org->id}/attachments", ['file' => UploadedFile::fake()->createWithContent($name, PDF)])
            ->assertJsonValidationErrors('file');
    }
    $this->postJson("/crm/organisations/{$org->id}/attachments", ['file' => UploadedFile::fake()->create('gros.pdf', 20 * 1024 + 1, 'application/pdf')])
        ->assertJsonValidationErrors('file');
    $this->postJson("/crm/organisations/{$org->id}/attachments", [])->assertJsonValidationErrors('file');

    expect(Attachment::count())->toBe(0);
});

it('accepts the allowed formats', function () {
    $org = attOrg();
    $this->actingAs(commercial());
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

    foreach ([['a.txt', "Bonjour\n"], ['b.csv', "a;b\n1;2\n"], ['c.png', $png]] as [$name, $content]) {
        $this->post("/crm/organisations/{$org->id}/attachments", ['file' => UploadedFile::fake()->createWithContent($name, $content)])
            ->assertSessionHasNoErrors();
    }

    expect(Attachment::query()->orderBy('original_name')->pluck('mime', 'original_name')->all())
        ->toBe(['a.txt' => 'text/plain', 'b.csv' => 'text/csv', 'c.png' => 'image/png']);
});

it('downloads as an attachment, without sniffing, with the original name', function () {
    $org = attOrg();
    $this->actingAs(commercial())->post("/crm/organisations/{$org->id}/attachments", ['file' => pdf()]);
    $attachment = Attachment::query()->sole();

    $response = $this->get("/crm/attachments/{$attachment->id}")->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('attachment;')->toContain("filename*=UTF-8''Devis%20%C3%A9t%C3%A9.pdf")
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Type'))->toStartWith('application/pdf')
        ->and($response->streamedContent())->toBe(PDF);
});

it('keeps hostile file names harmless', function () {
    $org = attOrg();
    $this->actingAs(commercial())->post("/crm/organisations/{$org->id}/attachments", ['file' => UploadedFile::fake()->createWithContent("../../etc/\"x\r\n.pdf", PDF)]);
    $attachment = Attachment::query()->sole();

    $response = $this->get("/crm/attachments/{$attachment->id}")->assertOk();

    expect($attachment->original_name)->not->toContain('/')->not->toContain("\r")
        ->and($attachment->path)->toStartWith('tenants/')
        ->and($response->headers->get('Content-Disposition'))->not->toContain("\r")->not->toContain("\n");
});

it('forbids the upload and the removal without crm.update', function () {
    $org = attOrg();
    $this->actingAs(commercial())->post("/crm/organisations/{$org->id}/attachments", ['file' => pdf()]);
    $attachment = Attachment::query()->sole();

    $this->actingAs(userWithRole(Role::Direction, twoFactor: true))->get("/crm/attachments/{$attachment->id}")->assertOk();
    $this->post("/crm/organisations/{$org->id}/attachments", ['file' => pdf()])->assertForbidden();
    $this->delete("/crm/attachments/{$attachment->id}")->assertForbidden();

    expect(Attachment::count())->toBe(1);
});

it('answers 403 to the download of a user without crm.view', function () {
    $org = attOrg();
    $this->actingAs(commercial())->post("/crm/organisations/{$org->id}/attachments", ['file' => pdf()]);
    $attachment = Attachment::query()->sole();

    RoleModel::findByName('direction')->revokePermissionTo('crm.view');

    $this->actingAs(userWithRole(Role::Direction, twoFactor: true))->get("/crm/attachments/{$attachment->id}")->assertForbidden();
});

it('answers 404 for the file of another organisme, whatever the user', function () {
    $other = Tenant::factory()->create();
    $foreign = app(TenantContext::class)->run($other, function () {
        $attachment = new Attachment(['original_name' => 'secret.pdf', 'mime' => 'application/pdf', 'size' => 10, 'path' => 'tenants/'.tenant()->id.'/attachments/'.fake()->uuid()]);
        $attachment->notable()->associate(Organisation::query()->create(['name' => 'Autre']));
        $attachment->save();

        return $attachment;
    });
    inTenant();

    $this->actingAs(admin(twoFactor: true))->get("/crm/attachments/{$foreign->id}")->assertNotFound();
    $this->delete("/crm/attachments/{$foreign->id}")->assertNotFound();
    $this->get('/crm/attachments/pas-un-uuid')->assertNotFound();
});

it('answers 404 when the file is missing on disk', function () {
    $org = attOrg();
    $this->actingAs(commercial())->post("/crm/organisations/{$org->id}/attachments", ['file' => pdf()]);
    $attachment = Attachment::query()->sole();
    Storage::disk('local')->delete($attachment->path);

    $this->get("/crm/attachments/{$attachment->id}")->assertNotFound();
});

it('soft-deletes the row and keeps the file for the GDPR purge', function () {
    $org = attOrg();
    $this->actingAs(commercial())->post("/crm/organisations/{$org->id}/attachments", ['file' => pdf()]);
    $attachment = Attachment::query()->sole();

    $this->delete("/crm/attachments/{$attachment->id}")->assertRedirect();

    expect(Attachment::count())->toBe(0)->and(Attachment::withTrashed()->count())->toBe(1);
    Storage::disk('local')->assertExists($attachment->path);
    $this->get("/crm/attachments/{$attachment->id}")->assertNotFound();
});

it('never writes the file name or the path in the journal', function () {
    $org = attOrg();
    $this->actingAs(commercial())->post("/crm/organisations/{$org->id}/attachments", ['file' => pdf('CV Dupont.pdf')]);

    $dump = json_encode(DB::table('audit_logs')->get(), JSON_UNESCAPED_UNICODE);

    expect($dump)->not->toContain('Dupont')->not->toContain('attachments/');
});

it('stores nothing outside the private disk', function () {
    $org = attOrg();
    $this->actingAs(commercial())->post("/crm/organisations/{$org->id}/attachments", ['file' => pdf()]);

    expect(glob(public_path('*.pdf')))->toBe([])
        ->and(config('filesystems.disks.local.root'))->not->toContain('public');
});

// --- Retention: files of fiches trashed for more than 12 months -----------

/** An attachment of $fiche, stored as the upload does, the fiche trashed $monthsAgo months ago (null: not trashed). */
function attachedTo(Organisation|Person $fiche, ?int $monthsAgo): Attachment
{
    $path = TenantStorage::path('attachments', (string) Str::uuid());
    Storage::disk('local')->put($path, PDF);
    $attachment = new Attachment(['original_name' => 'Devis.pdf', 'mime' => 'application/pdf', 'size' => strlen(PDF), 'path' => $path]);
    $attachment->notable()->associate($fiche);
    $attachment->save();
    if ($monthsAgo !== null) {
        DB::table($fiche->getTable())->where('id', $fiche->id)->update(['deleted_at' => now()->subMonths($monthsAgo)->subDay()]);
    }

    return $attachment;
}

it('deletes the files of fiches trashed for more than 12 months, keeping the rows', function () {
    $oldOrg = attachedTo(attOrg(), 12);
    $oldPerson = attachedTo(makePerson('Roux'), 13);
    $recent = attachedTo(Organisation::query()->create(['name' => 'Garage Martin']), 11);
    $active = attachedTo(Organisation::query()->create(['name' => 'Café des Arts']), null);

    expect(Artisan::call('tms:crm:purge-attachments'))->toBe(0)
        ->and(Artisan::output())->toContain('Fichiers supprimés : 2.');
    Artisan::call('tms:crm:purge-attachments'); // idempotent
    expect(Artisan::output())->toContain('Fichiers supprimés : 0.');

    Storage::disk('local')->assertMissing([$oldOrg->path, $oldPerson->path]);
    Storage::disk('local')->assertExists([$recent->path, $active->path]);
    expect(Attachment::query()->whereNotNull('file_deleted_at')->pluck('id')->sort()->values()->all())->toBe(collect([$oldOrg->id, $oldPerson->id])->sort()->values()->all())
        ->and(Attachment::query()->count())->toBe(4);
});

it('shows « Fichier supprimé » in the history and answers 410 to its download', function () {
    $org = attOrg();
    $attachment = attachedTo($org, 13);
    Artisan::call('tms:crm:purge-attachments');
    $org->restore();

    $entry = app(AttachmentsProvider::class)->entriesFor($org->refresh(), null, 10)->sole();
    expect($entry->meta['file_deleted'])->toBeTrue()
        ->and($entry->body)->toBe('Fichier supprimé');

    $this->actingAs(commercial())->get("/crm/attachments/{$attachment->id}")
        ->assertStatus(410)
        ->assertSee('Fichier supprimé : la fiche était dans la corbeille depuis plus de 12 mois.');
});

it('keeps the file flagged present when its deletion fails, retrying on the next run', function () {
    $stuck = attachedTo(attOrg(), 13);
    $gone = attachedTo(Organisation::query()->create(['name' => 'Garage Martin']), 13);
    Storage::disk('local')->delete($gone->path); // already absent: nothing left to delete
    $disk = Storage::disk('local');
    $failing = Mockery::mock($disk)->makePartial();
    $failing->shouldReceive('delete')->andReturn(false);
    Storage::set('local', $failing);

    Artisan::call('tms:crm:purge-attachments');

    expect($stuck->refresh()->file_deleted_at)->toBeNull()
        ->and($gone->refresh()->file_deleted_at)->not->toBeNull();

    Storage::set('local', $disk);
    Artisan::call('tms:crm:purge-attachments');

    expect($stuck->refresh()->file_deleted_at)->not->toBeNull();
    Storage::disk('local')->assertMissing($stuck->path);
});

it('purges every night, for every organisme', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'tms:crm:purge-attachments'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('45 3 * * *');
});
