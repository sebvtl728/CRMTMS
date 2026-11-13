<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Import\ImportProfile;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Organisations\Organisation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->user = commercial();
});

/** A csv upload as the browser sends it. */
function csvUpload(string $content, string $name = 'clients.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

const ORGS_CSV = "Raison sociale;SIRET;Ville;Origine\nHôtel du Lac;73282932000074;Annecy;Salon\nGarage Martin;;Pau;Radio locale\nCafé des Arts;;Paris;\n";

/** The batch of $user after the upload step. */
function uploaded(User $user, string $csv = ORGS_CSV, ImportTarget $target = ImportTarget::Organisations, array $options = []): ImportBatch
{
    test()->actingAs($user);

    return uploadImport($csv, $target, $options);
}

/** A batch simulated with the proposed mapping (by $user). */
function simulatedBatch(User $user, string $csv = ORGS_CSV, array $values = []): ImportBatch
{
    $batch = uploaded($user, $csv);
    simulateImport($batch, [], $values);

    return $batch->refresh();
}

it('is reserved to users holding crm.import', function (string $method, string $uri) {
    $this->actingAs(assistant());

    $this->call($method, $uri)->assertForbidden();
})->with([
    ['GET', '/crm/import'],
    ['GET', '/crm/import/new'],
    ['POST', '/crm/import'],
    ['GET', '/crm/import/019aaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa'],
    ['GET', '/crm/import/019aaaaa-aaaa-7aaa-8aaa-aaaaaaaaaaaa/status'],
]);

it('shows the upload step with the targets and the saved profiles', function () {
    $this->actingAs($this->user);
    ImportProfile::query()->create(['name' => 'Export Formevol', 'target' => ImportTarget::Organisations, 'field_map' => ['Nom' => 'name'], 'value_map' => [], 'options' => []]);

    $this->get('/crm/import/new')->assertInertia(fn (Assert $page) => $page
        ->component('crm/import/upload')
        ->where('targets.0.value', 'organisations')
        ->has('targets', 4)
        ->where('profiles.0.name', 'Export Formevol')
        ->where('profiles.0.target', 'organisations')
        ->where('maxMegabytes', 20));
});

it('uploads a csv, reads it and goes to the columns step', function () {
    $this->actingAs($this->user);

    $response = $this->post('/crm/import', ['file' => csvUpload(ORGS_CSV), 'target' => 'organisations']);

    $batch = ImportBatch::query()->sole();
    $response->assertRedirect("/crm/import/{$batch->id}/mapping");
    expect($batch->user_id)->toBe($this->user->id)
        ->and($batch->original_name)->toBe('clients.csv')
        ->and($batch->target)->toBe(ImportTarget::Organisations)
        ->and($batch->status)->toBe(ImportBatch::UPLOADED);
});

it('refuses a bad upload with a french message', function (array $input, string $field) {
    $this->actingAs($this->user);

    $this->post('/crm/import', $input)->assertSessionHasErrors($field);
    expect(ImportBatch::query()->count())->toBe(0);
})->with(fn () => [
    'no file' => [['target' => 'organisations'], 'file'],
    'no target' => [['file' => csvUpload(ORGS_CSV)], 'target'],
    'unknown target' => [['file' => csvUpload(ORGS_CSV), 'target' => 'factures'], 'target'],
    'pdf' => [['file' => UploadedFile::fake()->create('devis.pdf', 10, 'application/pdf'), 'target' => 'organisations'], 'file'],
    'old xls' => [['file' => UploadedFile::fake()->create('clients.xls', 10), 'target' => 'organisations'], 'file'],
    'over 20 MB' => [['file' => UploadedFile::fake()->create('gros.csv', 20 * 1024 + 1), 'target' => 'organisations'], 'file'],
    'unreadable xlsx' => [['file' => csvUpload('pas un classeur', 'faux.xlsx'), 'target' => 'organisations'], 'file'],
    'empty csv' => [['file' => csvUpload('', 'vide.csv'), 'target' => 'organisations'], 'file'],
]);

it('takes the settings of a profile and refuses one of another target', function () {
    $this->actingAs($this->user);
    $profile = ImportProfile::query()->create(['name' => 'Export Formevol', 'target' => ImportTarget::Organisations,
        'field_map' => ['Raison sociale' => 'name', 'Ville' => 'city'], 'value_map' => ['origin' => ['Radio locale' => '__create__']],
        'options' => ['strategy' => 'merge', 'source_system' => 'Formevol']]);

    $this->post('/crm/import', ['file' => csvUpload(ORGS_CSV), 'target' => 'people', 'profile_id' => $profile->id])->assertSessionHasErrors('profile_id');

    $this->post('/crm/import', ['file' => csvUpload(ORGS_CSV), 'target' => 'organisations', 'profile_id' => $profile->id]);
    $batch = ImportBatch::query()->sole();
    expect($batch->profile_id)->toBe($profile->id)
        ->and($batch->field_map)->toEqual(['Raison sociale' => 'name', 'Ville' => 'city'])
        ->and($batch->strategy()->value)->toBe('merge')
        ->and($batch->sourceSystem())->toBe('Formevol');
});

it('shows the columns step: 5 first rows, proposed mapping, required fields', function () {
    $csv = "Raison sociale;Ville;Colonne inconnue\n".implode('', array_map(fn ($i) => "Entreprise {$i};Lyon;x\n", range(1, 8)));
    $batch = uploaded($this->user, $csv);

    $this->get("/crm/import/{$batch->id}/mapping")->assertInertia(fn (Assert $page) => $page
        ->component('crm/import/mapping')
        ->where('batch.original_name', 'fichier.csv')
        ->where('headers', ['Raison sociale', 'Ville', 'Colonne inconnue'])
        ->has('preview', 5)
        ->where('preview.0', ['Entreprise 1', 'Lyon', 'x'])
        ->where('map', ['Raison sociale' => 'name', 'Ville' => 'city'])
        ->where('fields.0', ['value' => 'name', 'label' => 'Raison sociale', 'required' => true])
        ->where('fields.1.required', false)
        ->where('identifierFields', ['source_id'])
        ->where('sourceSystem', null)
        ->has('info'));
});

it('prefills the columns step from the profile', function () {
    $this->actingAs($this->user);
    $profile = ImportProfile::query()->create(['name' => 'Export Formevol', 'target' => ImportTarget::Organisations,
        'field_map' => ['Ville' => 'name', 'Raison sociale' => 'city'], 'value_map' => [], 'options' => ['source_system' => 'Formevol']]);
    $this->post('/crm/import', ['file' => csvUpload(ORGS_CSV), 'target' => 'organisations', 'profile_id' => $profile->id]);
    $batch = ImportBatch::query()->sole();

    $this->get("/crm/import/{$batch->id}/mapping")->assertInertia(fn (Assert $page) => $page
        ->where('map', ['Ville' => 'name', 'Raison sociale' => 'city'])
        ->where('sourceSystem', 'Formevol')
        ->where('profileName', 'Export Formevol'));
});

it('saves the mapping, simulates and moves on', function () {
    $batch = uploaded($this->user);

    $this->put("/crm/import/{$batch->id}/mapping", ['map' => ['Raison sociale' => 'name', 'SIRET' => 'siret', 'Ville' => 'city', 'Origine' => 'origin']])
        ->assertRedirect("/crm/import/{$batch->id}");

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatch::SIMULATED) // sync queue in tests
        ->and($batch->field_map)->toEqual(['Raison sociale' => 'name', 'SIRET' => 'siret', 'Ville' => 'city', 'Origine' => 'origin'])
        ->and($batch->simulation['to_create'])->toBe(2)
        ->and($batch->simulation['errors'])->toBe(1) // « Radio locale » is not an origin yet (next step)
        ->and(Organisation::query()->count())->toBe(0);
});

