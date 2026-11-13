<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Gdpr\AnonymisationPostponed;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Gdpr\Consent;
use App\Modules\Crm\Gdpr\GdprPending;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Import\ImportFiles;
use App\Modules\Crm\Import\ImportRow;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Import\UndoImport;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Timeline\Attachment;
use App\Modules\Crm\Timeline\Note;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->user = commercial();
    $this->actingAs($this->user);
});

function anonymise(Person $person, string $reason = 'manual'): void
{
    app(Anonymiser::class)->anonymise($person, $reason);
}

function attachTo(Person|Organisation $fiche, string $name, string $content): Attachment
{
    $kind = $fiche instanceof Person ? 'people' : 'organisations';
    test()->post("/crm/{$kind}/{$fiche->id}/attachments", ['file' => UploadedFile::fake()->createWithContent($name, $content)])->assertSessionHasNoErrors();

    return Attachment::query()->latest('created_at')->firstOrFail();
}

it('scrubs the person, her links, notes, attachments and consents, and keeps the statistics', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $person = makePerson('Durand', [
        'civility' => 'Mme', 'first_name' => 'Claire', 'email' => 'claire@example.test', 'mobile' => '0612345678',
        'city' => 'Annecy', 'birth_date' => '1980-01-02', 'project' => 'BTS', 'is_individual' => true,
    ]);
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    DB::table('person_das')->insert(['tenant_id' => tenant()->id, 'person_id' => $person->id, 'das_id' => $das->id]);
    $link = affiliate($person, $org, ['job_title' => 'Gérante', 'work_email' => 'c.durand@lac.test', 'work_phone' => '0450000000']);
    $old = affiliate($person, $org, ['ended_on' => '2020-01-01', 'work_email' => 'old@lac.test']);
    $old->delete();
    $this->post("/crm/people/{$person->id}/notes", ['body' => 'Souhaite un BTS'])->assertSessionHasNoErrors();
    $this->post("/crm/organisations/{$org->id}/notes", ['body' => 'Note entreprise'])->assertSessionHasNoErrors();
    $file = attachTo($person, 'CV Durand.pdf', "%PDF-1.4\nCV\n%%EOF\n");
    $orgFile = attachTo($org, 'Plaquette.pdf', "%PDF-1.4\nP\n%%EOF\n");
    Consent::query()->create(['person_id' => $person->id, 'channel' => 'email', 'granted' => true, 'source' => 'Formulaire salon']);

    anonymise($person, 'manual');

    $row = (array) DB::table('people')->where('id', $person->id)->first();
    expect($row['last_name'])->toBe('Anonyme')
        ->and(collect(Person::PERSONAL_FIELDS)->reject(fn ($f) => $f === 'last_name')->map(fn ($f) => $row[$f])->filter()->all())->toBe([])
        ->and($row['civility'])->toBeNull()
        ->and($row['anonymised_at'])->not->toBeNull()
        ->and($row['search_vector'])->not->toContain('durand')->not->toContain('claire')
        ->and($row['is_individual'])->toBeTrue()
        ->and($row['status'])->toBe('prospect');

    // Links kept (statistics) without contact data; DAS kept.
    expect(Affiliation::withTrashed()->where('person_id', $person->id)->get(['work_email', 'work_phone', 'job_title'])->map->toArray()->all())
        ->each->toBe(['work_email' => null, 'work_phone' => null, 'job_title' => null])
        ->and($link->refresh()->organisation_id)->toBe($org->id)
        ->and(DB::table('person_das')->where('person_id', $person->id)->count())->toBe(1);

    // Notes and attachments of the person gone (rows and files), the entreprise's stay.
    expect(Note::withTrashed()->pluck('body')->all())->toBe(['Note entreprise'])
        ->and(Attachment::withTrashed()->pluck('id')->all())->toBe([$orgFile->id]);
    Storage::disk('local')->assertMissing($file->path);
    Storage::disk('local')->assertExists($orgFile->path);

    // Consents kept as history, without their free text.
    expect(Consent::query()->where('person_id', $person->id)->sole()->source)->toBeNull();

    $audit = AuditLog::query()->where('event', 'gdpr.anonymised')->sole();
    expect($audit->auditable_id)->toBe($person->id)
        ->and($audit->old_values)->toBeEmpty()
        ->and($audit->new_values)->toEqualCanonicalizing(['reason' => 'manual', 'notes' => 1, 'attachments' => 1]);

    // Irreversible and idempotent.
    anonymise($person->refresh());
    expect(AuditLog::query()->where('event', 'gdpr.anonymised')->count())->toBe(1);
});

