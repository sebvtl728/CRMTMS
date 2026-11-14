<?php

use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\TrainingUsage;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Import\FieldMap;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Import\ImportRow;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Import\RunImport;
use App\Modules\Crm\Import\UndoImport;
use App\Modules\Crm\Import\UndoPreview;
use App\Modules\Crm\Import\ValueMapper;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->user = admin();
    $this->actingAs($this->user);
});

afterEach(fn () => TrainingUsage::flush());

const TRAININGS_HEADER = "Code;Intitulé;DAS;Durée (heures);Durée (jours);Public;Prérequis;Tarif entreprise;Tarif particulier;Financements;Active\n";

/** The catalogue file as Excel saves it in France: « ; » and Windows-1252. */
function trainingsCsv(string $rows): string
{
    return mb_convert_encoding(TRAININGS_HEADER.$rows, 'Windows-1252', 'UTF-8');
}

function trainingByCode(string $code): Training
{
    return Training::query()->where('code', $code)->sole();
}

it('imports a windows-1252 catalogue: french amounts and durations, funding modes, active flag', function () {
    $batch = runImport(trainingsCsv(
        "haccp-3j;HACCP 3 jours;Hôtellerie-restauration;21;3;Équipes de cuisine;Aucun;1 200,00;990;\"CPF; OPCO\";Oui\n"
        ."SST;Sauveteur secouriste du travail;Compétences transversales;14;2;Tout salarié;;1 200 €;;OPCO;\n"
        ."EXCEL;Excel perfectionnement;Métiers supports;7,5;1;;Bases d'Excel;1200.5;450,5;CPF,Entreprise;Non\n"
    ), ImportTarget::Trainings);

    expect([$batch->status, $batch->created, $batch->updated, $batch->errors])->toBe([ImportBatch::DONE, 3, 0, 0])
        ->and($batch->fieldMap()->toArray())->toEqual([
            'Code' => 'code', 'Intitulé' => 'title', 'DAS' => 'das', 'Durée (heures)' => 'duration_hours', 'Durée (jours)' => 'duration_days',
            'Public' => 'audience', 'Prérequis' => 'prerequisites', 'Tarif entreprise' => 'price_company', 'Tarif particulier' => 'price_individual',
            'Financements' => 'funding_modes', 'Active' => 'is_active',
        ]);

    $haccp = trainingByCode('HACCP-3J');
    expect($haccp->title)->toBe('HACCP 3 jours')
        ->and($haccp->das_id)->toBe(crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id)
        ->and([$haccp->duration_hours, $haccp->duration_days])->toBe(['21.00', '3.00'])
        ->and([$haccp->audience, $haccp->prerequisites])->toBe(['Équipes de cuisine', 'Aucun'])
        ->and([$haccp->price_company, $haccp->price_individual])->toBe(['1200.00', '990.00'])
        ->and($haccp->fundingModes()->pluck('label')->sort()->values()->all())->toBe(['CPF', 'OPCO'])
        ->and($haccp->is_active)->toBeTrue()
        ->and($haccp->created_by)->toBe($this->user->id);

    $sst = trainingByCode('SST');
    expect($sst->price_company)->toBe('1200.00')
        ->and($sst->price_individual)->toBeNull()
        ->and($sst->is_active)->toBeTrue();

    $excel = trainingByCode('EXCEL');
    expect([$excel->duration_hours, $excel->price_company, $excel->price_individual])->toBe(['7.50', '1200.50', '450.50'])
        ->and($excel->fundingModes()->pluck('label')->sort()->values()->all())->toBe(['CPF', 'Entreprise'])
        ->and($excel->is_active)->toBeFalse();

    $row = $batch->rows()->where('line', 2)->sole();
    expect([$row->status, $row->subject_type, $row->subject_id])->toBe([ImportRow::CREATED, 'training', $haccp->id]);
});

