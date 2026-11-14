<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Duplicates\Merger;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Import\ImportFileException;
use App\Modules\Crm\Import\ImportProfile;
use App\Modules\Crm\Import\ImportRow;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Import\RunImport;
use App\Modules\Crm\Import\ValueMapper;
use App\Modules\Crm\Import\Values;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Timeline\Note;
use App\Rules\Siret;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->user = commercial();
    $this->actingAs($this->user);
});

/**
 * @return list<list<string>>
 */
function reportRows(ImportBatch $batch): array
{
    $reader = new Reader;
    $reader->open(Storage::disk('local')->path((string) $batch->report_path));
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($v) => (string) $v, $row->toArray());
        }
        break;
    }
    $reader->close();

    return $rows;
}

it('imports entreprises through the domain code paths', function () {
    $batch = runImport('utf8_semicolon.csv', ImportTarget::Organisations);

    expect($batch->status)->toBe(ImportBatch::DONE)
        ->and([$batch->total, $batch->created, $batch->updated, $batch->skipped, $batch->errors])->toBe([3, 3, 0, 0, 0])
        ->and($batch->finished_at)->not->toBeNull();

    $hotel = Organisation::query()->where('name', 'Hôtel du Lac')->sole();
    expect($hotel->siret)->toBe('73282932000074')
        ->and($hotel->postal_code)->toBe('74000')
        ->and($hotel->phone)->toBe('04 50 00 00 00')
        ->and($hotel->email)->toBe('contact@hotel-lac.fr')
        ->and($hotel->origin_id)->toBe(crmItem(ReferenceList::ContactOrigin, 'Salon')->id)
        ->and($hotel->created_by)->toBe($this->user->id)
        ->and($hotel->status->value)->toBe('prospect')
        ->and(Organisation::query()->where('name', "Café des Arts\n(siège)")->value('origin_id'))->toBe(crmItem(ReferenceList::ContactOrigin, 'Site web')->id)
        // The search column is filled by the database, as for a fiche typed on screen.
        ->and(Organisation::query()->search('hotel lac')->count())->toBe(1);

    // Journal: one « created » per fiche, by the importing user.
    expect(AuditLog::query()->where('event', 'created')->where('auditable_type', 'organisation')->where('user_id', $this->user->id)->count())->toBe(3);
    $run = AuditLog::query()->where('event', 'crm.import_run')->sole();
    expect($run->new_values)->toEqualCanonicalizing(['target' => 'organisations', 'strategy' => 'skip', 'total' => 3, 'created' => 3, 'updated' => 0, 'skipped' => 0, 'errors' => 0])
        ->and($run->user_id)->toBe($this->user->id);

    $rows = ImportRow::query()->orderBy('line')->get();
    expect($rows->pluck('line')->all())->toBe([2, 3, 4])
        ->and($rows->pluck('status')->unique()->all())->toBe([ImportRow::CREATED])
        ->and($rows[0]->subject_type)->toBe('organisation')
        ->and($rows[0]->subject_id)->toBe($hotel->id)
        ->and($rows[0]->created_subject)->toBeTrue()
        ->and($rows[0]->source['Raison sociale'])->toBe('Hôtel du Lac');

    expect($batch->report_path)->toBe(TenantStorage::path('imports', $batch->id.'/rapport.xlsx'))
        ->and(reportRows($batch))->toBe([
            ['Ligne', 'Statut', 'Messages', 'Fiche', 'Identifiant de la fiche'],
            ['2', 'Créée', '', 'Entreprise', $hotel->id],
            ['3', 'Créée', '', 'Entreprise', $rows[1]->subject_id],
            ['4', 'Créée', '', 'Entreprise', $rows[2]->subject_id],
        ]);
});