it('refuses an invalid mapping', function (array $input, string $error) {
    $batch = uploaded($this->user);

    $this->put("/crm/import/{$batch->id}/mapping", $input)->assertSessionHasErrors($error);
    expect($batch->refresh()->status)->toBe(ImportBatch::UPLOADED)->and($batch->field_map)->toBeNull();
})->with([
    'unknown field' => [['map' => ['Ville' => 'password']], 'map'],
    'same field twice' => [['map' => ['Raison sociale' => 'name', 'Ville' => 'name']], 'map'],
    'column not in the file' => [['map' => ['Inconnue' => 'name']], 'map'],
    'required field missing' => [['map' => ['Ville' => 'city']], 'map'],
    'identifier without source software' => [['map' => ['Raison sociale' => 'name', 'SIRET' => 'source_id']], 'source_system'],
]);

it('keeps an identifier mapping with its source software, and saves a profile', function () {
    $batch = uploaded($this->user);

    $this->put("/crm/import/{$batch->id}/mapping", [
        'map' => ['Raison sociale' => 'name', 'SIRET' => 'source_id'], 'source_system' => ' Formevol ', 'profile_name' => 'Export Formevol',
    ])->assertRedirect();

    $profile = ImportProfile::query()->sole();
    expect($profile->name)->toBe('Export Formevol')
        ->and($profile->target)->toBe(ImportTarget::Organisations)
        ->and($profile->field_map)->toEqual(['Raison sociale' => 'name', 'SIRET' => 'source_id'])
        ->and($profile->options['source_system'])->toBe('Formevol')
        ->and($batch->refresh()->sourceSystem())->toBe('Formevol');

    // Same name again: updated, not duplicated.
    $second = uploaded($this->user);
    $this->put("/crm/import/{$second->id}/mapping", ['map' => ['Raison sociale' => 'name'], 'profile_name' => 'Export Formevol']);
    expect(ImportProfile::query()->count())->toBe(1)->and(ImportProfile::query()->sole()->field_map)->toEqual(['Raison sociale' => 'name']);
});