it('leaves no personal value anywhere (every table row and every stored file)', function () {
    $values = [
        'Zébulonnière', 'Anatolius', 'anatolius.zeb@exemple-rgpd.fr', 'azeb.perso@exemple-rgpd.fr', '478123456', '698765432',
        'Impasse des Glycines Bleues', 'Bâtiment Quetzal', 'Vallorcine-sur-Zébu', '1971-03-17', 'Devenir apiculteur lunaire',
        'a.zebulonniere@hotel-rgpd.fr', '450998877', '06 98 76 54 32', '04 78 12 34 56', 'Note secrète Quokka', 'CONTENU-PJ-QUOKKA', 'Salon Ornithorynque',
    ];

    // Imported first (raw values in the batch file and its rows)...
    $batch = runImport("Nom;Prénom;E-mail;Mobile;Ville\nZébulonnière;Anatolius;anatolius.zeb@exemple-rgpd.fr;06 98 76 54 32;Vallorcine-sur-Zébu\nMartin;Paul;paul@example.test;;Pau\n", ImportTarget::People);
    expect($batch->created)->toBe(2);
    $person = Person::query()->where('last_name', 'Zébulonnière')->sole();

    // ... a second file uploaded and simulated, never run ...
    $pendingBatch = uploadImport("Nom;E-mail;Statut\nZebulonniere;anatolius.zeb@exemple-rgpd.fr;Inconnu\n", ImportTarget::People);
    simulateImport($pendingBatch);
    expect($pendingBatch->refresh()->simulation['error_rows'] ?? [])->not->toBe([]);

    // ... then completed by hand.
    $this->put("/crm/people/{$person->id}", [
        'civility' => 'M.', 'last_name' => 'Zébulonnière', 'first_name' => 'Anatolius', 'email' => 'anatolius.zeb@exemple-rgpd.fr',
        'email_secondary' => 'azeb.perso@exemple-rgpd.fr', 'phone' => '04 78 12 34 56', 'mobile' => '06 98 76 54 32',
        'address_line1' => 'Impasse des Glycines Bleues', 'address_line2' => 'Bâtiment Quetzal', 'postal_code' => '74660',
        'city' => 'Vallorcine-sur-Zébu', 'birth_date' => '1971-03-17', 'project' => 'Devenir apiculteur lunaire',
    ])->assertSessionHasNoErrors();
    $org = Organisation::query()->create(['name' => 'Hôtel RGPD']);
    affiliate($person, $org, ['work_email' => 'a.zebulonniere@hotel-rgpd.fr', 'work_phone' => '04 50 99 88 77', 'job_title' => 'Veilleur']);
    $this->post("/crm/people/{$person->id}/notes", ['body' => 'Note secrète Quokka'])->assertSessionHasNoErrors();
    attachTo($person, 'CV Zébulonnière.txt', 'CONTENU-PJ-QUOKKA');
    $this->post("/crm/people/{$person->id}/consents", ['channel' => 'email', 'granted' => true, 'source' => 'Salon Ornithorynque'])->assertSessionHasNoErrors();
    $this->get('/crm/people?q=Zébulonnière')->assertOk();

    // Sanity: the values are really there before.
    expect(personalTraces($values))->not->toBe([]);

    anonymise($person->refresh(), 'request');

    expect(personalTraces($values))->toBe([]);
    // The other imported person is untouched.
    expect(Person::query()->where('last_name', 'Martin')->exists())->toBeTrue();
});

