<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Users\User;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Import\ImportRow;
use App\Modules\Crm\Import\ImportTarget;
use App\Modules\Crm\Import\RowImporter;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Timeline\Note;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    fakeImportDisk();
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->colleague = commercial();
    // A « Ses fiches » commercial: he sees and modifies only his own fiches.
    $this->me = userWithPermissions([Permissions::FICHES_VIEW, Permissions::FICHES_UPDATE, Permissions::IMPORT]);
    $role = $this->me->roles()->firstOrFail();
    foreach ([Permissions::FICHES_VIEW, Permissions::FICHES_UPDATE] as $permission) {
        DB::table(Scopes::TABLE)->insert(['tenant_id' => tenant()->id, 'role_id' => $role->id, 'permission' => $permission, 'scope' => AccessScope::Own->value]);
    }
    Scopes::forget();
    $this->actingAs($this->me);
});

function isOwnedBy(Organisation|Person $fiche, User $user): Organisation|Person
{
    DB::table($fiche->getTable())->where('id', $fiche->id)->update(['created_by' => $user->id, 'responsible_user_id' => $user->id]);

    return $fiche->fresh();
}

it('skips a row matching (siret, merge) a fiche outside the import user scope', function () {
    $theirs = isOwnedBy(Organisation::query()->create(['name' => 'Hôtel du Lac', 'siret' => '73282932000074']), $this->colleague);

    $batch = runImport("Raison sociale;SIRET;Ville\nHôtel du Lac SARL;73282932000074;Annecy\n", ImportTarget::Organisations, ['strategy' => 'merge']);

    expect($batch->rows()->value('status'))->toBe(ImportRow::SKIPPED)
        ->and(rowMessages($batch, 2))->toBe([RowImporter::OUT_OF_SCOPE])
        ->and($theirs->fresh()->city)->toBeNull()
        ->and(Organisation::query()->count())->toBe(1);
});

it('completes a matching fiche within the import user scope', function () {
    $mine = isOwnedBy(Organisation::query()->create(['name' => 'Hôtel du Lac', 'siret' => '73282932000074']), $this->me);

    $batch = runImport("Raison sociale;SIRET;Ville\nHôtel du Lac SARL;73282932000074;Annecy\n", ImportTarget::Organisations, ['strategy' => 'merge']);

    expect($batch->rows()->value('status'))->toBe(ImportRow::UPDATED)
        ->and($mine->fresh()->city)->toBe('Annecy')
        ->and(Organisation::query()->count())->toBe(1);
});

it('skips a person matching by e-mail or a fiche matching by source id outside the scope', function () {
    $paul = isOwnedBy(makePerson('Martin', ['email' => 'paul@exemple.fr']), $this->colleague);
    $batch = runImport("Nom;Prénom;E-mail;Ville\nMartin;Paul;paul@exemple.fr;Pau\n", ImportTarget::People, ['strategy' => 'merge']);
    expect($batch->rows()->value('status'))->toBe(ImportRow::SKIPPED)
        ->and(rowMessages($batch, 2))->toBe([RowImporter::OUT_OF_SCOPE])
        ->and($paul->fresh()->city)->toBeNull();

    $garage = Organisation::query()->create(['name' => 'Garage Martin']);
    $garage->forceFill(['source_system' => 'formevol', 'source_id' => 'C1'])->save();
    isOwnedBy($garage, $this->colleague);
    $again = runImport("Identifiant;Raison sociale;Ville\nC1;Garage Martin;Tarbes\n", ImportTarget::Organisations, ['source_system' => 'formevol']);
    expect($again->rows()->value('status'))->toBe(ImportRow::SKIPPED)
        ->and(rowMessages($again, 2))->toBe([RowImporter::OUT_OF_SCOPE])
        ->and($garage->fresh()->city)->toBeNull();
});

it('never attaches an imported note to a fiche outside the scope', function () {
    isOwnedBy(Organisation::query()->create(['name' => 'Hôtel du Lac', 'siret' => '73282932000074']), $this->colleague);

    $batch = runImport("SIRET;Compte rendu\n73282932000074;Appel\n", ImportTarget::Notes);

    expect($batch->rows()->value('status'))->toBe(ImportRow::SKIPPED)
        ->and(rowMessages($batch, 2))->toBe([RowImporter::OUT_OF_SCOPE])
        ->and(Note::query()->count())->toBe(0);
});

it('shows the out of scope skip in the simulation', function () {
    isOwnedBy(Organisation::query()->create(['name' => 'Hôtel du Lac', 'siret' => '73282932000074']), $this->colleague);

    $batch = uploadImport("Raison sociale;SIRET;Ville\nHôtel du Lac SARL;73282932000074;Annecy\n", ImportTarget::Organisations, ['strategy' => 'merge']);
    $report = simulateImport($batch);

    expect($report->toSkip)->toBe(1)
        ->and($report->rows[0]['status'])->toBe(ImportRow::SKIPPED)
        ->and($report->rows[0]['messages'])->toBe([RowImporter::OUT_OF_SCOPE]);
});