it('refuses a row whose das is unknown, and asks for a correspondence', function () {
    $batch = uploadImport(trainingsCsv(
        "HACCP;HACCP 3 jours;Hôtellerie-restauration;21;3;;;1 200,00;;;\n"
        ."ZEN;Sophrologie;Bien-être;7;1;;;300;;;\n"
    ), ImportTarget::Trainings);
    $report = simulateImport($batch);

    expect($report->summary()['to_create'])->toBe(1)
        ->and($report->summary()['errors'])->toBe(1)
        ->and($report->unknownValues)->toBe(['das' => ['Bien-être']])
        ->and($report->errorRows[0]['messages'][0])->toContain('Valeur « Bien-être » inconnue pour DAS');

    // Nothing is written by the simulation.
    expect(Training::query()->count())->toBe(0);
});

it('refuses wrong values with french messages', function (string $row, string $message) {
    $batch = runImport(trainingsCsv($row), ImportTarget::Trainings);

    expect($batch->errors)->toBe(1)
        ->and(implode(' ', rowMessages($batch, 2)))->toContain($message)
        ->and(Training::query()->count())->toBe(0);
})->with([
    'negative price' => ["A;Formation A;Métiers supports;;;;;-5;;;\n", 'Tarif entreprise : La valeur ne peut pas être négative.'],
    'text price' => ["A;Formation A;Métiers supports;;;;;gratuit;;;\n", 'Tarif entreprise : Indiquez un nombre'],
    'three decimals' => ["A;Formation A;Métiers supports;7,125;;;;;;;\n", 'Durée (heures) : Indiquez au plus deux décimales.'],
    'no title' => ["A;;Métiers supports;;;;;;;;\n", 'Intitulé : Ce champ est obligatoire.'],
    'no das' => ["A;Formation A;;;;;;;;;\n", 'DAS : Ce champ est obligatoire.'],
    'two das' => ["A;Formation A;Métiers supports, Hôtellerie-restauration;;;;;;;;\n", 'DAS : une seule valeur par formation.'],
    'active' => ["A;Formation A;Métiers supports;;;;;;;;peut-être\n", 'Active : répondez par Oui ou Non.'],
    'long code' => ["ABCDEFGHIJKLMNOPQRSTUVWXYZ;Formation A;Métiers supports;;;;;;;;\n", 'Code : Le code ne doit pas dépasser 20 caractères.'],
]);

it('maps funding modes through the value correspondence step', function () {
    $batch = uploadImport(trainingsCsv("HACCP;HACCP;Hôtellerie-restauration;;;;;;;\"CPF; Plan de développement ; Pôle emploi\";\n"), ImportTarget::Trainings);
    $report = simulateImport($batch);
    expect($report->unknownValues)->toBe(['funding_modes' => ['Plan de développement', 'Pôle emploi']]);

    $batch = runImport(trainingsCsv("HACCP;HACCP;Hôtellerie-restauration;;;;;;;\"CPF; Plan de développement ; Pôle emploi\";\n"), ImportTarget::Trainings, values: [
        'funding_modes' => ['Plan de développement' => ValueMapper::CREATE, 'Pôle emploi' => crmItem(ReferenceList::FundingMode, 'France Travail')->id],
    ]);

    expect($batch->created)->toBe(1)
        ->and(trainingByCode('HACCP')->fundingModes()->pluck('label')->sort()->values()->all())->toBe(['CPF', 'France Travail', 'Plan de développement'])
        ->and(ReferenceItem::query()->where('list', ReferenceList::FundingMode)->where('label', 'Plan de développement')->exists())->toBeTrue();
});

it('updates the training of the same code on a second import', function () {
    runImport(trainingsCsv("HACCP;HACCP 3 jours;Hôtellerie-restauration;21;3;Cuisiniers;;1 200,00;;CPF;\n"), ImportTarget::Trainings);
    $haccp = trainingByCode('HACCP');

    $this->travel(1)->minutes();
    $batch = runImport(trainingsCsv("haccp;HACCP (3 jours);Hôtellerie-restauration;;;;Aucun;1 350,00;990;OPCO;\n"), ImportTarget::Trainings, ['strategy' => 'merge']);

    $haccp->refresh();
    expect([$batch->created, $batch->updated, $batch->errors])->toBe([0, 1, 0])
        ->and(Training::query()->count())->toBe(1)
        ->and($haccp->title)->toBe('HACCP (3 jours)')
        // Non-empty file values win, an empty cell never erases a value.
        ->and([$haccp->price_company, $haccp->price_individual, $haccp->duration_hours])->toBe(['1350.00', '990.00', '21.00'])
        ->and([$haccp->audience, $haccp->prerequisites])->toBe(['Cuisiniers', 'Aucun'])
        ->and($haccp->fundingModes()->pluck('label')->all())->toBe(['OPCO']);
});