it('sends to the values step when the file has values without correspondence', function () {
    $batch = simulatedBatch($this->user);

    $this->get("/crm/import/{$batch->id}")->assertRedirect("/crm/import/{$batch->id}/values");

    $this->get("/crm/import/{$batch->id}/values")->assertInertia(fn (Assert $page) => $page
        ->component('crm/import/values')
        ->has('blocks', 1)
        ->where('blocks.0.field', 'origin')
        ->where('blocks.0.label', 'Origine')
        ->where('blocks.0.values', ['Radio locale'])
        ->where('blocks.0.canCreate', true)
        ->where('blocks.0.options', fn ($options) => collect($options)->pluck('label')->contains('Salon'))
        ->where('blocks.0.choices', []));
});

it('records the value choices, simulates again and shows the simulation', function () {
    $batch = simulatedBatch($this->user);

    $this->put("/crm/import/{$batch->id}/values", ['values' => ['origin' => ['Radio locale' => '__create__']]])
        ->assertRedirect("/crm/import/{$batch->id}");

    $batch->refresh();
    expect($batch->value_map)->toEqual(['origin' => ['Radio locale' => '__create__']])
        ->and($batch->simulation['errors'])->toBe(0)
        ->and(ReferenceItem::query()->where('list', ReferenceList::ContactOrigin->value)->where('label', 'Radio locale')->exists())->toBeFalse(); // simulation only

    $this->get("/crm/import/{$batch->id}")->assertInertia(fn (Assert $page) => $page
        ->component('crm/import/simulation')
        ->where('simulation.to_create', 3)
        ->where('simulation.errors', 0)
        ->where('canRun', true));
});

