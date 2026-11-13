<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Affiliations\Affiliation;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Import\ImportBatch;
use App\Modules\Crm\Import\ImportRow;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Import\UndoImport;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Timeline\Note;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->user = commercial();
    $this->actingAs($this->user);
});

it('undoes an import: created fiches go to the trash, updated ones stay', function () {
    $existing = Organisation::query()->create(['name' => 'Hôtel du Lac', 'siret' => '73282932000074']);
    $batch = runImport("Raison sociale;SIRET;Ville\nHôtel du Lac;73282932000074;Annecy\nGarage Martin;;Pau\nCafé des Arts;;Paris\n", ImportTarget::Organisations, ['strategy' => 'merge']);
    expect([$batch->created, $batch->updated])->toBe([2, 1]);

    $this->travel(1)->minutes();
    UndoImport::dispatch($batch->id, $this->user->id);

    expect($batch->refresh()->status)->toBe(ImportBatch::UNDONE)
        ->and($batch->undone_at)->not->toBeNull()
        ->and(Organisation::query()->pluck('name')->all())->toBe(['Hôtel du Lac'])
        ->and(Organisation::onlyTrashed()->orderBy('name')->pluck('name')->all())->toBe(['Café des Arts', 'Garage Martin'])
        // Updated, not created: left as it is (nothing is restored either).
        ->and($existing->refresh()->city)->toBe('Annecy');

    $audit = AuditLog::query()->where('event', 'crm.import_undone')->sole();
    expect($audit->new_values)->toEqualCanonicalizing(['file' => 'fichier.csv', 'removed' => 2, 'kept' => 0])
        ->and($audit->user_id)->toBe($this->user->id)
        ->and(AuditLog::query()->where('event', 'deleted')->where('auditable_type', 'organisation')->count())->toBe(2);
});

it('keeps a fiche modified since the import', function () {
    $batch = runImport("Raison sociale;Ville\nGarage Martin;Pau\nCafé des Arts;Paris\n", ImportTarget::Organisations);

    $this->travel(5)->minutes();
    $garage = Organisation::query()->where('name', 'Garage Martin')->sole();
    $garage->update(['city' => 'Tarbes']);

    UndoImport::dispatch($batch->id);

    expect(Organisation::query()->pluck('name')->all())->toBe(['Garage Martin'])
        ->and(Organisation::onlyTrashed()->pluck('name')->all())->toBe(['Café des Arts'])
        ->and(rowMessages($batch, 2))->toBe([UndoImport::KEPT_MODIFIED])
        ->and($batch->refresh()->options['undo'])->toEqualCanonicalizing(['removed' => 1, 'kept' => 1]);
});

it('undoes contacts with their entreprise', function () {
    $known = Organisation::query()->create(['name' => 'Hôtel Bellevue', 'siret' => '73282932000074']);
    $batch = runImport('contacts.xlsx', ImportTarget::Affiliations);
    expect(Affiliation::query()->count())->toBe(3);

    $this->travel(1)->minutes();
    UndoImport::dispatch($batch->id);

    expect(Person::query()->count())->toBe(0)
        ->and(Affiliation::query()->count())->toBe(0)
        // The entreprise known before the import is kept; the one it created is not.
        ->and(Organisation::query()->pluck('id')->all())->toBe([$known->id])
        ->and($batch->refresh()->status)->toBe(ImportBatch::UNDONE);
});

it('undoes imported notes', function () {
    Organisation::query()->create(['name' => 'Hôtel du Lac', 'siret' => '73282932000074']);
    $batch = runImport("SIRET;Note\n73282932000074;Premier échange\n", ImportTarget::Notes);
    expect(Note::query()->count())->toBe(1);

    UndoImport::dispatch($batch->id);

    expect(Note::query()->count())->toBe(0)
        ->and(Note::onlyTrashed()->count())->toBe(1);
});

it('undoes only a finished import, and only once', function () {
    $batch = uploadImport("Raison sociale\nGarage Martin\n", ImportTarget::Organisations);
    simulateImport($batch);

    UndoImport::dispatch($batch->id);
    expect($batch->refresh()->status)->toBe(ImportBatch::SIMULATED)
        ->and($batch->canUndo())->toBeFalse();

    $batch = runImport("Raison sociale\nCafé des Arts\n", ImportTarget::Organisations);
    UndoImport::dispatch($batch->id);
    UndoImport::dispatch($batch->id);

    expect($batch->refresh()->status)->toBe(ImportBatch::UNDONE)
        ->and(AuditLog::query()->where('event', 'crm.import_undone')->count())->toBe(1);
});

it('re-imports a file after its import was undone', function () {
    $file = "Identifiant;Raison sociale\nC1;Garage Martin\nC2;Café des Arts\n";
    $first = runImport($file, ImportTarget::Organisations, ['source_system' => 'formevol']);
    UndoImport::dispatch($first->id);

    $again = runImport($file, ImportTarget::Organisations, ['source_system' => 'formevol']);

    expect([$again->updated, $again->errors])->toBe([2, 0])
        ->and(Organisation::query()->count())->toBe(2)
        ->and(rowMessages($again, 2))->toContain('Fiche restaurée depuis la corbeille.');
});