/**
 * Every place (table row or stored file) where one of $values still appears,
 * compared without case nor accents.
 *
 * @param  list<string>  $values
 * @return list<string>
 */
function personalTraces(array $values): array
{
    $needles = array_map(fn (string $v) => Str::lower(Str::ascii($v)), $values);
    $found = [];
    $haystack = function (string $where, string $text) use ($needles, &$found): void {
        $text = Str::lower(Str::ascii($text));
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                $found[] = "{$where}: {$needle}";
            }
        }
    };

    $tables = DB::select("select tablename from pg_tables where schemaname = 'public' and has_table_privilege(format('%I.%I', schemaname, tablename), 'select') order by tablename");
    foreach ($tables as $t) {
        foreach (DB::select('select row_to_json(t)::text as j from '.$t->tablename.' t') as $row) {
            $haystack('table '.$t->tablename, (string) json_encode(json_decode($row->j, true), JSON_UNESCAPED_UNICODE));
        }
    }

    $disk = Storage::disk(TenantStorage::DISK);
    foreach ($disk->allFiles() as $path) {
        $content = (string) $disk->get($path);
        $haystack('file '.$path, mb_convert_encoding($content, 'UTF-8', 'UTF-8, Windows-1252'));
    }

    return $found;
}

it('anonymisation scrubs import rows and keeps batch undoable', function () {
    $batch = runImport("Nom;Prénom;E-mail\nDurand;Claire;claire@example.test\nMartin;Paul;paul@example.test\nLeroy;Anne;anne@example.test\n", ImportTarget::People);
    $durand = Person::query()->where('last_name', 'Durand')->sole();
    $row = ImportRow::query()->where('batch_id', $batch->id)->where('line', 2)->sole();
    expect($row->source)->not->toBeNull();

    $this->travel(1)->minutes();
    anonymise($durand, 'request');

    $rows = ImportRow::query()->where('batch_id', $batch->id)->orderBy('line')->get();
    expect($rows[0]->source)->toBeNull()
        ->and($rows[1]->source)->not->toBeNull()
        ->and($rows[2]->source)->not->toBeNull()
        ->and(json_encode($rows[0]->messages))->not->toContain('Durand')
        ->and($batch->refresh()->stored_path)->toBeNull();
    // Journal: values were masked from the start, nothing to scrub there.
    $journal = AuditLog::query()->get()->map(fn ($l) => json_encode([$l->old_values, $l->new_values], JSON_UNESCAPED_UNICODE))->implode(' ');
    expect($journal)->not->toContain('Durand')->not->toContain('claire@example.test');

    // The batch can still be undone: the other people go, the anonymised one stays.
    expect($batch->canUndo())->toBeTrue();
    UndoImport::dispatch($batch->id);
    expect($batch->refresh()->status)->toBe(ImportBatch::UNDONE)
        ->and(Person::query()->pluck('last_name')->all())->toBe(['Anonyme'])
        ->and(Person::onlyTrashed()->orderBy('last_name')->pluck('last_name')->all())->toBe(['Leroy', 'Martin']);

    // A re-import never brings her back.
    $again = runImport("Nom;Prénom;E-mail\nDurand;Claire;claire@example.test\n", ImportTarget::People);
    expect($again->created)->toBe(1); // a new, different fiche (nothing links it to the anonymised one)
    expect($durand->refresh()->last_name)->toBe('Anonyme');
});

it('finds the import rows of her notes and links too', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac', 'siret' => '73282932000074']);
    $person = makePerson('Durand', ['email' => 'claire@example.test']);
    $notes = runImport("E-mail de la fiche;Note\nclaire@example.test;Entretien confidentiel\n", ImportTarget::Notes);
    $contacts = runImport("Nom;E-mail;Entreprise;SIRET\nDurand;claire@example.test;Hôtel du Lac;73282932000074\n", ImportTarget::Affiliations, ['strategy' => 'merge']);
    expect(ImportRow::query()->whereNotNull('source')->count())->toBe(2);

    anonymise($person->refresh());

    expect(ImportRow::query()->whereNotNull('source')->count())->toBe(0)
        ->and(Note::withTrashed()->count())->toBe(0)
        ->and($notes->refresh()->stored_path)->toBeNull()
        ->and($contacts->refresh()->stored_path)->toBeNull()
        ->and($org->refresh()->name)->toBe('Hôtel du Lac');
});

