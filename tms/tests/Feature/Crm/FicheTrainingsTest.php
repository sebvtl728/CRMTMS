<?php

use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\TrainingUsage;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Gdpr\PersonDataExport;
use App\Modules\Crm\Interests\FicheTraining;
use App\Modules\Crm\Interests\FicheTrainings;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Support\DasLinks;
use App\Modules\Crm\Support\LinkedData;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/**
 * @param  array<string, mixed>  $attributes
 */
function ftTraining(string $title, string $das = 'Hôtellerie-restauration', array $attributes = []): Training
{
    return Training::query()->create(['title' => $title, 'das_id' => crmItem(ReferenceList::Das, $das)->id, ...$attributes]);
}

/**
 * @return list<string>
 */
function ftIds(Organisation|Person $fiche): array
{
    return FicheTraining::query()
        ->where($fiche instanceof Organisation ? 'organisation_id' : 'person_id', $fiche->id)
        ->orderBy('training_id')->pluck('training_id')->all();
}

/**
 * @param  list<string>  $ids
 * @return list<string>
 */
function ftSorted(array $ids): array
{
    sort($ids);

    return $ids;
}

/**
 * @return list<list<string>>
 */
function ftRows(TestResponse $response): array
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $reader = new Reader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($v) => (string) $v, $row->toArray());
        }
        break;
    }
    $reader->close();
    unlink($path);

    return $rows;
}

it('sets the interest trainings of an entreprise and journals the change on its history', function () {
    $this->actingAs(commercial());
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $haccp = ftTraining('HACCP');
    $hygiene = ftTraining('Hygiène');

    $this->from("/crm/organisations/{$organisation->id}")
        ->put("/crm/organisations/{$organisation->id}/trainings", ['training_ids' => [$haccp->id, $hygiene->id]])
        ->assertRedirect("/crm/organisations/{$organisation->id}")->assertSessionHasNoErrors();

    expect(ftIds($organisation))->toBe(ftSorted([$haccp->id, $hygiene->id]));
    $row = FicheTraining::query()->where('training_id', $haccp->id)->sole();
    expect($row->person_id)->toBeNull()->and($row->created_by)->toBe(auth()->id());

    $log = AuditLog::query()->where('event', 'crm.organisation_trainings_changed')->sole();
    expect($log->auditable_id)->toBe($organisation->id)
        ->and($log->old_values)->toBe(['training_ids' => []])
        ->and($log->new_values)->toBe(['training_ids' => ftSorted([$haccp->id, $hygiene->id])]);

    $this->getJson("/crm/organisations/{$organisation->id}/timeline")->assertOk()
        ->assertJsonFragment(['title' => "Formations d'intérêt modifiées"]);

    // Removing one keeps the other; an unchanged list journals nothing.
    $this->put("/crm/organisations/{$organisation->id}/trainings", ['training_ids' => [$hygiene->id]])->assertSessionHasNoErrors();
    expect(ftIds($organisation))->toBe([$hygiene->id]);
    $this->put("/crm/organisations/{$organisation->id}/trainings", ['training_ids' => [$hygiene->id]])->assertSessionHasNoErrors();
    expect(AuditLog::query()->where('event', 'crm.organisation_trainings_changed')->count())->toBe(2);

    $this->put("/crm/organisations/{$organisation->id}/trainings", ['training_ids' => []])->assertSessionHasNoErrors();
    expect(ftIds($organisation))->toBe([]);
});

it('shows the interest trainings on the fiche, with the choices grouped by DAS', function () {
    $this->actingAs(commercial());
    $person = makePerson('Durand');
    app(DasLinks::class)->sync($person, [crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id]);
    $haccp = ftTraining('HACCP', 'Hôtellerie-restauration', ['code' => 'H1']);
    $excel = ftTraining('Excel', 'Compétences transversales');
    $old = ftTraining('Ancienne');
    ftTraining('Brouillon', 'Métiers supports', ['is_active' => false]);
    app(FicheTrainings::class)->sync($person, [$haccp->id, $old->id]);
    $old->update(['is_active' => false]);

    $this->get("/crm/people/{$person->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('interestTrainings', 2)
        ->where('interestTrainings.0.title', 'Ancienne')
        ->where('interestTrainings.0.is_active', false)
        ->where('interestTrainings.1.title', 'HACCP')
        ->where('interestTrainings.1.code', 'H1')
        ->where('interestTrainings.1.das', 'Hôtellerie-restauration')
        ->where('ficheDasIds', [crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id])
        // Active trainings, plus the inactive one the fiche keeps; never another inactive one.
        ->where('trainingOptions', fn ($options) => collect($options)->pluck('label')->sort()->values()->all() === ['Ancienne (inactive)', 'Excel', 'HACCP (H1)'])
        ->where('trainingOptions', fn ($options) => collect($options)->firstWhere('value', $excel->id)['das'] === 'Compétences transversales')
        ->where('can.update', true));
});