it('purges uploaded files and raw values after 30 days', function () {
    $old = runImport("Raison sociale\nGarage Martin\n", ImportTarget::Organisations);
    $old->forceFill(['created_at' => now()->subDays(31)])->save();
    $recent = runImport("Raison sociale\nCafé des Arts\n", ImportTarget::Organisations);
    $oldPath = (string) $old->stored_path;

    Artisan::call('tms:crm:purge-imports');

    expect(Storage::disk('local')->exists($oldPath))->toBeFalse()
        ->and($old->refresh()->stored_path)->toBeNull()
        ->and(DB::table('import_rows')->where('batch_id', $old->id)->value('source'))->toBeNull()
        // The outcome, the report and the undo stay possible.
        ->and($old->rows()->value('status'))->toBe(ImportRow::CREATED)
        ->and(Storage::disk('local')->exists((string) $old->report_path))->toBeTrue()
        ->and($old->canUndo())->toBeTrue()
        ->and(Storage::disk('local')->exists((string) $recent->stored_path))->toBeTrue()
        ->and($recent->stored_path)->toStartWith(TenantStorage::path('imports'));
});

// --- Fix round 1: undo never removes what users built on -------------------

it('keeps an imported fiche that received a note after the import', function () {
    $batch = runImport("Raison sociale\nGarage Martin\nCafé des Arts\n", ImportTarget::Organisations);
    $garage = Organisation::query()->where('name', 'Garage Martin')->sole();
    $note = new Note(['body' => 'Appel de suivi']);
    $note->notable()->associate($garage);
    $note->save();

    UndoImport::dispatch($batch->id);

    expect(Organisation::query()->pluck('name')->all())->toBe(['Garage Martin'])
        ->and($note->refresh()->trashed())->toBeFalse()
        ->and(rowMessages($batch, 2))->toBe([UndoImport::KEPT_LINKED]);
});

it('keeps an imported fiche given a link by a user, and the link survives', function () {
    $batch = runImport('contacts.xlsx', ImportTarget::Affiliations);
    $elodie = Person::query()->where('email', 'elodie.durand@bellevue.fr')->sole();
    $other = Organisation::query()->create(['name' => 'Autre employeur']);
    $userLink = affiliate($elodie, $other, ['job_title' => 'Consultante']);

    UndoImport::dispatch($batch->id);

    expect(Person::query()->pluck('id')->all())->toBe([$elodie->id])
        ->and($userLink->refresh()->trashed())->toBeFalse()
        // The link the import created (to Hôtel Bellevue) is undone.
        ->and(Affiliation::query()->pluck('id')->all())->toBe([$userLink->id])
        ->and(Organisation::query()->pluck('name')->all())->toBe(['Autre employeur']);
});

it('keeps a fiche edited by a user while the import was running', function () {
    $this->travelTo(now()->setDate(2026, 11, 13)->setTime(10, 0));
    $batch = runImport("Raison sociale\nGarage Martin\nCafé des Arts\n", ImportTarget::Organisations);
    // A long run: it ended at 10:00:10; the user saved the fiche at 10:00:05.
    $batch->forceFill(['finished_at' => now()->addSeconds(10)])->save();
    $garage = Organisation::query()->where('name', 'Garage Martin')->sole();
    $garage->update(['city' => 'Pau']);
    DB::table('organisations')->where('id', $garage->id)->update(['updated_at' => now()->addSeconds(5)]);
    $this->travel(1)->minutes();

    UndoImport::dispatch($batch->id);

    expect(Organisation::query()->pluck('name')->all())->toBe(['Garage Martin'])
        ->and(rowMessages($batch, 2))->toBe([UndoImport::KEPT_MODIFIED]);
});

it('keeps a fiche that a later import linked to', function () {
    $first = runImport("Raison sociale;SIRET;Ville\nHôtel Bellevue;73282932000074;Annecy\n", ImportTarget::Organisations);
    $second = runImport('contacts.xlsx', ImportTarget::Affiliations);
    $bellevue = Organisation::query()->where('siret', '73282932000074')->sole();
    expect($bellevue->affiliations()->count())->toBe(2);

    UndoImport::dispatch($first->id);

    expect($bellevue->refresh()->trashed())->toBeFalse()
        ->and($bellevue->affiliations()->count())->toBe(2)
        ->and(rowMessages($first, 2))->toBe([UndoImport::KEPT_LINKED])
        ->and($second->refresh()->status)->toBe(ImportBatch::DONE);
});

it('removes a fiche created then completed by the same import', function () {
    $batch = runImport("Identifiant;Raison sociale;Ville\nC1;Garage Martin;\nC1;Garage Martin;Pau\n", ImportTarget::Organisations, ['source_system' => 'formevol']);
    expect([$batch->created, $batch->updated])->toBe([1, 1]);
    $this->travel(1)->minutes();

    UndoImport::dispatch($batch->id);

    expect(Organisation::query()->count())->toBe(0);
});
