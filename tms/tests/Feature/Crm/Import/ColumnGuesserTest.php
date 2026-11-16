<?php

use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Import\ColumnGuesser;
use App\Modules\Crm\Import\FieldMap;
use App\Modules\Crm\Import\HeaderChoice;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Import\ImportProfile;
use App\Modules\Crm\Import\ImportTarget;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->user = commercial();
    $this->actingAs($this->user);
});

/**
 * Sample rows: one array per line, column => value.
 *
 * @param  array<string, list<string>>  $columns
 * @return list<array<string, string>>
 */
function sampleRows(array $columns): array
{
    $count = max(array_map('count', $columns));
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = array_map(fn (array $values) => $values[$i] ?? '', $columns);
    }

    return $rows;
}

/** @return list<string> */
function emails(int $count): array
{
    return array_map(fn (int $i) => "contact{$i}@example.test", range(1, $count));
}

it('keeps today\'s exact matches', function () {
    expect(ColumnGuesser::guess(ImportTarget::People, ['Nom', 'E-mail'], []))->toBe([
        'Nom' => ['field' => 'last_name', 'reason' => 'header'],
        'E-mail' => ['field' => 'email', 'reason' => 'header'],
    ]);
});

it('matches a header containing a known synonym', function () {
    expect(ColumnGuesser::guess(ImportTarget::People, ['Tél portable contact', 'CP'], []))->toBe([
        'Tél portable contact' => ['field' => 'mobile', 'reason' => 'similar'],
        'CP' => ['field' => 'postal_code', 'reason' => 'header'],
    ])
        ->and(ColumnGuesser::guess(ImportTarget::Organisations, ['Raison sociale client', 'Port.', 'E-mail entreprise'], []))->toBe([
            // « E-mail entreprise » is as close to « E-mail » as to « Entreprise »: ignored.
            'Raison sociale client' => ['field' => 'name', 'reason' => 'similar'],
        ])
        ->and(ColumnGuesser::guess(ImportTarget::Affiliations, ['E-mail entreprise', 'Téléphone de l\'entreprise'], []))->toBe([
            'E-mail entreprise' => ['field' => 'organisation.email', 'reason' => 'similar'],
            'Téléphone de l\'entreprise' => ['field' => 'organisation.phone', 'reason' => 'similar'],
        ]);
});

it('guesses from the content when the header says nothing', function () {
    $mixed = [...emails(7), 'à rappeler', 'voir Paul', 'inconnu'];

    expect(ColumnGuesser::guess(ImportTarget::People, ['Col 1', 'Col 3'], sampleRows(['Col 1' => array_fill(0, 10, 'x'), 'Col 3' => emails(10)])))
        ->toBe(['Col 3' => ['field' => 'email', 'reason' => 'content']])
        // 70 % of e-mails: no proposal.
        ->and(ColumnGuesser::guess(ImportTarget::People, ['Col 3'], sampleRows(['Col 3' => $mixed])))->toBe([])
        // Empty cells do not count: 8 e-mails out of 8 filled cells.
        ->and(ColumnGuesser::guess(ImportTarget::People, ['Col 3'], sampleRows(['Col 3' => [...emails(8), '', '', '']])))
        ->toBe(['Col 3' => ['field' => 'email', 'reason' => 'content']])
        ->and(ColumnGuesser::guess(ImportTarget::People, ['A', 'B', 'C', 'D'], sampleRows([
            'A' => ['06 12 34 56 78', '07 98 76 54 32', '+33 6 11 22 33 44'],
            'B' => ['75011', '69002', '13001'],
            'C' => ['M.', 'Mme', 'Madame'],
            'D' => ['16/11/1980', '01/02/1975', '1990-05-04'],
        ])))->toBe([
            // A column of dates is never taken for birth dates.
            'A' => ['field' => 'mobile', 'reason' => 'content'],
            'B' => ['field' => 'postal_code', 'reason' => 'content'],
            'C' => ['field' => 'civility', 'reason' => 'content'],
        ])
        // Fewer than 3 filled cells: too few to judge.
        ->and(ColumnGuesser::guess(ImportTarget::People, ['Col 3'], sampleRows(['Col 3' => [...emails(2), '', '']])))->toBe([])
        // Excel dropped the leading 0 of the mobiles.
        ->and(ColumnGuesser::guess(ImportTarget::People, ['Col 3'], sampleRows(['Col 3' => ['612345679', '798765432', '611223344']])))
        ->toBe(['Col 3' => ['field' => 'mobile', 'reason' => 'content']])
        // A shape without a matching field for the target: nothing.
        ->and(ColumnGuesser::guess(ImportTarget::Trainings, ['Col 3'], sampleRows(['Col 3' => emails(10)])))->toBe([]);
});