it('lets the user map a value to an existing item or leave it empty, and refuses a foreign id', function () {
    $batch = simulatedBatch($this->user);
    $salon = ReferenceItem::query()->where('list', ReferenceList::ContactOrigin->value)->where('label', 'Salon')->sole();

    $this->put("/crm/import/{$batch->id}/values", ['values' => ['origin' => ['Radio locale' => $salon->id]]])->assertRedirect();
    expect($batch->refresh()->value_map)->toEqual(['origin' => ['Radio locale' => $salon->id]]);

    $this->put("/crm/import/{$batch->id}/values", ['values' => ['origin' => ['Radio locale' => '__empty__']]])->assertRedirect();
    expect($batch->refresh()->value_map)->toEqual(['origin' => ['Radio locale' => '__empty__']]);

    $this->put("/crm/import/{$batch->id}/values", ['values' => ['password' => ['x' => '__create__']]])->assertSessionHasErrors('values');
    $this->put("/crm/import/{$batch->id}/values", ['values' => ['origin' => ['Radio locale' => 'n-importe-quoi']]])->assertSessionHasErrors('values');
});

it('shows the simulation: counters, the first 100 error lines, strategy and overwrite warning', function () {
    $existing = Organisation::query()->create(['name' => 'Hôtel du Lac', 'siret' => '73282932000074']);
    $csv = "Raison sociale;SIRET;Ville\nHôtel du Lac;73282932000074;Annecy\nGarage Martin;;Pau\n".str_repeat(";;Paris\n", 120);
    $batch = simulatedBatch($this->user, $csv);

    $this->get("/crm/import/{$batch->id}")->assertInertia(fn (Assert $page) => $page
        ->component('crm/import/simulation')
        ->where('simulation.to_create', 1)
        ->where('simulation.to_skip', 1)
        ->where('simulation.errors', 120)
        ->has('simulation.error_rows', 100)
        ->where('simulation.error_rows.0.line', 4)
        ->where('simulation.error_rows.0.messages.0', fn ($m) => str_contains($m, 'Raison sociale'))
        ->where('strategy.value', 'skip')
        ->has('strategy.options', 3)
        ->where('strategy.options.1.value', 'merge')
        ->where('canRun', true));
    expect($existing->refresh()->city)->toBeNull();
});

it('re-simulates with another duplicate strategy', function () {
    Organisation::query()->create(['name' => 'Hôtel du Lac', 'siret' => '73282932000074']);
    $batch = simulatedBatch($this->user, "Raison sociale;SIRET;Ville\nHôtel du Lac;73282932000074;Annecy\n");
    expect($batch->simulation['to_skip'])->toBe(1);

    $this->put("/crm/import/{$batch->id}/strategy", ['strategy' => 'merge'])->assertRedirect("/crm/import/{$batch->id}");
    $batch->refresh();
    expect($batch->strategy()->value)->toBe('merge')->and($batch->simulation['to_update'])->toBe(1);

    $this->put("/crm/import/{$batch->id}/strategy", ['strategy' => 'delete'])->assertSessionHasErrors('strategy');
});

it('shows a blocked simulation with a way back to the columns', function () {
    $batch = uploaded($this->user);
    $this->put("/crm/import/{$batch->id}/mapping", ['map' => ['Raison sociale' => 'name']]);
    $batch->refresh();
    // A mapping saved before (profile) that lost its required column: simulation blocked.
    DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'uploaded', 'simulation' => null, 'field_map' => json_encode(['Ville' => 'city'])]);

    $this->get("/crm/import/{$batch->id}")->assertRedirect("/crm/import/{$batch->id}/mapping");
});

it('runs the import, then shows the report with a download', function () {
    $batch = simulatedBatch($this->user, "Raison sociale;Ville\nGarage Martin;Pau\nCafé des Arts;Paris\n;Lyon\n");

    $this->post("/crm/import/{$batch->id}/run")->assertRedirect("/crm/import/{$batch->id}");

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatch::DONE)->and([$batch->created, $batch->errors])->toBe([2, 1])
        ->and(Organisation::query()->count())->toBe(2);

    $this->get("/crm/import/{$batch->id}")->assertInertia(fn (Assert $page) => $page
        ->component('crm/import/report')
        ->where('batch.status.value', 'done')
        ->where('batch.created', 2)
        ->where('batch.errors', 1)
        ->has('rows', 1)
        ->where('rows.0.line', 4)
        ->where('canUndo', true)
        ->where('hasReport', true));

    $download = $this->get("/crm/import/{$batch->id}/report");
    $download->assertOk();
    expect($download->headers->get('content-disposition'))->toContain('.xlsx');
});