it('imports a windows-1252 excel export with damaged siret', function () {
    $batch = runImport('windows1252_excel.csv', ImportTarget::Organisations);

    expect($batch->status)->toBe(ImportBatch::DONE)
        // Empty lines are not rows: 4 rows out of 10 lines.
        ->and([$batch->total, $batch->created, $batch->errors])->toBe([4, 3, 1])
        ->and($batch->simulation['warnings'])->toContain('La colonne « Raison sociale » apparaît plusieurs fois : seule la première est utilisée.')
        ->and($batch->simulation['warnings'])->toContain('Fichier lu en Windows-1252, séparateur « ; ».');

    // The damaged SIRET is refused with a clear message, nothing is created for it.
    expect($batch->rows()->where('line', 3)->value('status'))->toBe(ImportRow::ERROR)
        ->and(rowMessages($batch, 3)[0])->toStartWith('SIRET : SIRET altéré par Excel (notation scientifique)')
        ->and(Organisation::query()->where('name', 'Crêperie Ty Breizh')->exists())->toBeFalse();

    // The rest is imported, accents intact; a SIRET that lost its leading zero is restored.
    expect(Organisation::query()->orderBy('name')->pluck('siret', 'name')->all())->toBe([
        'Brasserie Œnologie & Cie' => '01234567800010',
        'Hôtel Bellevue' => '73282932000074',
        'Pâtisserie Müller' => '55234567800015',
    ])
        ->and(rowMessages($batch, 4))->toBe([Values::SIRET_PADDED])
        ->and(Organisation::query()->where('name', 'Hôtel Bellevue')->value('phone'))->toBe('04 50 11 22 33');
});

it('applies the duplicate strategies', function (string $strategy, array $file, string $status, int $count, ?string $city) {
    $existing = Organisation::query()->create(['name' => 'Hôtel du Lac', 'siret' => '73282932000074', 'email' => 'lac@exemple.fr']);
    $existing->forceFill(['updated_at' => now()->subDay()])->saveQuietly();

    $batch = runImport("Raison sociale;SIRET;E-mail;Ville\n".implode(';', $file)."\n", ImportTarget::Organisations, ['strategy' => $strategy]);

    expect($batch->rows()->value('status'))->toBe($status)
        ->and(Organisation::query()->count())->toBe($count)
        ->and($existing->refresh()->city)->toBe($city)
        ->and($existing->name)->toBe('Hôtel du Lac');
})->with([
    'skip: ignored' => ['skip', ['Hôtel du Lac', '73282932000074', '', 'Annecy'], ImportRow::SKIPPED, 1, null],
    'merge: empty fields completed' => ['merge', ['Hôtel du Lac SARL', '73282932000074', '', 'Annecy'], ImportRow::UPDATED, 1, 'Annecy'],
    'create: never on a used siret' => ['create', ['Hôtel du Lac', '73282932000074', '', 'Annecy'], ImportRow::ERROR, 1, null],
    'create: same e-mail' => ['create', ['Hôtel du Lac', '', 'lac@exemple.fr', 'Annecy'], ImportRow::CREATED, 2, null],
]);

it('replays a chunk row by row when one row fails, keeping the others and the created values', function () {
    Organisation::query()->create(['name' => 'Déjà là', 'siret' => '73282932000074']);

    $batch = runImport("Raison sociale;SIRET;Origine\nA;;Salon pro\nB;73282932000074;Salon pro\nC;;Salon pro\n", ImportTarget::Organisations,
        ['strategy' => 'create'], values: ['origin' => ['Salon pro' => ValueMapper::CREATE]]);

    $item = ReferenceItem::query()->where('label', 'Salon pro')->sole();
    expect([$batch->created, $batch->errors])->toBe([2, 1])
        ->and(rowMessages($batch, 3))->toBe(['SIRET : Une entreprise avec ce SIRET existe déjà.'])
        ->and(Organisation::query()->whereIn('name', ['A', 'C'])->pluck('origin_id')->unique()->all())->toBe([$item->id])
        ->and(ImportRow::query()->count())->toBe(3);
});

