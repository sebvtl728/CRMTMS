<?php

use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Training;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/**
 * @return list<list<Cell>>
 */
function trainingXlsxCells(TestResponse $response): array
{
    $response->assertOk();
    // tempnam() creates its file: removed with the .xlsx copy.
    $temp = (string) tempnam(sys_get_temp_dir(), 'xlsx');
    $path = $temp.'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $reader = new Reader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->getCells();
        }
        break;
    }
    $reader->close();
    unlink($path);
    unlink($temp);

    return $rows;
}

/**
 * @return list<list<string>>
 */
function trainingXlsxRows(TestResponse $response): array
{
    return array_map(fn (array $cells) => array_map(fn (Cell $c) => (string) $c->getValue(), $cells), trainingXlsxCells($response));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function exportTraining(string $title, array $attributes = []): Training
{
    return Training::query()->create(['title' => $title, 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id, ...$attributes]);
}

it('exports the catalogue with french headers and typed cells', function () {
    $this->actingAs(commercial());
    $this->travelTo(CarbonImmutable::parse('2026-11-14 15:30', 'Europe/Paris'));
    $training = exportTraining('HACCP', ['code' => 'HACCP', 'duration_hours' => '14.5', 'duration_days' => '2', 'price_company' => '1250.5', 'is_active' => false]);
    $training->fundingModes()->sync([
        crmItem(ReferenceList::FundingMode, 'OPCO')->id => ['tenant_id' => tenant()->id],
        crmItem(ReferenceList::FundingMode, 'CPF')->id => ['tenant_id' => tenant()->id],
    ]);

    $response = $this->get('/catalogue/export');
    expect($response->headers->get('content-disposition'))->toContain('catalogue-20261114-1530.xlsx');
    $rows = trainingXlsxRows($response);
    expect($rows[0])->toBe(['Code', 'Intitulé', 'DAS', 'Durée (heures)', 'Durée (jours)', 'Tarif entreprise HT (€)', 'Tarif particulier HT (€)', 'Financements', 'Active'])
        ->and($rows[1])->toBe(['HACCP', 'HACCP', 'Hôtellerie-restauration', '14.5', '2', '1250.5', '', 'CPF, OPCO', 'Non']);

    $cells = trainingXlsxCells($this->get('/catalogue/export'))[1];
    expect($cells[0])->toBeInstanceOf(StringCell::class)
        ->and($cells[3])->toBeInstanceOf(NumericCell::class)->and($cells[3]->getValue())->toBe(14.5)
        ->and($cells[4])->toBeInstanceOf(NumericCell::class)
        ->and($cells[5])->toBeInstanceOf(NumericCell::class)->and($cells[5]->getValue())->toBe(1250.5)
        ->and($cells[6])->toBeInstanceOf(EmptyCell::class);

    // Optional columns, in the order asked; unknown ones refused.
    expect(trainingXlsxRows($this->get('/catalogue/export?columns[]=title&columns[]=audience')))->toBe([['Intitulé', 'Public visé'], ['HACCP', '']]);
    $this->get('/catalogue/export?columns[]=password')->assertSessionHasErrors('columns');

    $log = AuditLog::query()->where('event', 'export.run')->latest('created_at')->first();
    expect($log?->new_values)->toMatchArray(['export' => 'catalogue.trainings', 'rows' => 1]);
});

it('exports exactly the filtered list', function () {
    $cpf = crmItem(ReferenceList::FundingMode, 'CPF');
    $supports = crmItem(ReferenceList::Das, 'Métiers supports');
    exportTraining('Accueil', ['code' => 'C', 'price_company' => '300'])->fundingModes()->sync([$cpf->id => ['tenant_id' => tenant()->id]]);
    exportTraining('Bureautique', ['code' => 'A', 'das_id' => $supports->id, 'price_company' => '100']);
    exportTraining('Cuisine', ['code' => 'B', 'is_active' => false]);
    exportTraining('Supprimée')->delete();

    $this->actingAs(commercial());
    $list = fn (string $query) => collect($this->get('/catalogue?'.$query)->original->getData()['page']['props']['trainings']['data'])->pluck('title')->all();
    $export = fn (string $query) => collect(trainingXlsxRows($this->get('/catalogue/export?columns[]=title&'.$query)))->skip(1)->pluck(0)->values()->all();

    foreach (['', 'q=bur', 'das='.$supports->id, 'status=active', 'status=inactive', 'funding='.$cpf->id, 'sort=code', 'sort=price_company&dir=desc'] as $query) {
        expect($export($query))->toBe($list($query));
    }
    expect($export('sort=price_company&dir=desc'))->toBe(['Accueil', 'Bureautique', 'Cuisine']);
});

it('needs crm.export', function () {
    exportTraining('HACCP');
    $this->actingAs(assistant())->get('/catalogue/export')->assertForbidden();
    expect(AuditLog::query()->where('event', 'export.run')->count())->toBe(0);
});