it('validates SIRET with the Luhn key', function () {
    $valid = array_fill(0, 5, '73282932000074');
    $invalid = array_fill(0, 5, '73282932000075');

    expect(ColumnGuesser::guess(ImportTarget::Organisations, ['N'], sampleRows(['N' => $valid])))->toBe(['N' => ['field' => 'siret', 'reason' => 'content']])
        ->and(ColumnGuesser::guess(ImportTarget::Organisations, ['N'], sampleRows(['N' => $invalid])))->toBe([]);
});

it('leaves an ambiguous column on Ignore', function () {
    // « Code » is close to « Code NAF », « Code postal »... and « Nom du
    // responsable » to « Nom » and « Responsable » for a person.
    expect(ColumnGuesser::guess(ImportTarget::People, ['Nom du responsable'], []))->toBe([])
        ->and(ColumnGuesser::guess(ImportTarget::Affiliations, ['Date'], []))->toBe([])
        ->and(ColumnGuesser::guess(ImportTarget::Organisations, ['Nom du responsable'], []))->toBe(['Nom du responsable' => ['field' => 'responsible', 'reason' => 'similar']]);
});

it('proposes a birth date only from a clear header', function () {
    expect(ColumnGuesser::guess(ImportTarget::People, ['Date', 'Date de création', 'Dernier contact'], sampleRows([
        'Date' => ['16/11/1980', '01/02/1975', '04/05/1990'],
        'Date de création' => ['16/11/2026', '15/11/2026', '14/11/2026'],
        'Dernier contact' => ['16/11/2026', '15/11/2026', '14/11/2026'],
    ])))->toBe([])
        ->and(ColumnGuesser::guess(ImportTarget::People, ['Date de naissance'], []))->toBe(['Date de naissance' => ['field' => 'birth_date', 'reason' => 'header']])
        ->and(ColumnGuesser::guess(ImportTarget::People, ['Né le'], []))->toBe(['Né le' => ['field' => 'birth_date', 'reason' => 'header']])
        ->and(ColumnGuesser::guess(ImportTarget::People, ['Naissance'], []))->toBe(['Naissance' => ['field' => 'birth_date', 'reason' => 'header']])
        ->and(ColumnGuesser::guess(ImportTarget::People, ['Date naiss.'], []))->toBe(['Date naiss.' => ['field' => 'birth_date', 'reason' => 'similar']]);
});

it('proposes each field once, to the surest column', function () {
    $rows = sampleRows(['Courriel perso' => emails(5), 'Col 3' => emails(5), 'Mail' => emails(5)]);

    // Exact header beats close header beats content, whatever the order of the columns.
    expect(ColumnGuesser::guess(ImportTarget::People, ['Col 3', 'Courriel perso', 'Mail'], $rows))->toBe(['Mail' => ['field' => 'email', 'reason' => 'header']])
        ->and(ColumnGuesser::guess(ImportTarget::People, ['Col 3', 'Courriel perso'], $rows))->toBe(['Courriel perso' => ['field' => 'email', 'reason' => 'similar']])
        // Same level: the first column, as before.
        ->and(FieldMap::guess(ImportTarget::Organisations, ['Téléphone', 'Tél fixe'])->toArray())->toBe(['Téléphone' => 'phone']);
});

it('remembers the organisme\'s choices', function () {
    $csv = "Nom;Portable perso;Divers\nDupont;06 12 34 56 78;a@example.test\nMartin;07 12 34 56 78;b@example.test\nLeroy;;e@example.test\n";
    $first = uploadImport($csv, ImportTarget::People);
    $this->put("/crm/import/{$first->id}/mapping", ['map' => ['Nom' => 'last_name', 'Portable perso' => 'mobile', 'Divers' => '']])
        ->assertSessionHasNoErrors();

    // Same headers, other spelling, other values.
    $second = uploadImport("NOM;portable  perso;Divers\nDurand;Inconnu;c@example.test\nLeroy;;d@example.test\nPetit;;f@example.test\n", ImportTarget::People);
    $this->get("/crm/import/{$second->id}/mapping")->assertInertia(fn (Assert $page) => $page
        ->where('map', ['NOM' => 'last_name', 'portable  perso' => 'mobile'])
        ->where('reasons', ['NOM' => 'memory', 'portable  perso' => 'memory']));

    $this->put("/crm/import/{$second->id}/mapping", ['map' => ['NOM' => 'last_name', 'portable  perso' => 'mobile', 'Divers' => '']]);
    expect(HeaderChoice::query()->where('header_key', 'portable perso')->sole()->uses)->toBe(2)
        // Another target does not share the memory.
        ->and(ColumnGuesser::guess(ImportTarget::Affiliations, ['Divers'], sampleRows(['Divers' => emails(5)])))->toBe(['Divers' => ['field' => 'email', 'reason' => 'content']]);

    // Another organisme sees nothing (RLS).
    inTenant(Tenant::factory()->create());
    expect(HeaderChoice::query()->count())->toBe(0)
        ->and(ColumnGuesser::guess(ImportTarget::People, ['Portable perso'], []))->toBe(['Portable perso' => ['field' => 'mobile', 'reason' => 'similar']]);
});