it('stops, resumable, on a database out of resources or shutting down instead of failing every row', function (string $state) {
    Organisation::creating(function (Organisation $organisation) use ($state): void {
        if ($organisation->name === 'Boum') {
            DB::select("do $$ begin raise exception 'arrêt' using errcode = '{$state}'; end $$");
        }
    });

    $batch = uploadImport("Raison sociale\nA\nBoum\nC\n", ImportTarget::Organisations);
    simulateImport($batch);

    // The job fails (the worker reports it); the chunk is rolled back, the batch stays running
    // at its last cursor, for the watchdog to resume once the database is back.
    expect(fn () => RunImport::dispatch($batch->id))->toThrow(QueryException::class);

    $batch->refresh();
    expect($batch->status)->toBe(ImportBatch::RUNNING)
        ->and([$batch->cursor_line, $batch->errors])->toBe([0, 0])
        ->and(ImportRow::query()->count())->toBe(0)
        ->and(Organisation::query()->count())->toBe(0);
})->with(['out of memory (53200)' => '53200', 'admin shutdown (57P01)' => '57P01', 'crash shutdown (57P02)' => '57P02', 'cannot connect now (57P03)' => '57P03']);

it('turns a row cancelled by a statement timeout (57014) into an error row and goes on', function () {
    Organisation::creating(function (Organisation $organisation): void {
        if ($organisation->name === 'Lente') {
            DB::select("do $$ begin raise exception 'délai' using errcode = '57014'; end $$");
        }
    });

    $batch = runImport("Raison sociale\nA\nLente\nC\n", ImportTarget::Organisations);

    expect($batch->status)->toBe(ImportBatch::DONE)
        ->and([$batch->created, $batch->errors])->toBe([2, 1])
        ->and(rowMessages($batch, 3))->toBe(['Ligne non importée : la base de données l\'a refusée (code 57014).']);
});

it('turns a row failing on any database error into an error row and goes on', function () {
    // A database error that is not a uniqueness conflict, on one row only.
    Organisation::creating(function (Organisation $organisation): void {
        if ($organisation->name === 'Boum Durand') {
            DB::select('select 1 / 0');
        }
    });
    Log::spy();

    $batch = runImport("Raison sociale;Ville\nA;Lyon\nBoum Durand;Pau\nC;Paris\n", ImportTarget::Organisations);

    expect($batch->status)->toBe(ImportBatch::DONE)
        ->and([$batch->total, $batch->created, $batch->errors])->toBe([3, 2, 1])
        ->and(rowMessages($batch, 3))->toBe(['Ligne non importée : la base de données l\'a refusée (code 22012).'])
        ->and(Organisation::query()->orderBy('name')->pluck('name')->all())->toBe(['A', 'C']);

    // Logged for the administrator without any value of the row.
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context['sqlstate'] === '22012'
        && $context['line_number'] === 3 && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'Durand'))->once();
});

it('re-imports the same file by source id: updates, no duplicates', function () {
    $file = "Identifiant;Raison sociale;Ville\nC1;Hôtel du Lac;Annecy\nC2;Garage Martin;Pau\n";
    $first = runImport($file, ImportTarget::Organisations, ['source_system' => 'formevol']);
    expect($first->created)->toBe(2)
        ->and(Organisation::query()->where('source_id', 'C1')->value('source_system'))->toBe('formevol');

    $second = runImport(str_replace('Pau', 'Tarbes', $file), ImportTarget::Organisations, ['source_system' => 'formevol']);

    expect([$second->created, $second->updated, $second->skipped, $second->errors])->toBe([0, 2, 0, 0])
        ->and(Organisation::query()->count())->toBe(2)
        ->and(Organisation::query()->where('source_id', 'C2')->value('city'))->toBe('Tarbes')
        ->and(rowMessages($second, 2))->toBe(['Aucune modification.']);
});

