<?php

use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Training;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Export\OrganisationExport;
use App\Modules\Crm\Export\PersonExport;
use App\Modules\Crm\Gdpr\LegalBasis;
use App\Modules\Crm\Import\ColumnGuesser;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Interests\FicheTrainings;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->actingAs(admin(twoFactor: true));
    $this->haccp = Training::query()->create(['title' => 'HACCP 3 jours', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id]);
});

/**
 * A « ; » CSV whose header is every column of an export, in its order.
 *
 * @param  array<string, string>  $columns  export column => header
 * @param  list<array<string, string>>  $rows  header => value
 */
function exportShapedCsv(array $columns, array $rows): string
{
    $headers = array_values($columns);
    $lines = [implode(';', $headers)];
    foreach ($rows as $row) {
        $lines[] = implode(';', array_map(fn (string $h) => '"'.str_replace('"', '""', $row[$h] ?? '').'"', $headers));
    }

    return implode("\n", $lines)."\n";
}

it('recognises every importable column of the entreprises export', function () {
    $headers = array_values((new OrganisationExport)->columns());
    $guess = ColumnGuesser::guess(ImportTarget::Organisations, $headers);

    // Computed by the TMS, never imported.
    $unmapped = array_values(array_diff($headers, array_keys($guess)));
    expect($unmapped)->toEqualCanonicalizing(['Dernier contact', 'Création'])
        ->and($guess['Nom']['field'])->toBe('name')
        ->and($guess['Types']['field'])->toBe('types')
        ->and($guess["Formations d'intérêt"]['field'])->toBe('interest_trainings');
});

it('recognises every importable column of the people export', function () {
    $headers = array_values((new PersonExport)->columns());
    $guess = ColumnGuesser::guess(ImportTarget::People, $headers);

    $unmapped = array_values(array_diff($headers, array_keys($guess)));
    expect($unmapped)->toEqualCanonicalizing(['Dernier contact', 'Création', 'Système source'])
        ->and($guess['Type']['field'])->toBe('is_individual')
        ->and($guess['Entreprises']['field'])->toBe('organisation.name')
        ->and($guess['Fonction']['field'])->toBe('job_title')
        ->and($guess['E-mail professionnel']['field'])->toBe('work_email')
        ->and($guess['Téléphone professionnel']['field'])->toBe('work_phone')
        ->and($guess['Base légale']['field'])->toBe('legal_basis')
        ->and($guess["Formations d'intérêt"]['field'])->toBe('interest_trainings');
});

it('reimports an entreprises export with its roles and its trainings of interest', function () {
    $csv = exportShapedCsv((new OrganisationExport)->columns(), [[
        'Nom' => 'Hôtel du Lac', 'Statut' => 'Client', 'DAS' => 'Hôtellerie-restauration',
        'Types' => 'Entreprise cliente, Co-traitant', "Formations d'intérêt" => 'HACCP 3 jours ; Formation inconnue',
        'Ville' => 'Annecy', 'Dernier contact' => '12/11/2026', 'Création' => '01/10/2026',
    ]]);

    $batch = runImport($csv, ImportTarget::Organisations);

    expect([$batch->created, $batch->errors])->toBe([1, 0]);
    $org = Organisation::query()->where('name', 'Hôtel du Lac')->sole();
    expect($org->status->value)->toBe('client')
        ->and($org->types()->pluck('label')->sort()->values()->all())->toBe(['Co-traitant', 'Entreprise cliente'])
        ->and(FicheTrainings::ids($org))->toBe([$this->haccp->id])
        ->and(implode(' ', rowMessages($batch, 2)))->toContain('Formation inconnue');
});

it('reimports a people export: particulier or interlocuteur, entreprise and function, legal basis, interests', function () {
    $lac = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $csv = exportShapedCsv((new PersonExport)->columns(), [
        ['Civilité' => 'Mme', 'Nom' => 'Durand', 'Prénom' => 'Léa', 'Type' => 'Interlocuteur', 'Entreprises' => 'Hôtel du Lac',
            'Fonction' => 'Gérante', 'E-mail professionnel' => 'lea@hotel-lac.fr', 'Base légale' => 'Consentement',
            "Formations d'intérêt" => 'HACCP 3 jours'],
        ['Nom' => 'Martin', 'Prénom' => 'Paul', 'Type' => 'Particulier', 'E-mail' => 'paul@example.test'],
        ['Nom' => 'Petit', 'Prénom' => 'Ana', 'Type' => 'Interlocuteur', 'Entreprises' => 'Hôtel du Lac ; Café des Arts'],
    ]);

    $batch = runImport($csv, ImportTarget::People);

    expect($batch->errors)->toBe(0);
    $lea = Person::query()->where('last_name', 'Durand')->sole();
    $link = Affiliation::query()->where('person_id', $lea->id)->sole();
    expect($lea->is_individual)->toBeFalse()
        ->and($lea->legal_basis)->toBe(LegalBasis::Consent)
        ->and(FicheTrainings::ids($lea))->toBe([$this->haccp->id])
        ->and($link->organisation_id)->toBe($lac->id)
        ->and($link->job_title)->toBe('Gérante')
        ->and($link->work_email)->toBe('lea@hotel-lac.fr');

    $paul = Person::query()->where('last_name', 'Martin')->sole();
    expect($paul->is_individual)->toBeTrue()->and(Affiliation::query()->where('person_id', $paul->id)->exists())->toBeFalse();

    // Several entreprises: the first is linked, the others named in a warning.
    $ana = Person::query()->where('last_name', 'Petit')->sole();
    expect(Affiliation::query()->where('person_id', $ana->id)->pluck('organisation_id')->all())->toBe([$lac->id])
        ->and(implode(' ', rowMessages($batch, 4)))->toContain('Café des Arts');
});
