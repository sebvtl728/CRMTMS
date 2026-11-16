<?php

use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Import\FieldMap;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Import\ImportRow;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Import\ImportTemplate;
use App\Modules\Crm\Import\ImportUploads;
use App\Modules\Crm\Import\Readers\XlsxReader;
use App\Modules\Crm\Import\RunImport;
use App\Modules\Crm\Organisations\Organisation;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->user = commercial();
    $this->actingAs($this->user);
});

/** Downloads the template of $target to a temporary .xlsx and returns its path. */
function downloadedTemplate(ImportTarget $target): string
{
    $response = test()->get('/crm/import/template/'.$target->value);
    $response->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    return $path;
}

/**
 * Rows of each sheet of an .xlsx: name => list of rows (list of strings).
 *
 * @return array<string, list<list<string>>>
 */
function sheetsOf(string $path): array
{
    $reader = new Reader;
    $reader->open($path);
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $sheets[$sheet->getName()][] = array_map(fn ($c) => (string) $c->getValue(), $row->getCells());
        }
    }
    $reader->close();

    return $sheets;
}

it('serves a readable template for every importable target', function (ImportTarget $target) {
    $this->user->givePermissionTo('catalogue.manage');
    $path = downloadedTemplate($target);
    $reader = XlsxReader::open($path);
    $sheets = sheetsOf($path);

    expect($reader->headers())->toBe(array_values($target->fields()))
        ->and(array_keys($sheets))->toBe(['Données', 'Listes'])
        ->and($sheets['Données'][1][0])->toBe(ImportTemplate::EXAMPLE_MARKER)
        ->and(count($sheets['Données'][1]))->toBe(count($target->fields()))
        ->and(FieldMap::guess($target, $reader->headers())->toArray())
        ->toHaveCount(count($target->fields()));
})->with(fn () => ImportTarget::cases());

it('names the file after the target', function () {
    $this->get('/crm/import/template/people')->assertHeader('content-disposition', 'attachment; filename=modele-import-people.xlsx');
});

it('lists the active values of the organisme and nothing else', function () {
    $other = Tenant::factory()->create();
    app(TenantContext::class)->run($other, fn () => ReferenceItem::query()->create(['list' => ReferenceList::ContactOrigin, 'code' => 'autre-org', 'label' => 'Origine autre organisme', 'position' => 1]));
    ReferenceItem::query()->create(['list' => ReferenceList::ContactOrigin, 'code' => 'actif', 'label' => 'Origine active', 'position' => 90]);
    ReferenceItem::query()->create(['list' => ReferenceList::ContactOrigin, 'code' => 'inactif', 'label' => 'Origine désactivée', 'position' => 91, 'is_active' => false]);

    $lists = sheetsOf(downloadedTemplate(ImportTarget::Organisations))['Listes'];
    $column = array_search('Origine', $lists[0], true);
    $values = array_column($lists, $column);

    expect($column)->not->toBeFalse()
        ->and($values)->toContain('Origine active')
        ->and($values)->not->toContain('Origine désactivée')
        ->and($values)->not->toContain('Origine autre organisme');
});

it('refuses the template without the import right, for an unknown target and for the catalogue without its right', function () {
    $this->get('/crm/import/template/nimportequoi')->assertNotFound();
    $this->get('/crm/import/template/trainings')->assertNotFound();

    $this->actingAs(userWithPermissions(['crm.fiches.view']));
    $this->get('/crm/import/template/organisations')->assertForbidden();
});

it('ignores the example row left in a filled template', function () {
    $path = tempnam(sys_get_temp_dir(), 'fill').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(array_values(ImportTarget::Organisations->fields())));
    $example = sheetsOf(downloadedTemplate(ImportTarget::Organisations))['Données'][1];
    $writer->addRow(Row::fromValues($example));
    $values = array_fill(0, count($example), '');
    $writer->addRow(Row::fromValues(['Garage Dupont', ...array_slice($values, 1)]));
    $writer->addRow(Row::fromValues(['Café Lumière', ...array_slice($values, 1)]));
    $writer->close();

    $batch = app(ImportUploads::class)->store($this->user, $path, 'modele.xlsx', ImportTarget::Organisations, ['source_system' => 'Formevol']);
    simulateImport($batch);
    RunImport::dispatch($batch->id);
    $batch->refresh();

    expect($batch->status)->toBe(ImportBatch::DONE)
        ->and(Organisation::query()->orderBy('name')->pluck('name')->all())->toBe(['Café Lumière', 'Garage Dupont'])
        ->and($batch->skipped)->toBe(1)
        ->and($batch->rows()->where('status', ImportRow::SKIPPED)->sole()->messages[0]['text'])->toBe("Ligne d'exemple du modèle ignorée.");
});

it('leaves every identifier blank in the example row', function (ImportTarget $target) {
    [$headers, $example] = sheetsOf(downloadedTemplate($target))['Données'];
    $cells = array_combine($headers, array_pad($example, count($headers), ''));

    foreach ($target->identifierFields() as $field) {
        expect($cells[$target->fields()[$field]])->toBe('');
    }
})->with([ImportTarget::Organisations, ImportTarget::People, ImportTarget::Affiliations, ImportTarget::Notes]);

it('asks no source system for a template kept with its example row and empty identifiers', function () {
    $marker = ImportTemplate::EXAMPLE_MARKER;
    // A template of an earlier version: its example row filled the identifier.
    $batch = uploadImport("Raison sociale;Identifiant source\n{$marker};EXEMPLE-1\nGarage Martin;\nCafé des Arts;\n", ImportTarget::Organisations);

    expect($batch->identifiersFilled(FieldMap::fromArray(ImportTarget::Organisations, ['Raison sociale' => 'name', 'Identifiant source' => 'source_id'])))->toBeFalse();
    $this->put("/crm/import/{$batch->id}/mapping", ['map' => ['Raison sociale' => 'name', 'Identifiant source' => 'source_id']])->assertSessionHasNoErrors();

    $report = simulateImport($batch, ['Identifiant source' => 'source_id']);
    expect($report->blocking)->toBe([]);
    RunImport::dispatch($batch->id);

    expect($batch->refresh()->status)->toBe(ImportBatch::DONE)
        ->and(Organisation::query()->orderBy('name')->pluck('name')->all())->toBe(['Café des Arts', 'Garage Martin']);
});

it('recognises the example row whatever the column of its marker', function () {
    expect(ImportTemplate::isExample(['Raison sociale' => 'Garage', 'Ville' => ImportTemplate::EXAMPLE_MARKER]))->toBeTrue()
        ->and(ImportTemplate::isExample(['Raison sociale' => ' '.ImportTemplate::EXAMPLE_MARKER.' ']))->toBeTrue()
        ->and(ImportTemplate::isExample(['Raison sociale' => 'Garage', 'Ville' => 'Paris']))->toBeFalse()
        ->and(ImportTemplate::isExample([]))->toBeFalse();
});