it('re-import resolves a merged fiche to the kept one', function () {
    $file = "Identifiant;Raison sociale;Ville\nC1;Hôtel du Lac;Annecy\nC2;Le Lac;Chambéry\n";
    runImport($file, ImportTarget::Organisations, ['source_system' => 'formevol']);
    $keep = Organisation::query()->where('source_id', 'C1')->sole();
    $drop = Organisation::query()->where('source_id', 'C2')->sole();
    app(Merger::class)->organisations($keep, $drop, []);

    $again = runImport(str_replace('Le Lac', 'Le Lac (fusionné)', $file), ImportTarget::Organisations, ['source_system' => 'formevol']);

    expect([$again->created, $again->updated, $again->errors])->toBe([0, 2, 0])
        ->and(Organisation::query()->count())->toBe(1)
        ->and($again->rows()->where('line', 3)->value('subject_id'))->toBe($keep->id)
        ->and($keep->refresh()->name)->toBe('Le Lac (fusionné)');
});

it('imports people with normalised phones and personal data masked in the journal', function () {
    $batch = runImport('comma_bom.csv', ImportTarget::People);

    expect($batch->created)->toBe(2);
    $helene = Person::query()->where('first_name', 'Hélène')->sole();
    expect($helene->last_name)->toBe('Lefèvre')
        ->and($helene->mobile)->toBe('+33612345678')
        ->and($helene->email)->toBe('helene.lefevre@example.fr')
        ->and($helene->city)->toBe('Saint-Étienne, Loire')
        ->and($helene->is_individual)->toBeTrue();

    $journal = AuditLog::query()->get()->map(fn (AuditLog $l) => json_encode([$l->old_values, $l->new_values], JSON_UNESCAPED_UNICODE))->implode(' ');
    expect($journal)->not->toContain('Lefèvre')
        ->and($journal)->not->toContain('helene.lefevre@example.fr')
        ->and($journal)->not->toContain('+33612345678')
        ->and(AuditLog::query()->where('auditable_type', 'import_row')->count())->toBe(0);
});

it('imports contacts with their entreprise from an xlsx', function () {
    $batch = runImport('contacts.xlsx', ImportTarget::Affiliations, map: ['Ville' => 'organisation.city']);

    expect([$batch->total, $batch->created, $batch->errors])->toBe([5, 3, 2])
        ->and(rowMessages($batch, 6)[0])->toStartWith('Entreprise : SIRET : SIRET altéré par Excel (notation scientifique)')
        ->and(rowMessages($batch, 7)[0])->toStartWith('Entreprise : SIRET : SIRET altéré par Excel');

    // Hôtel Bellevue once, with its two interlocuteurs.
    $bellevue = Organisation::query()->where('siret', '73282932000074')->sole();
    expect($bellevue->name)->toBe('Hôtel Bellevue')
        ->and($bellevue->city)->toBe('Annecy')
        ->and(Organisation::query()->where('name', 'Brasserie Œnologie')->value('siret'))->toBe('01234567800010')
        ->and(Organisation::query()->count())->toBe(2);

    $elodie = Person::query()->where('email', 'elodie.durand@bellevue.fr')->sole();
    $link = Affiliation::query()->where('person_id', $elodie->id)->sole();
    expect($elodie->first_name)->toBe('Élodie')
        ->and($elodie->is_individual)->toBeFalse()
        ->and($link->organisation_id)->toBe($bellevue->id)
        ->and($link->job_title)->toBe('Directrice')
        ->and($link->started_on?->toDateString())->toBe('2024-03-15')
        ->and(Affiliation::query()->where('organisation_id', $bellevue->id)->count())->toBe(2)
        ->and(Person::query()->count())->toBe(3);

    $row = $batch->rows()->where('line', 2)->sole();
    expect($row->subject_type)->toBe('affiliation')
        ->and(collect($row->created_refs)->pluck('type')->all())->toBe(['organisation', 'person', 'affiliation'])
        ->and(collect($batch->rows()->where('line', 3)->sole()->created_refs)->pluck('type')->all())->toBe(['person', 'affiliation']);
});

