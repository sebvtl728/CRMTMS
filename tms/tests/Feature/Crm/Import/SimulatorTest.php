<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Import\FieldMap;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Import\ImportRow;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Import\ValueMapper;
use App\Modules\Crm\Import\Values;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->actingAs(commercial());
});

it('proposes the column mapping from the headers', function (ImportTarget $target, array $headers, array $expected) {
    expect(FieldMap::guess($target, $headers)->toArray())->toBe($expected);
})->with([
    'entreprises' => [ImportTarget::Organisations, ['Raison sociale', 'SIRET', 'CP', 'Ville', 'Tél', 'Mail', 'Origine', 'Colonne inconnue'],
        ['Raison sociale' => 'name', 'SIRET' => 'siret', 'CP' => 'postal_code', 'Ville' => 'city', 'Tél' => 'phone', 'Mail' => 'email', 'Origine' => 'origin']],
    'accents, case and spacing' => [ImportTarget::Organisations, [' RAISON  SOCIALE ', 'Code Postal', 'Téléphone', 'Courriel', 'N° SIRET'],
        [' RAISON  SOCIALE ' => 'name', 'Code Postal' => 'postal_code', 'Téléphone' => 'phone', 'Courriel' => 'email', 'N° SIRET' => 'siret']],
    'personnes' => [ImportTarget::People, ['Nom', 'Prénom', 'E-mail', 'Portable', 'Ville'],
        ['Nom' => 'last_name', 'Prénom' => 'first_name', 'E-mail' => 'email', 'Portable' => 'mobile', 'Ville' => 'city']],
    'interlocuteurs' => [ImportTarget::Affiliations, ['Nom', 'Prénom', 'E-mail', 'Fonction', 'Entreprise', 'SIRET', 'Ville', 'Depuis'],
        ['Nom' => 'last_name', 'Prénom' => 'first_name', 'E-mail' => 'email', 'Fonction' => 'job_title', 'Entreprise' => 'organisation.name', 'SIRET' => 'organisation.siret', 'Ville' => 'city', 'Depuis' => 'started_on']],
    'notes' => [ImportTarget::Notes, ['SIRET', 'Date', 'Compte rendu'], ['SIRET' => 'subject_siret', 'Date' => 'date', 'Compte rendu' => 'body']],
    // A field is proposed once, for its first column.
    'repeated meaning' => [ImportTarget::Organisations, ['Téléphone', 'Tél fixe'], ['Téléphone' => 'phone']],
]);

it('lists the target fields with french labels', function () {
    expect(ImportTarget::Organisations->fields())->toMatchArray(['name' => 'Raison sociale', 'postal_code' => 'Code postal', 'source_id' => 'Identifiant source'])
        ->and(ImportTarget::Affiliations->fields())->toMatchArray(['organisation.name' => 'Entreprise : raison sociale', 'job_title' => 'Fonction'])
        ->and(ImportTarget::Notes->fields())->toHaveKeys(['subject_siret', 'subject_email', 'subject_source_id', 'date', 'body']);
});

it('refuses a mapping to an unknown field or to the same field twice', function (array $map, string $message) {
    expect(fn () => FieldMap::fromArray(ImportTarget::Organisations, $map))->toThrow(InvalidArgumentException::class, $message);
})->with([
    [['A' => 'password'], 'Champ inconnu pour la colonne « A »'],
    [['A' => 'name', 'B' => 'name'], 'Le champ « Raison sociale » est associé à plusieurs colonnes'],
]);

it('simulates without writing anything', function () {
    $batch = uploadImport('utf8_semicolon.csv', ImportTarget::Organisations);
    $before = [Organisation::withTrashed()->count(), Person::withTrashed()->count(), ReferenceItem::withTrashed()->count(), AuditLog::query()->count(), ImportRow::query()->count()];

    $report = simulateImport($batch);

    expect([Organisation::withTrashed()->count(), Person::withTrashed()->count(), ReferenceItem::withTrashed()->count(), AuditLog::query()->count(), ImportRow::query()->count()])->toBe($before)
        ->and($report->total)->toBe(3)
        ->and($report->toCreate)->toBe(3)
        ->and($report->errors)->toBe(0)
        ->and($report->canRun())->toBeTrue()
        ->and($report->warnings)->toBe(['Fichier lu en UTF-8, séparateur « ; ».']);

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatch::SIMULATED)
        ->and($batch->field_map)->toMatchArray(['Raison sociale' => 'name', 'Origine' => 'origin'])
        ->and($batch->simulation)->toMatchArray(['total' => 3, 'to_create' => 3, 'errors' => 0]);
});