it('does not run twice nor a batch that was not simulated', function () {
    $batch = uploaded($this->user);

    $this->post("/crm/import/{$batch->id}/run")->assertRedirect("/crm/import/{$batch->id}");
    expect($batch->refresh()->status)->toBe(ImportBatch::UPLOADED)->and(Organisation::query()->count())->toBe(0);

    $done = simulatedBatch($this->user, "Raison sociale\nGarage Martin\n");
    $this->post("/crm/import/{$done->id}/run");
    $this->post("/crm/import/{$done->id}/run");
    expect(Organisation::query()->count())->toBe(1);
});

it('refuses the report download before the end', function () {
    $batch = simulatedBatch($this->user);

    $this->get("/crm/import/{$batch->id}/report")->assertNotFound();
});

it('polls the progress as json', function () {
    $batch = simulatedBatch($this->user, "Raison sociale\nGarage Martin\nCafé des Arts\n");
    DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'running', 'total' => 1, 'created' => 1]);

    $this->getJson("/crm/import/{$batch->id}/status")->assertOk()
        ->assertJson(['status' => 'running', 'phase' => 'run', 'processed' => 1, 'total' => 2, 'percent' => 50, 'stuck' => false, 'canFail' => false, 'finished' => false]);

    DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'simulating', 'cursor_line' => 120]);
    $this->getJson("/crm/import/{$batch->id}/status")->assertJson(['phase' => 'simulation', 'processed' => 120, 'finished' => false]);

    DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'done']);
    $this->getJson("/crm/import/{$batch->id}/status")->assertJson(['status' => 'done', 'finished' => true]);
});

it('shows a running import as progress, and a stuck one as blocked with the action', function () {
    $batch = simulatedBatch($this->user);
    DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'running']);

    $this->get("/crm/import/{$batch->id}")->assertInertia(fn (Assert $page) => $page->component('crm/import/progress')->where('phase', 'run'));

    DB::table('import_batches')->where('id', $batch->id)->update(['updated_at' => now()->subMinutes(16)]);
    $this->getJson("/crm/import/{$batch->id}/status")->assertJson(['stuck' => true, 'canFail' => true]);
    $this->get("/crm/import/{$batch->id}")->assertInertia(fn (Assert $page) => $page->where('stuck', true)->where('canFail', true));
});

it('lets the author mark a stuck import as failed, then undo what it created', function () {
    $batch = simulatedBatch($this->user, "Raison sociale\nGarage Martin\nCafé des Arts\n");
    $this->post("/crm/import/{$batch->id}/run");
    DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'running', 'updated_at' => now()->subMinutes(20)]);

    $this->post("/crm/import/{$batch->id}/fail")->assertRedirect("/crm/import/{$batch->id}")->assertSessionHas('status');
    expect($batch->refresh()->status)->toBe(ImportBatch::FAILED)
        ->and(AuditLog::query()->where('event', 'crm.import_failed_marked')->count())->toBe(1);

    $this->post("/crm/import/{$batch->id}/undo")->assertRedirect("/crm/import/{$batch->id}");
    expect($batch->refresh()->status)->toBe(ImportBatch::UNDONE)->and(Organisation::query()->count())->toBe(0);
});

it('refuses to mark an import that is progressing as failed', function () {
    $batch = simulatedBatch($this->user);
    DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'running', 'updated_at' => now()->subMinutes(3)]);

    $this->post("/crm/import/{$batch->id}/fail")->assertSessionHasErrors('batch');
    expect($batch->refresh()->status)->toBe(ImportBatch::RUNNING);
});