it('creates a reference value once when asked', function () {
    $batch = runImport("Raison sociale;Origine\nA;Salon pro\nB;salon PRO\n", ImportTarget::Organisations, values: ['origin' => ['Salon pro' => ValueMapper::CREATE]]);

    $item = ReferenceItem::query()->where('list', ReferenceList::ContactOrigin)->where('label', 'Salon pro')->sole();
    expect($batch->created)->toBe(2)
        ->and(Organisation::query()->pluck('origin_id')->unique()->all())->toBe([$item->id]);
});

it('imports notes on fiches found by siret, e-mail or source id', function () {
    $this->travelTo(now()->setDate(2026, 6, 1)->setTime(10, 0));
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Lac', 'siret' => '73282932000074']);
    $garage = Organisation::query()->create(['name' => 'Garage Martin']);
    $garage->forceFill(['source_system' => 'formevol', 'source_id' => 'C42'])->save();
    $paul = makePerson('Martin', ['email' => 'paul@exemple.fr']);
    DB::table('people')->where('id', $paul->id)->update(['last_contact_at' => '2026-01-01 09:00:00']);

    $file = implode("\n", [
        'SIRET;E-mail;Identifiant;Date;Compte rendu',
        '73282932000074;;;15/02/2026;Appel : intéressé par la formation HACCP',
        ';PAUL@exemple.fr;;2025-05-01;Ancien échange',
        ';;C42;10/03/2026 14:30;Rendez-vous',
        ';inconnu@exemple.fr;;;Perdu',
        '',
    ]);
    $batch = runImport($file, ImportTarget::Notes, ['source_system' => 'formevol']);

    expect([$batch->created, $batch->errors])->toBe([3, 1])
        ->and(rowMessages($batch, 5))->toBe(['Aucune fiche ne correspond (SIRET, e-mail ou identifiant source) : ligne non importée.']);

    $note = Note::query()->where('notable_id', $hotel->id)->sole();
    expect($note->body)->toBe('Appel : intéressé par la formation HACCP')
        ->and($note->author_id)->toBe($this->user->id)
        ->and($note->created_at->toDateString())->toBe('2026-02-15')
        ->and($hotel->refresh()->last_contact_at?->toDateString())->toBe('2026-02-15')
        ->and(Note::query()->where('notable_id', $garage->id)->value('created_at')?->format('d/m/Y H:i'))->toBe('10/03/2026 14:30')
        // An older exchange never moves the last contact back.
        ->and($paul->refresh()->last_contact_at?->toDateString())->toBe('2026-01-01')
        ->and(Note::query()->where('notable_id', $paul->id)->count())->toBe(1);

    // Re-importing the same history does not duplicate it.
    $again = runImport($file, ImportTarget::Notes, ['source_system' => 'formevol']);
    expect([$again->created, $again->skipped])->toBe([0, 3])
        ->and(Note::query()->count())->toBe(3);
});

it('keeps imports of another organisme invisible and untouched', function () {
    $batch = uploadImport('utf8_semicolon.csv', ImportTarget::Organisations);
    simulateImport($batch);
    $mine = tenant();

    $other = Tenant::factory()->create();
    app(TenantContext::class)->run($other, function () use ($batch): void {
        // Same SIRET in the other organisme: never a duplicate of ours.
        Organisation::query()->create(['name' => 'Hôtel du Lac (autre organisme)', 'siret' => '73282932000074']);

        expect(ImportBatch::query()->count())->toBe(0)
            ->and(ImportProfile::query()->count())->toBe(0);

        // A job of the other organisme naming our batch finds nothing.
        RunImport::dispatch($batch->id);
    });

    inTenant($mine);
    expect($batch->refresh()->status)->toBe(ImportBatch::SIMULATED);

    RunImport::dispatch($batch->id);
    expect($batch->refresh()->created)->toBe(3)
        ->and(Organisation::query()->count())->toBe(3);

    app(TenantContext::class)->run($other, function (): void {
        expect(ImportRow::query()->count())->toBe(0)
            ->and(Organisation::query()->count())->toBe(1);
    });
});