it('reports the errors of each row without stopping', function () {
    $batch = uploadImport(implode("\n", [
        'Raison sociale;SIRET;E-mail;Origine;Statut',
        ';;;;',
        ';73282932000074;;;',
        'Siret faux;73282932000075;;;',
        'Siret Excel;7,32829E+13;;;',
        'Mail faux;;pas-un-mail;;',
        'Origine inconnue;;;Salon pro;',
        'Statut inconnu;;;;Partenaire',
        'Correcte;;;Salon;Client',
    ]), ImportTarget::Organisations);

    $report = simulateImport($batch);
    $messages = collect($report->rows)->mapWithKeys(fn (array $r) => [$r['line'] => $r['messages']]);

    expect($report->total)->toBe(7)
        ->and($report->errors)->toBe(6)
        ->and($report->toCreate)->toBe(1)
        ->and($messages[3])->toBe(['Raison sociale : Ce champ est obligatoire.'])
        ->and($messages[4])->toBe(['SIRET : '.Values::SIRET_INVALID])
        ->and($messages[5][0])->toContain('SIRET altéré par Excel (notation scientifique)')
        ->and($messages[6])->toBe(['E-mail : L\'adresse e-mail n\'est pas valide.'])
        ->and($messages[7][0])->toContain('Valeur « Salon pro » inconnue pour Origine')
        ->and($messages[8][0])->toContain('Statut « Partenaire » inconnu')
        ->and($report->unknownValues)->toBe(['origin' => ['Salon pro'], 'status' => ['Partenaire']]);
});

it('accepts unknown values mapped to an item, to a creation or left empty', function () {
    $salon = crmItem(ReferenceList::ContactOrigin, 'Salon');
    $batch = uploadImport("Raison sociale;Origine;Statut\nA;Foire;Partenaire\nB;Salon pro;\nC;Inconnue;\n", ImportTarget::Organisations);

    $report = simulateImport($batch, values: [
        'origin' => ['foire' => $salon->id, 'Salon pro' => ValueMapper::CREATE, 'Inconnue' => ValueMapper::EMPTY],
        'status' => ['Partenaire' => 'client'],
    ]);

    expect($report->errors)->toBe(0)
        ->and($report->toCreate)->toBe(3)
        ->and(ReferenceItem::query()->where('label', 'Salon pro')->exists())->toBeFalse();
});

it('predicts the outcome of each duplicate strategy', function (string $strategy, string $status, string $message) {
    Organisation::query()->create(['name' => 'Hôtel du Lac', 'siret' => '73282932000074']);
    $batch = uploadImport("Raison sociale;SIRET;Ville\nHôtel du Lac;73282932000074;Annecy\n", ImportTarget::Organisations, ['strategy' => $strategy]);

    $report = simulateImport($batch);

    expect($report->rows[0]['status'])->toBe($status)
        ->and(implode(' ', $report->rows[0]['messages']))->toContain($message);
})->with([
    'skip' => ['skip', ImportRow::SKIPPED, 'doublon probable d\'une fiche existante (même siret) : ligne ignorée'],
    'merge' => ['merge', ImportRow::UPDATED, 'Fiche existante complétée'],
    'create' => ['create', ImportRow::ERROR, 'Une entreprise avec ce SIRET existe déjà'],
]);

it('detects duplicates inside the file', function () {
    $batch = uploadImport("Raison sociale;SIRET;E-mail\nA;73282932000074;\nA bis;73282932000074;\nB;;b@exemple.fr\nB bis;;B@Exemple.fr\n", ImportTarget::Organisations);

    $report = simulateImport($batch);

    expect($report->toCreate)->toBe(2)
        ->and($report->toSkip)->toBe(2)
        ->and($report->rows[0]['messages'][0])->toContain('doublon de la ligne 2 du fichier')
        ->and($report->rows[1]['messages'][0])->toContain('doublon de la ligne 4 du fichier');
});

it('blocks a file whose identifiers have no source system or whose required column is missing', function () {
    $batch = uploadImport("Identifiant;Ville\nC1;Lyon\n", ImportTarget::Organisations);

    $report = simulateImport($batch);

    expect($report->canRun())->toBeFalse()
        ->and($report->blocking)->toBe([
            'Associez une colonne au champ obligatoire « Raison sociale ».',
            'Indiquez le logiciel d\'origine des identifiants (ex. : Formevol) pour pouvoir réimporter sans doublon.',
        ])
        ->and($batch->refresh()->status)->toBe(ImportBatch::UPLOADED);
});

it('asks for a source system only when a source identifier is filled in the file', function () {
    $empty = uploadImport("Raison sociale;Identifiant source\nGarage Martin;\nCafé des Arts; \n", ImportTarget::Organisations);
    $filled = uploadImport("Raison sociale;Identifiant source\nGarage Martin;\nCafé des Arts;C2\n", ImportTarget::Organisations);

    $withoutValue = simulateImport($empty);
    $withValue = simulateImport($filled);

    expect($withoutValue->blocking)->toBe([])->and($withoutValue->canRun())->toBeTrue()
        ->and($withValue->blocking)->toBe(['Indiquez le logiciel d\'origine des identifiants (ex. : Formevol) pour pouvoir réimporter sans doublon.']);
});

it('does not ask for a source system on the columns step when the identifier column is empty', function () {
    $batch = uploadImport("Raison sociale;Identifiant source\nGarage Martin;\n", ImportTarget::Organisations);
    $filled = uploadImport("Raison sociale;Identifiant source\nGarage Martin;C1\n", ImportTarget::Organisations);
    $map = ['map' => ['Raison sociale' => 'name', 'Identifiant source' => 'source_id']];

    $this->put("/crm/import/{$batch->id}/mapping", $map)->assertSessionHasNoErrors();
    $this->put("/crm/import/{$filled->id}/mapping", $map)->assertSessionHasErrors('source_system');
});