it('sets the interest trainings of a person', function () {
    $this->actingAs(assistant());
    $person = makePerson('Durand');
    $haccp = ftTraining('HACCP');

    $this->put("/crm/people/{$person->id}/trainings", ['training_ids' => [$haccp->id]])->assertSessionHasNoErrors();

    expect(ftIds($person))->toBe([$haccp->id])
        ->and(AuditLog::query()->where('event', 'crm.person_trainings_changed')->where('auditable_id', $person->id)->exists())->toBeTrue();
});

it('refuses an inactive, unknown or repeated training, and more than 50', function () {
    $this->actingAs(commercial());
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $kept = ftTraining('Gardée');
    app(FicheTrainings::class)->sync($organisation, [$kept->id]);
    $kept->update(['is_active' => false]);
    $inactive = ftTraining('Inactive', 'Hôtellerie-restauration', ['is_active' => false]);
    $url = "/crm/organisations/{$organisation->id}/trainings";

    $this->put($url, ['training_ids' => [$inactive->id]])->assertSessionHasErrors(['training_ids.0' => 'Cette formation est désactivée : choisissez-en une autre.']);
    $this->put($url, ['training_ids' => ['0193a6b2-0000-7000-8000-000000000000']])->assertSessionHasErrors(['training_ids.0' => "Cette formation n'existe pas."]);
    $this->put($url, ['training_ids' => [$kept->id, $kept->id]])->assertSessionHasErrors('training_ids.1');
    $this->put($url, ['training_ids' => 'x'])->assertSessionHasErrors('training_ids');

    $many = collect(range(1, 51))->map(fn (int $i) => ftTraining("Formation {$i}")->id)->all();
    $this->put($url, ['training_ids' => $many])->assertSessionHasErrors(['training_ids' => "Indiquez au plus 50 formations d'intérêt."]);

    // A training the fiche already has stays, even inactive.
    $this->put($url, ['training_ids' => [$kept->id]])->assertSessionHasNoErrors();
    expect(ftIds($organisation))->toBe([$kept->id]);
});

it('needs the right to edit the fiche, and refuses a trashed or invisible fiche', function () {
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $haccp = ftTraining('HACCP');
    $url = "/crm/organisations/{$organisation->id}/trainings";

    $reader = userWithRole(Role::ResponsableDas);
    $this->actingAs($reader)->put($url, ['training_ids' => [$haccp->id]])->assertNotFound();
    $this->actingAs(direction(twoFactor: true))->put($url, ['training_ids' => [$haccp->id]])->assertForbidden();

    $this->actingAs(commercial());
    $organisation->delete();
    $this->put($url, ['training_ids' => [$haccp->id]])->assertNotFound();
    expect(ftIds($organisation))->toBe([]);
});

it('lets a Responsable DAS edit the fiches of his DAS only', function () {
    $das = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $user = userWithRole(Role::ResponsableDas);
    followDas($user, $das);
    $mine = Organisation::query()->create(['name' => 'Le Bistrot']);
    app(DasLinks::class)->sync($mine, [$das->id]);
    $other = Organisation::query()->create(['name' => 'Ailleurs']);
    $haccp = ftTraining('HACCP');

    $this->actingAs($user);
    $canUpdate = $user->can('update', $mine);
    $this->put("/crm/organisations/{$mine->id}/trainings", ['training_ids' => [$haccp->id]])
        ->assertStatus($canUpdate ? 302 : 403);
    $this->put("/crm/organisations/{$other->id}/trainings", ['training_ids' => [$haccp->id]])->assertNotFound();
    expect(ftIds($other))->toBe([]);
});

it('keeps a training of interest from being deleted from the catalogue', function () {
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $haccp = ftTraining('HACCP');
    expect(TrainingUsage::isUsed($haccp->id))->toBeFalse();

    app(FicheTrainings::class)->sync($organisation, [$haccp->id]);

    expect(TrainingUsage::isUsed($haccp->id))->toBeTrue()
        ->and(LinkedData::any($organisation))->toBeTrue();
});