it('saves and reuses an import profile', function () {
    $profile = ImportProfile::query()->create([
        'name' => 'Export Excel commercial', 'target' => ImportTarget::Organisations,
        'field_map' => ['Société' => 'name', 'Provenance' => 'origin'],
        'value_map' => ['origin' => ['Foire' => crmItem(ReferenceList::ContactOrigin, 'Salon')->id]],
        'options' => ['strategy' => 'merge'],
    ]);

    expect($profile->fieldMap()->toArray())->toBe(['Société' => 'name', 'Provenance' => 'origin'])
        ->and($profile->valueMapper()->actionFor('origin', 'foire'))->toBe(crmItem(ReferenceList::ContactOrigin, 'Salon')->id)
        ->and($profile->created_by)->toBe($this->user->id);
});

it('imports 10 000 rows in less than 120 seconds', function () {
    $lines = ['Raison sociale;SIRET;Code postal;Ville;E-mail;Téléphone;Origine'];
    for ($i = 0; $i < 10000; $i++) {
        $prefix = sprintf('8%012d', $i);
        for ($k = 0; ! Siret::isValid($prefix.$k); $k++);
        // Distinct names, places and contacts: no duplicate among them.
        $lines[] = sprintf('Entreprise %05d;%s;%05d;Ville %d;contact%d@exemple.fr;04 %02d %02d %02d %02d;Salon', $i, $prefix.$k, 10000 + $i, $i, $i, intdiv($i, 1000000) % 100, intdiv($i, 10000) % 100, intdiv($i, 100) % 100, $i % 100);
    }
    $batch = uploadImport(implode("\n", $lines)."\n", ImportTarget::Organisations);
    $simulation = microtime(true);
    simulateImport($batch);
    fwrite(STDERR, sprintf("\nsimulation of 10 000 rows: %.1f s", microtime(true) - $simulation));

    $started = microtime(true);
    RunImport::dispatch($batch->id);
    $seconds = microtime(true) - $started;
    fwrite(STDERR, sprintf("\n10 000 rows imported in %.1f s\n", $seconds));

    expect($batch->refresh()->created)->toBe(10000)
        ->and($batch->status)->toBe(ImportBatch::DONE)
        ->and(Organisation::query()->count())->toBe(10000)
        // 120 s, not 60: shared CI runners are about twice as slow as a workstation.
        ->and($seconds)->toBeLessThan(120);
})->group('slow');

// --- Fix round 1: re-import never brings back what users removed ----------

it('does not restore a fiche deleted by a user when re-importing', function (string $strategy) {
    $file = "Identifiant;Raison sociale\nC1;Garage Martin\nC2;Café des Arts\n";
    runImport($file, ImportTarget::Organisations, ['source_system' => 'formevol']);
    $garage = Organisation::query()->where('source_id', 'C1')->sole();
    $garage->delete();

    $again = runImport($file, ImportTarget::Organisations, ['source_system' => 'formevol', 'strategy' => $strategy]);

    expect($garage->refresh()->trashed())->toBeTrue()
        ->and(Organisation::withTrashed()->count())->toBe(2)
        ->and($again->rows()->where('line', 2)->value('status'))->toBe(ImportRow::SKIPPED)
        ->and(rowMessages($again, 2))->toBe(['Fiche supprimée par un utilisateur : non réimportée.']);
})->with(['skip', 'merge', 'create']);