it('previews an undo: how many fiches go, how many modified ones stay', function () {
    $batch = simulatedBatch($this->user, "Raison sociale;Ville\nGarage Martin;Pau\nCafé des Arts;Paris\nBar du Port;Nice\n");
    $this->post("/crm/import/{$batch->id}/run");
    $this->travel(5)->minutes();
    Organisation::query()->where('name', 'Garage Martin')->sole()->update(['city' => 'Tarbes']);

    $this->getJson("/crm/import/{$batch->id}/undo")->assertOk()->assertJson(['removable' => 2, 'keptModified' => 1, 'canUndo' => true])
        ->assertJsonPath('byType.organisation', 2);
});

it('undoes an import after confirmation and keeps what was modified', function () {
    $batch = simulatedBatch($this->user, "Raison sociale;Ville\nGarage Martin;Pau\nCafé des Arts;Paris\n");
    $this->post("/crm/import/{$batch->id}/run");
    $this->travel(5)->minutes();
    Organisation::query()->where('name', 'Garage Martin')->sole()->update(['city' => 'Tarbes']);

    $this->post("/crm/import/{$batch->id}/undo")->assertRedirect("/crm/import/{$batch->id}");

    expect($batch->refresh()->status)->toBe(ImportBatch::UNDONE)
        ->and(Organisation::query()->pluck('name')->all())->toBe(['Garage Martin']);
    $this->get("/crm/import/{$batch->id}")->assertInertia(fn (Assert $page) => $page
        ->component('crm/import/report')->where('batch.status.value', 'undone')->where('batch.undo.removed', 1)->where('batch.undo.kept', 1)->where('canUndo', false));
});

it('refuses to undo an import that is not finished', function () {
    $batch = simulatedBatch($this->user);

    $this->post("/crm/import/{$batch->id}/undo")->assertSessionHasErrors('batch');
    $this->getJson("/crm/import/{$batch->id}/undo")->assertJson(['canUndo' => false]);
});

it('lists the history: everyone sees their own imports, the administrator all of them', function () {
    $mine = simulatedBatch($this->user, "Raison sociale\nGarage Martin\n");
    $colleague = commercial();
    $theirs = simulatedBatch($colleague, "Raison sociale\nCafé des Arts\n");
    $this->actingAs($this->user)->post("/crm/import/{$mine->id}/run");

    $this->actingAs($this->user)->get('/crm/import')->assertInertia(fn (Assert $page) => $page
        ->component('crm/import/index')
        ->has('batches.data', 1)
        ->where('batches.data.0.id', $mine->id)
        ->where('batches.data.0.file', 'fichier.csv')
        ->where('batches.data.0.target', 'Entreprises')
        ->where('batches.data.0.author', $this->user->name)
        ->where('batches.data.0.status.value', 'done')
        ->where('batches.data.0.created', 1)
        ->where('batches.data.0.canUndo', true)
        ->where('seesAll', false));

    $this->actingAs(admin(true))->get('/crm/import')->assertInertia(fn (Assert $page) => $page
        ->has('batches.data', 2)->where('seesAll', true)
        ->where('batches.data', fn ($rows) => collect($rows)->pluck('id')->contains($theirs->id)));
});

it('hides the import of a colleague from a commercial but not from the administrator', function () {
    $colleague = commercial();
    $theirs = simulatedBatch($colleague, "Raison sociale\nCafé des Arts\n");

    $this->actingAs($this->user);
    foreach (['get' => '', 'getJson' => '/status', 'get ' => '/mapping', 'getJson ' => '/undo'] as $method => $suffix) {
        $this->{trim($method)}("/crm/import/{$theirs->id}{$suffix}")->assertNotFound();
    }
    $this->post("/crm/import/{$theirs->id}/run")->assertNotFound();
    $this->post("/crm/import/{$theirs->id}/undo")->assertNotFound();
    $this->post("/crm/import/{$theirs->id}/fail")->assertNotFound();
    expect($theirs->refresh()->status)->toBe(ImportBatch::SIMULATED);

    $this->actingAs(admin(true))->get("/crm/import/{$theirs->id}")->assertOk();
});