it('never overrides a saved or profile mapping', function () {
    $csv = "Raison sociale;Ville;Courriel\nHôtel du Lac;Annecy;a@example.test\n";
    $batch = uploadImport($csv, ImportTarget::Organisations);
    $this->put("/crm/import/{$batch->id}/mapping", ['map' => ['Raison sociale' => 'name', 'Ville' => '', 'Courriel' => '']]);
    expect($batch->refresh()->status)->toBe(ImportBatch::SIMULATED); // still editable

    $this->get("/crm/import/{$batch->id}/mapping")->assertInertia(fn (Assert $page) => $page
        ->where('map', ['Raison sociale' => 'name'])
        ->where('reasons', []));

    $profile = ImportProfile::query()->create(['name' => 'Export', 'target' => ImportTarget::Organisations,
        'field_map' => ['Ville' => 'name'], 'value_map' => [], 'options' => []]);
    $this->post('/crm/import', ['file' => UploadedFile::fake()->createWithContent('a.csv', $csv), 'target' => 'organisations', 'profile_id' => $profile->id]);
    $fromProfile = ImportBatch::query()->where('profile_id', $profile->id)->sole();

    $this->get("/crm/import/{$fromProfile->id}/mapping")->assertInertia(fn (Assert $page) => $page
        ->where('map', ['Ville' => 'name'])
        ->where('reasons', []));
});

it('stores no cell value', function () {
    $batch = uploadImport("Nom;Prénom;Col 3\nDupont;Léa;lea@example.test\n", ImportTarget::People);
    $this->put("/crm/import/{$batch->id}/mapping", ['map' => ['Nom' => 'last_name', 'Prénom' => 'first_name', 'Col 3' => 'email']])
        ->assertSessionHasNoErrors();

    $rows = DB::table('import_header_choices')->orderBy('header_key')->get(['target', 'header_key', 'field', 'uses'])->map(fn ($r) => (array) $r)->all();
    expect($rows)->toBe([
        ['target' => 'people', 'header_key' => 'col 3', 'field' => 'email', 'uses' => 1],
        ['target' => 'people', 'header_key' => 'nom', 'field' => 'last_name', 'uses' => 1],
        ['target' => 'people', 'header_key' => 'prenom', 'field' => 'first_name', 'uses' => 1],
    ])
        ->and(json_encode(DB::table('import_header_choices')->get()))->not->toContain('Dupont')->not->toContain('lea@example.test');
});

/** Rows of import_header_choices: header key => field (null: « Ignorer »). */
function rememberedChoices(): array
{
    return DB::table('import_header_choices')->orderBy('header_key')->pluck('field', 'header_key')->all();
}

it('remembers only deliberate choices', function () {
    // Nom: exact header, kept. Tél portable contact: close header, kept.
    // Col A: e-mails, proposal from the content kept: not remembered.
    // Col B: postcodes, proposal turned into « Ignorer »: remembered as ignored.
    // Col C: nothing proposed, left on « Ignorer »: not remembered.
    // Col D: nothing proposed, chosen by the user: remembered.
    // Col E: civilities proposed from the content, changed by the user: remembered.
    $csv = "Nom;Tél portable contact;Col A;Col B;Col C;Col D;Col E\n"
        ."Dupont;0612345678;a@example.test;75011;?;Lyon;M.\n"
        ."Martin;0712345678;b@example.test;69002;?;Pau;Mme\n"
        ."Leroy;0698765432;c@example.test;13001;?;Nice;Madame\n";
    $batch = uploadImport($csv, ImportTarget::People);

    $this->get("/crm/import/{$batch->id}/mapping")->assertInertia(fn (Assert $page) => $page
        ->where('reasons', ['Nom' => 'header', 'Tél portable contact' => 'similar', 'Col A' => 'content', 'Col B' => 'content', 'Col E' => 'content']));

    // The client cannot pretend a proposal: the server recomputes it.
    $this->put("/crm/import/{$batch->id}/mapping", ['map' => ['Nom' => 'last_name', 'Tél portable contact' => 'mobile', 'Col A' => 'email',
        'Col B' => '', 'Col C' => '', 'Col D' => 'city', 'Col E' => 'first_name'], 'reasons' => ['Col A' => 'header']])->assertSessionHasNoErrors();

    expect(rememberedChoices())->toBe([
        'col b' => null,
        'col d' => 'city',
        'col e' => 'first_name',
        'nom' => 'last_name',
        'tel portable contact' => 'mobile',
    ]);
});