it('never updates an anonymised person', function () {
    $file = "Identifiant;Nom;Ville\nP1;Lefèvre;Lyon\n";
    runImport($file, ImportTarget::People, ['source_system' => 'formevol']);
    $person = Person::query()->where('source_id', 'P1')->sole();
    DB::table('people')->where('id', $person->id)->update(['last_name' => 'Anonyme', 'city' => null, 'anonymised_at' => now()]);

    $again = runImport($file, ImportTarget::People, ['source_system' => 'formevol']);

    expect($person->refresh()->last_name)->toBe('Anonyme')
        ->and($person->city)->toBeNull()
        ->and($again->rows()->value('status'))->toBe(ImportRow::SKIPPED)
        ->and(rowMessages($again, 2))->toBe(['Fiche anonymisée (RGPD) : non réimportée.']);
});

it('warns in the simulation about fiches modified since their import', function () {
    $file = "Identifiant;Raison sociale;Ville\nC1;Garage Martin;Pau\nC2;Café des Arts;Paris\n";
    runImport($file, ImportTarget::Organisations, ['source_system' => 'formevol']);
    $this->travel(1)->minutes();
    Organisation::query()->where('source_id', 'C1')->sole()->update(['city' => 'Tarbes']);

    $report = simulateImport(uploadImport($file, ImportTarget::Organisations, ['source_system' => 'formevol']));

    expect($report->modifiedSinceImport)->toBe(1)
        ->and($report->warnings)->toContain('1 fiche modifiée depuis son import sera mise à jour avec les valeurs du fichier.');
});

it('only simulates a batch not yet run, and only runs a simulated one', function () {
    $batch = runImport("Raison sociale\nGarage Martin\n", ImportTarget::Organisations);
    $map = $batch->field_map;

    expect(fn () => simulateImport($batch, ['Raison sociale' => null]))->toThrow(ImportFileException::class, 'Cet import a déjà été lancé');
    expect($batch->refresh()->field_map)->toBe($map)
        ->and($batch->status)->toBe(ImportBatch::DONE);

    $uploaded = uploadImport("Raison sociale\nCafé des Arts\n", ImportTarget::Organisations);
    RunImport::dispatch($uploaded->id);
    expect($uploaded->refresh()->status)->toBe(ImportBatch::UPLOADED)
        ->and(Organisation::query()->count())->toBe(1);
});

// --- Fix round 2 ------------------------------------------------------------

it('re-simulates a large re-import with a bounded number of queries', function () {
    $lines = ['Identifiant;Raison sociale;Ville'];
    for ($i = 0; $i < 2000; $i++) {
        $lines[] = "C{$i};Entreprise {$i};Ville {$i}";
    }
    runImport(implode("\n", $lines)."\n", ImportTarget::Organisations, ['source_system' => 'formevol']);
    $this->travel(1)->minutes();
    Organisation::query()->where('source_id', 'C7')->sole()->update(['city' => 'Changée']);

    $batch = uploadImport(str_replace('Ville ', 'Commune ', implode("\n", $lines))."\n", ImportTarget::Organisations, ['source_system' => 'formevol']);
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $started = microtime(true);
    $report = simulateImport($batch);
    $seconds = microtime(true) - $started;
    fwrite(STDERR, sprintf("\nre-simulation of 2 000 rows: %d queries, %.1f s\n", $queries, $seconds));

    expect($report->toUpdate)->toBe(2000)
        ->and($report->modifiedSinceImport)->toBe(1)
        // A few queries per 500-row chunk, never one per row.
        ->and($queries)->toBeLessThan(60)
        ->and($seconds)->toBeLessThan(5.0);
});

it('never attaches an imported note to an anonymised person', function () {
    $person = makePerson('Martin', ['email' => 'paul@exemple.fr']);
    DB::table('people')->where('id', $person->id)->update(['anonymised_at' => now()]);

    $batch = runImport("E-mail;Note\npaul@exemple.fr;Rappel\n", ImportTarget::Notes);

    expect(Note::query()->count())->toBe(0)
        ->and($batch->rows()->value('status'))->toBe(ImportRow::SKIPPED)
        ->and(rowMessages($batch, 2))->toBe(['Fiche anonymisée (RGPD) : non réimportée.']);
});