it('lets the administrator mark a colleague\'s stuck import as failed', function () {
    $colleague = commercial();
    $theirs = simulatedBatch($colleague, "Raison sociale\nCafé des Arts\n");
    DB::table('import_batches')->where('id', $theirs->id)->update(['status' => 'running', 'updated_at' => now()->subMinutes(20)]);

    $this->actingAs(admin(true))->post("/crm/import/{$theirs->id}/fail")->assertRedirect();

    expect($theirs->refresh()->status)->toBe(ImportBatch::FAILED);
});

it('does not show the import of another organisme, even to its administrator', function () {
    $other = Tenant::factory()->create();
    $strangerBatch = app(TenantContext::class)->run($other, function () {
        $admin = User::factory()->withRole(Role::AdminOrganisme)->withTwoFactor()->create();
        test()->actingAs($admin);

        return uploadImport("Raison sociale\nGarage Martin\n", ImportTarget::Organisations)->id;
    });
    $mine = simulatedBatch($this->user);

    $this->actingAs(admin(true));
    $this->get("/crm/import/{$strangerBatch}")->assertNotFound();
    $this->getJson("/crm/import/{$strangerBatch}/status")->assertNotFound();
    $this->get('/crm/import')->assertInertia(fn (Assert $page) => $page->has('batches.data', 1)->where('batches.data.0.id', $mine->id));
});

it('refuses the steps of a batch that already ran', function () {
    $batch = simulatedBatch($this->user, "Raison sociale\nGarage Martin\n");
    $this->post("/crm/import/{$batch->id}/run");

    $this->get("/crm/import/{$batch->id}/mapping")->assertRedirect("/crm/import/{$batch->id}");
    $this->put("/crm/import/{$batch->id}/mapping", ['map' => ['Raison sociale' => 'name']])->assertRedirect("/crm/import/{$batch->id}");
    $this->put("/crm/import/{$batch->id}/strategy", ['strategy' => 'merge'])->assertRedirect("/crm/import/{$batch->id}");
    expect($batch->refresh()->status)->toBe(ImportBatch::DONE)->and($batch->strategy()->value)->toBe('skip');
});

it('offers the columns step for every kind of file, with the right required fields', function (ImportTarget $target, string $csv, array $required) {
    $batch = uploaded($this->user, $csv, $target);

    $this->get("/crm/import/{$batch->id}/mapping")->assertInertia(fn (Assert $page) => $page
        ->component('crm/import/mapping')
        ->where('fields', fn ($fields) => collect($fields)->where('required', true)->pluck('value')->all() === $required));
})->with([
    'entreprises' => [ImportTarget::Organisations, "Raison sociale\nA\n", ['name']],
    'personnes' => [ImportTarget::People, "Nom;Prénom\nDupont;Léa\n", ['last_name']],
    'interlocuteurs' => [ImportTarget::Affiliations, "Nom;Entreprise\nDupont;Acme\n", ['last_name']],
    'notes' => [ImportTarget::Notes, "SIRET;Note\n73282932000074;Appel\n", ['body']],
]);

it('reads an excel workbook for the preview', function () {
    $this->actingAs($this->user);
    $upload = new UploadedFile(base_path('tests/Fixtures/imports/contacts.xlsx'), 'contacts.xlsx', null, null, true);

    $this->post('/crm/import', ['file' => $upload, 'target' => 'affiliations']);
    $batch = ImportBatch::query()->sole();

    $this->get("/crm/import/{$batch->id}/mapping")->assertInertia(fn (Assert $page) => $page
        ->where('info', 'Classeur Excel : première feuille.')
        ->where('preview', fn ($rows) => count($rows) > 0));
});

it('shows the Importer link on the lists to users who may import', function () {
    $this->actingAs($this->user)->get('/crm/organisations')->assertInertia(fn (Assert $page) => $page->where('can.import', true));
    $this->actingAs($this->user)->get('/crm/people')->assertInertia(fn (Assert $page) => $page->where('can.import', true));
    $this->actingAs(assistant())->get('/crm/organisations')->assertInertia(fn (Assert $page) => $page->where('can.import', false));
});