it('applies the duplicate strategy to a code already in the catalogue', function (string $strategy, string $status, ?string $message) {
    runImport(trainingsCsv("HACCP;HACCP 3 jours;Hôtellerie-restauration;;;;;1 200,00;;;\n"), ImportTarget::Trainings);

    $batch = runImport(trainingsCsv("HACCP;Autre intitulé;Hôtellerie-restauration;;;;;900;;;\n"), ImportTarget::Trainings, ['strategy' => $strategy]);

    $row = $batch->rows()->where('line', 2)->sole();
    expect($row->status)->toBe($status)
        ->and(Training::query()->count())->toBe(1)
        ->and(trainingByCode('HACCP')->price_company)->toBe('1200.00');
    if ($message !== null) {
        expect(implode(' ', rowMessages($batch, 2)))->toContain($message);
    }
})->with([
    'skip' => ['skip', ImportRow::SKIPPED, 'Une formation a déjà ce code'],
    'create' => ['create', ImportRow::ERROR, 'Ce code est déjà utilisé par une autre formation.'],
]);

it('predicts the outcome of rows sharing a code in the same file', function () {
    $batch = uploadImport(trainingsCsv(
        "HACCP;HACCP 3 jours;Hôtellerie-restauration;;;;;;;;\n"
        ."HACCP;HACCP 3 jours (nouveau tarif);Hôtellerie-restauration;;;;;900;;;\n"
    ), ImportTarget::Trainings, ['strategy' => 'merge']);

    $report = simulateImport($batch);

    expect([$report->summary()['to_create'], $report->summary()['to_update']])->toBe([1, 1]);

    simulateImport($batch);
    RunImport::dispatch($batch->id);
    expect([$batch->refresh()->created, $batch->updated])->toBe([1, 1])
        ->and(trainingByCode('HACCP')->price_company)->toBe('900.00');
});

it('finds a training by its source identifier first, even when its code changed', function () {
    $csv = "Identifiant;Code;Intitulé;DAS;Tarif entreprise\nF-12;HACCP;HACCP 3 jours;Hôtellerie-restauration;1 200,00\n";
    $first = runImport($csv, ImportTarget::Trainings, ['source_system' => 'Formevol']);
    $haccp = trainingByCode('HACCP');
    expect($first->fieldMap()->toArray()['Identifiant'])->toBe('source_id')
        ->and([$haccp->source_system, $haccp->source_id])->toBe(['Formevol', 'F-12']);

    $this->travel(1)->minutes();
    $batch = runImport("Identifiant;Code;Intitulé;DAS;Tarif entreprise\nF-12;HACCP-2027;HACCP 3 jours;Hôtellerie-restauration;1 300,00\n", ImportTarget::Trainings, ['source_system' => 'Formevol', 'strategy' => 'skip']);

    expect([$batch->created, $batch->updated])->toBe([0, 1])
        ->and($haccp->refresh()->code)->toBe('HACCP-2027')
        ->and($haccp->price_company)->toBe('1300.00');
});