it('clears the simulation lines when the 30-day purge runs', function () {
    $batch = uploadImport("Nom;Statut\nDurand;Inconnu\n", ImportTarget::People);
    simulateImport($batch);
    expect($batch->refresh()->simulation['error_rows'])->not->toBe([]);

    $this->travel(31)->days();
    ImportFiles::purgeExpired();

    $simulation = $batch->refresh()->simulation;
    expect($simulation)->not->toHaveKey('error_rows')->not->toHaveKey('other_rows')
        ->and($simulation)->toHaveKey('total');
});

it('refuses to anonymise while an import is running', function () {
    $person = makePerson('Durand');
    $batch = uploadImport("Nom\nMartin\n", ImportTarget::People);
    $batch->forceFill(['status' => ImportBatch::RUNNING])->save();

    expect(fn () => anonymise($person))->toThrow(AnonymisationPostponed::class);
    expect($person->refresh()->anonymised_at)->toBeNull();
});

it('« Anonymiser maintenant » needs crm.gdpr and the name typed', function () {
    $person = makePerson('Durand', ['first_name' => 'Claire']);
    GdprPending::query()->create(['person_id' => $person->id, 'listed_at' => now()]);

    $this->actingAs(commercial())->post("/crm/people/{$person->id}/anonymise", ['confirmation' => 'Claire Durand'])->assertForbidden();

    $direction = direction(twoFactor: true);
    $this->actingAs($direction)->post("/crm/people/{$person->id}/anonymise", ['confirmation' => 'Durand'])->assertSessionHasErrors('confirmation');
    expect($person->refresh()->anonymised_at)->toBeNull();

    $this->actingAs($direction)->post("/crm/people/{$person->id}/anonymise", ['confirmation' => ' claire durand '])
        ->assertRedirect("/crm/people/{$person->id}");
    expect($person->refresh()->anonymised_at)->not->toBeNull()
        ->and(GdprPending::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('event', 'gdpr.anonymised')->sole()->user_id)->toBe($direction->id);
});

it('locks an anonymised fiche: no edit, no note, no attachment, no merge back of data', function () {
    $person = makePerson('Durand');
    anonymise($person);
    $admin = admin(twoFactor: true);

    $this->actingAs($admin)->get("/crm/people/{$person->id}/edit")->assertForbidden();
    $this->actingAs($admin)->put("/crm/people/{$person->id}", ['last_name' => 'Durand'])->assertForbidden();
    $this->actingAs($admin)->post("/crm/people/{$person->id}/notes", ['body' => 'x'])->assertForbidden();
    $this->actingAs($admin)->post("/crm/people/{$person->id}/consents", ['channel' => 'email', 'granted' => true])->assertForbidden();
    $this->actingAs($admin)->get("/crm/people/{$person->id}")->assertInertia(fn ($page) => $page
        ->where('person.anonymised', true)->where('can.update', false)->where('can.anonymise', false));
    expect($person->refresh()->last_name)->toBe('Anonyme');
});

it('never takes contact data again on the links of an anonymised person', function () {
    $org = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $other = Organisation::query()->create(['name' => 'Garage']);
    $person = makePerson('Durand');
    $link = affiliate($person, $org);
    anonymise($person);

    $this->put("/crm/affiliations/{$link->id}", ['work_email' => 'claire@example.test'])->assertSessionHasErrors('work_email');
    $this->put("/crm/affiliations/{$link->id}", ['ended_on' => now()->toDateString()])->assertSessionHasNoErrors();
    $this->post("/crm/organisations/{$other->id}/affiliations", ['person_id' => $person->id])->assertSessionHasErrors('person_id');
    expect($link->refresh()->work_email)->toBeNull()->and($link->ended_on)->not->toBeNull();
});