it('moves the interest trainings of the absorbed fiche to the kept one, without duplicates', function () {
    $this->actingAs(commercial());
    $keep = Organisation::query()->create(['name' => 'Le Bistrot']);
    $drop = Organisation::query()->create(['name' => 'Le Bistrot (doublon)']);
    $haccp = ftTraining('HACCP');
    $hygiene = ftTraining('Hygiène');
    $vins = ftTraining('Vins');
    app(FicheTrainings::class)->sync($keep, [$haccp->id, $hygiene->id]);
    app(FicheTrainings::class)->sync($drop, [$hygiene->id, $vins->id]);

    $this->post('/crm/merge/organisation', ['keep' => $keep->id, 'drop' => $drop->id])->assertRedirect();

    expect(ftIds($keep))->toBe(ftSorted([$haccp->id, $hygiene->id, $vins->id]))
        ->and(ftIds($drop))->toBe([]);
    $log = AuditLog::query()->where('event', 'crm.organisation_trainings_changed')->where('auditable_id', $keep->id)->latest('created_at')->latest('id')->first();
    expect($log?->new_values)->toBe(['training_ids' => ftSorted([$haccp->id, $hygiene->id, $vins->id])]);
});

it('merges the interest trainings of two people', function () {
    $this->actingAs(commercial());
    $keep = makePerson('Durand');
    $drop = makePerson('Durand');
    $haccp = ftTraining('HACCP');
    app(FicheTrainings::class)->sync($drop, [$haccp->id]);

    $this->post('/crm/merge/person', ['keep' => $keep->id, 'drop' => $drop->id])->assertRedirect();

    expect(ftIds($keep))->toBe([$haccp->id])->and(ftIds($drop))->toBe([]);
});

it('deletes the interest trainings of an anonymised person and lists them in her data export', function () {
    $person = makePerson('Durand');
    $haccp = ftTraining('HACCP', 'Hôtellerie-restauration', ['code' => 'H1']);
    app(FicheTrainings::class)->sync($person, [$haccp->id]);

    expect(PersonDataExport::for($person)['formations_d_interet'])->toBe([
        ['formation' => 'HACCP', 'code' => 'H1', 'das' => 'Hôtellerie-restauration', 'ajoutee_le' => FicheTraining::query()->sole()->created_at?->format(DateTimeInterface::ATOM)],
    ]);

    app(Anonymiser::class)->anonymise($person, 'request');

    expect(ftIds($person))->toBe([])
        ->and(PersonDataExport::for($person->refresh())['formations_d_interet'])->toBe([]);

    // An anonymised fiche is never filled again.
    $this->actingAs(commercial())->put("/crm/people/{$person->id}/trainings", ['training_ids' => [$haccp->id]])->assertForbidden();
});

it('filters both lists and their exports on a training of interest', function () {
    $this->actingAs(commercial());
    $haccp = ftTraining('HACCP');
    $hygiene = ftTraining('Hygiène');
    $bistrot = Organisation::query()->create(['name' => 'Le Bistrot']);
    Organisation::query()->create(['name' => 'Autre']);
    $durand = makePerson('Durand');
    makePerson('Martin');
    app(FicheTrainings::class)->sync($bistrot, [$haccp->id, $hygiene->id]);
    app(FicheTrainings::class)->sync($durand, [$haccp->id]);

    $this->get("/crm/organisations?training={$haccp->id}")->assertInertia(fn (Assert $page) => $page
        ->has('organisations.data', 1)
        ->where('organisations.data.0.name', 'Le Bistrot')
        ->where('filters.training', $haccp->id)
        ->where('options.training', fn ($options) => collect($options)->pluck('label')->all() === ['HACCP', 'Hygiène']));
    $this->get("/crm/people?training={$haccp->id}")->assertInertia(fn (Assert $page) => $page
        ->has('people.data', 1)
        ->where('people.data.0.last_name', 'Durand')
        ->where('filters.training', $haccp->id));

    $rows = ftRows($this->get("/crm/organisations/export?columns[]=name&columns[]=interest_trainings&training={$haccp->id}"));
    expect($rows)->toBe([['Nom', "Formations d'intérêt"], ['Le Bistrot', 'HACCP ; Hygiène']]);
    $rows = ftRows($this->get('/crm/people/export?columns[]=last_name&columns[]=interest_trainings'));
    expect($rows)->toBe([['Nom', "Formations d'intérêt"], ['Durand', 'HACCP'], ['Martin', '']]);
});

it('proposes the active interest trainings of the fiche on a new opportunity', function () {
    $this->actingAs(commercial());
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $haccp = ftTraining('HACCP');
    $old = ftTraining('Ancienne');
    app(FicheTrainings::class)->sync($organisation, [$haccp->id, $old->id]);
    $old->update(['is_active' => false]);

    $this->get("/crm/opportunities/create?organisation={$organisation->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('defaults.training_lines', [['training_id' => $haccp->id, 'quantity' => '1']]));

    $this->get('/crm/opportunities/create')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('defaults.training_lines', []));
});