it('undoes an import except a training used or modified since', function () {
    $batch = runImport(trainingsCsv(
        "HACCP;HACCP 3 jours;Hôtellerie-restauration;;;;;;;CPF;\n"
        ."SST;Sauveteur secouriste;Compétences transversales;;;;;;;;\n"
        ."EXCEL;Excel;Métiers supports;;;;;;;;\n"
    ), ImportTarget::Trainings);
    expect($batch->created)->toBe(3);

    // Since the import: SST is used (an opportunity, in the next tasks), EXCEL edited.
    $sst = trainingByCode('SST');
    TrainingUsage::register('test.opportunities', fn (string $id) => $id === $sst->id);
    $this->travel(5)->minutes();
    trainingByCode('EXCEL')->update(['title' => 'Excel perfectionnement']);

    // The preview matches the undo: SST (used) and EXCEL (edited) are kept.
    expect(UndoPreview::for($batch)['removable'])->toBe(1)
        ->and(UndoPreview::for($batch)['keptModified'])->toBe(2)
        ->and(UndoPreview::for($batch)['byType'])->toBe(['training' => 1]);

    UndoImport::dispatch($batch->id, $this->user->id);

    expect($batch->refresh()->status)->toBe(ImportBatch::UNDONE)
        ->and(Training::query()->orderBy('code')->pluck('code')->all())->toBe(['EXCEL', 'SST'])
        ->and(Training::onlyTrashed()->pluck('code')->all())->toBe(['HACCP'])
        ->and(rowMessages($batch, 3))->toBe([UndoImport::KEPT_USED])
        ->and(rowMessages($batch, 4))->toBe([UndoImport::KEPT_MODIFIED])
        ->and($batch->options['undo'])->toEqualCanonicalizing(['removed' => 1, 'kept' => 2]);

    // The code is free again: a new import creates the training anew.
    $again = runImport(trainingsCsv("HACCP;HACCP 3 jours;Hôtellerie-restauration;;;;;;;;\n"), ImportTarget::Trainings);
    expect($again->created)->toBe(1);
});

it('proposes the catalogue target only to the users who manage the catalogue', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->get('/crm/import/new')->assertInertia(fn (Assert $page) => $page
        ->has('targets', 5)
        ->where('targets.4', ['value' => 'trainings', 'label' => 'Catalogue de formations']));

    $this->actingAs(commercial());
    $this->get('/crm/import/new')->assertInertia(fn (Assert $page) => $page->has('targets', 4));
    $this->post('/crm/import', ['file' => UploadedFile::fake()->createWithContent('catalogue.csv', trainingsCsv('')), 'target' => 'trainings'])
        ->assertSessionHasErrors(['target' => 'Seuls les gestionnaires du catalogue peuvent importer des formations.']);
    expect(ImportBatch::query()->count())->toBe(0);
});

it('describes the duplicate strategies in catalogue terms', function () {
    $batch = uploadImport(trainingsCsv("HACCP;HACCP 3 jours;Hôtellerie-restauration;;;;;;;;\n"), ImportTarget::Trainings);
    simulateImport($batch);
    $this->actingAs(admin(twoFactor: true));
    $batch->forceFill(['user_id' => auth()->id()])->save();

    $this->get("/crm/import/{$batch->id}")->assertInertia(fn (Assert $page) => $page
        ->component('crm/import/simulation')
        ->where('strategy.options.1.label', 'Mettre à jour la formation existante')
        ->where('strategy.options.1.description', fn (string $d) => str_contains($d, 'même code')
            && str_contains($d, 'une cellule « Financements » non vide remplace les financements enregistrés')));
});

it('guesses the usual headers of a catalogue file', function () {
    $map = FieldMap::guess(ImportTarget::Trainings, ['Référence', 'Libellé', 'Domaine', 'Nombre d\'heures', 'Nombre de jours', 'Public visé', 'Pré-requis', 'Prix entreprise', 'Prix particulier', 'Modes de financement', 'Actif']);

    expect($map->toArray())->toBe([
        'Référence' => 'code', 'Libellé' => 'title', 'Domaine' => 'das', 'Nombre d\'heures' => 'duration_hours', 'Nombre de jours' => 'duration_days',
        'Public visé' => 'audience', 'Pré-requis' => 'prerequisites', 'Prix entreprise' => 'price_company', 'Prix particulier' => 'price_individual',
        'Modes de financement' => 'funding_modes', 'Actif' => 'is_active',
    ]);
});
