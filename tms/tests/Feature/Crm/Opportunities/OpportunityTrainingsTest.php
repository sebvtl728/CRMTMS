<?php

use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Training;
use App\Modules\Catalogue\TrainingUsage;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\OpportunityTraining;
use App\Modules\Crm\Opportunities\OpportunityTrainings;
use App\Modules\Crm\Organisations\Organisation;
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
function otTraining(string $title, array $attributes = []): Training
{
    return Training::query()->create([
        'title' => $title,
        'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        ...$attributes,
    ]);
}

/**
 * @param  list<array{0: Training, 1: string}>  $lines
 * @return list<array{training_id: string, quantity: string}>
 */
function otLines(array $lines): array
{
    return array_map(fn (array $l) => ['training_id' => $l[0]->id, 'quantity' => $l[1]], $lines);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function otPayload(Opportunity $o, array $overrides = []): array
{
    return [
        'title' => $o->title,
        'das_id' => $o->das_id,
        'probability' => $o->probability,
        ...$overrides,
    ];
}

it('stores the trainings of an entreprise opportunity and proposes their amount without saving it', function () {
    $this->actingAs(commercial());
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $haccp = otTraining('HACCP', ['price_company' => '1200.00', 'price_individual' => '900.00']);
    $hygiene = otTraining('Hygiène', ['price_company' => '800.00']);

    $this->post('/crm/opportunities', [
        'organisation_id' => $organisation->id,
        'title' => 'Plan de formation',
        'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'stage_id' => pipelineStage('Nouveau')->id,
        'training_lines' => otLines([[$haccp, '3'], [$hygiene, '1']]),
    ])->assertSessionHasNoErrors();

    $opportunity = Opportunity::query()->sole();
    expect(OpportunityTraining::query()->orderBy('position')->get()->map(fn ($l) => [$l->training_id, $l->quantity, $l->position])->all())
        ->toBe([[$haccp->id, '3.00', 0], [$hygiene->id, '1.00', 1]])
        ->and(OpportunityTrainings::estimate($opportunity))->toBe(440000);

    $this->postJson("/crm/opportunities/{$opportunity->id}/estimate")->assertOk()
        ->assertExactJson(['amount_cents' => 440000, 'amount_display' => '4 400,00 €', 'missing' => []]);
    expect($opportunity->refresh()->amount)->toBeNull();

    // Lines typed on the form, not saved yet: estimated as they are, nothing written.
    $this->postJson("/crm/opportunities/{$opportunity->id}/estimate", ['training_lines' => otLines([[$haccp, '1,5']])])->assertOk()
        ->assertJson(['amount_cents' => 180000, 'amount_display' => '1 800,00 €']);
    expect(OpportunityTraining::query()->count())->toBe(2);

    $this->get("/crm/opportunities/{$opportunity->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('training_summary.lines', 2)
            ->where('training_summary.lines.0.title', 'HACCP')
            ->where('training_summary.lines.0.quantity', '3.00')
            ->where('training_summary.lines.0.price_cents', 120000)
            ->where('training_summary.estimate.amount_cents', 440000)
            ->where('training_summary.estimate.missing', []));
});

it('uses the particulier price for a particulier and warns of a missing price', function () {
    $this->actingAs(commercial());
    $person = makePerson('Martin', ['is_individual' => true]);
    $haccp = otTraining('HACCP', ['price_company' => '1200.00', 'price_individual' => '900.00']);
    $sansTarif = otTraining('Sans tarif', ['price_company' => '500.00']);
    $opportunity = makeOpportunity($person);

    $this->put("/crm/opportunities/{$opportunity->id}", otPayload($opportunity, ['training_lines' => otLines([[$haccp, '2']])]))->assertSessionHasNoErrors();
    $this->postJson("/crm/opportunities/{$opportunity->id}/estimate")->assertOk()
        ->assertExactJson(['amount_cents' => 180000, 'amount_display' => '1 800,00 €', 'missing' => []]);

    $this->put("/crm/opportunities/{$opportunity->id}", otPayload($opportunity, ['training_lines' => otLines([[$haccp, '2'], [$sansTarif, '1']])]))->assertSessionHasNoErrors();
    $this->postJson("/crm/opportunities/{$opportunity->id}/estimate")->assertOk()
        ->assertExactJson(['amount_cents' => 180000, 'amount_display' => '1 800,00 €', 'missing' => ['Sans tarif']]);

    // Removing every line empties the list.
    $this->put("/crm/opportunities/{$opportunity->id}", otPayload($opportunity, ['training_lines' => []]))->assertSessionHasNoErrors();
    expect(OpportunityTraining::query()->count())->toBe(0);
});

it('keeps an inactive training on the opportunity that has it but refuses to add it', function () {
    $this->actingAs(commercial());
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $haccp = otTraining('HACCP', ['price_company' => '1200.00']);
    $old = otTraining('Ancienne', ['price_company' => '100.00']);
    $opportunity = makeOpportunity($organisation);
    $this->put("/crm/opportunities/{$opportunity->id}", otPayload($opportunity, ['training_lines' => otLines([[$old, '1']])]))->assertSessionHasNoErrors();

    $old->update(['is_active' => false]);

    $this->get("/crm/opportunities/{$opportunity->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('training_summary.lines.0.title', 'Ancienne')->where('training_summary.lines.0.is_active', false));
    $this->get("/crm/opportunities/{$opportunity->id}/edit")->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('opportunity.training_lines.0.training_id', $old->id)
            ->where('options.training', fn ($options) => collect($options)->pluck('label')->all() === ['Ancienne (inactive)', 'HACCP']));

    // Kept (quantity changed) on the opportunity that already has it.
    $this->put("/crm/opportunities/{$opportunity->id}", otPayload($opportunity, ['training_lines' => otLines([[$old, '2'], [$haccp, '1']])]))->assertSessionHasNoErrors();
    expect(OpportunityTraining::query()->where('training_id', $old->id)->value('quantity'))->toBe('2.00');

    // Refused on another opportunity, at creation and on update.
    $other = makeOpportunity($organisation, ['title' => 'Autre']);
    $this->put("/crm/opportunities/{$other->id}", otPayload($other, ['training_lines' => otLines([[$old, '1']])]))
        ->assertSessionHasErrors(['training_lines.0.training_id' => 'Cette formation est désactivée : choisissez-en une autre.']);
    $this->post('/crm/opportunities', [
        'organisation_id' => $organisation->id, 'title' => 'Nouvelle', 'das_id' => $opportunity->das_id,
        'stage_id' => pipelineStage('Nouveau')->id, 'training_lines' => otLines([[$old, '1']]),
    ])->assertSessionHasErrors('training_lines.0.training_id');
    expect(OpportunityTraining::query()->where('opportunity_id', $other->id)->count())->toBe(0);

    $this->get('/crm/opportunities/create?organisation='.$organisation->id)
        ->assertInertia(fn (Assert $page) => $page->where('options.training', fn ($options) => collect($options)->pluck('label')->all() === ['HACCP']));
});

it('refuses to delete a training an opportunity uses, even a deleted opportunity', function () {
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $haccp = otTraining('HACCP');
    $free = otTraining('Libre');
    $opportunity = makeOpportunity($organisation);
    OpportunityTrainings::sync($opportunity, [['training_id' => $haccp->id, 'quantity' => '1']]);
    $opportunity->delete();

    expect(TrainingUsage::isUsed($haccp->id))->toBeTrue()
        ->and(TrainingUsage::isUsed($free->id))->toBeFalse();

    $this->actingAs(admin(twoFactor: true))->delete("/catalogue/{$haccp->id}")
        ->assertSessionHasErrors(['training' => 'Cette formation est utilisée (opportunités, devis, sessions) : désactivez-la plutôt.']);
    expect($haccp->refresh()->trashed())->toBeFalse();
    $this->delete("/catalogue/{$free->id}")->assertSessionHasNoErrors();
});

it('prefills the DAS from the first training when none is chosen', function () {
    $this->actingAs(commercial());
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $supports = crmItem(ReferenceList::Das, 'Métiers supports');
    $bureautique = otTraining('Bureautique', ['das_id' => $supports->id]);

    $this->post('/crm/opportunities', [
        'organisation_id' => $organisation->id, 'title' => 'Excel', 'das_id' => '',
        'stage_id' => pipelineStage('Nouveau')->id, 'training_lines' => otLines([[$bureautique, '1']]),
    ])->assertSessionHasNoErrors();
    expect(Opportunity::query()->sole()->das_id)->toBe($supports->id);

    // Without a training the DAS stays required.
    $this->post('/crm/opportunities', [
        'organisation_id' => $organisation->id, 'title' => 'Sans', 'das_id' => '', 'stage_id' => pipelineStage('Nouveau')->id,
    ])->assertSessionHasErrors('das_id');
});

it('validates the lines in french', function () {
    $this->actingAs(commercial());
    $opportunity = makeOpportunity(Organisation::query()->create(['name' => 'Le Bistrot']));
    $haccp = otTraining('HACCP');
    $foreign = app(TenantContext::class)->run(Tenant::factory()->create(), function () {
        CapFormationReferenceSeeder::run(tenant());

        return otTraining('Étrangère');
    });
    inTenant();

    $put = fn (array $lines) => $this->put("/crm/opportunities/{$opportunity->id}", otPayload($opportunity, ['training_lines' => $lines]));

    $put([['training_id' => $haccp->id, 'quantity' => '0']])->assertSessionHasErrors(['training_lines.0.quantity' => 'La quantité doit être supérieure à 0.']);
    $put([['training_id' => $haccp->id, 'quantity' => 'trois']])->assertSessionHasErrors(['training_lines.0.quantity' => 'Indiquez une quantité, par exemple 1 ou 2,5.']);
    $put([['training_id' => $haccp->id, 'quantity' => '+5']])->assertSessionHasErrors(['training_lines.0.quantity' => 'Indiquez une quantité, par exemple 1 ou 2,5.']);
    $put([['training_id' => $haccp->id, 'quantity' => '.5']])->assertSessionHasErrors(['training_lines.0.quantity' => 'Indiquez une quantité, par exemple 1 ou 2,5.']);
    $put([['training_id' => $haccp->id, 'quantity' => '1,234']])->assertSessionHasErrors('training_lines.0.quantity');
    $put([['training_id' => $haccp->id, 'quantity' => '10000']])->assertSessionHasErrors(['training_lines.0.quantity' => 'La quantité ne peut pas dépasser 9 999,99.']);
    $put([['training_id' => $haccp->id, 'quantity' => '1'], ['training_id' => $haccp->id, 'quantity' => '2']])
        ->assertSessionHasErrors(['training_lines.1.training_id' => 'Cette formation est déjà dans la liste.']);
    $put([['training_id' => $foreign->id, 'quantity' => '1']])->assertSessionHasErrors(['training_lines.0.training_id' => 'Cette formation n\'existe pas.']);
    $put([['training_id' => 'pas-un-uuid', 'quantity' => '1']])->assertSessionHasErrors(['training_lines.0.training_id' => 'Cette formation n\'existe pas.']);
    expect(OpportunityTraining::query()->count())->toBe(0);

    // An empty quantity counts 1; the audit journal records the lines.
    $put([['training_id' => $haccp->id, 'quantity' => '']])->assertSessionHasNoErrors();
    expect(OpportunityTraining::query()->sole()->quantity)->toBe('1.00')
        ->and(AuditLog::query()->where('auditable_type', 'opportunity_training')->where('event', 'created')->count())->toBe(1);
});

it('answers 404 for the estimate of an opportunity of another organisme or out of sight', function () {
    $foreign = app(TenantContext::class)->run(Tenant::factory()->create(), function () {
        CapFormationReferenceSeeder::run(tenant());

        return makeOpportunity(Organisation::query()->create(['name' => 'Autre']));
    });
    inTenant();

    $this->actingAs(commercial())->postJson("/crm/opportunities/{$foreign->id}/estimate")->assertNotFound();
    $this->postJson('/crm/opportunities/pas-un-uuid/estimate')->assertNotFound();

    // A Responsable DAS does not see an opportunity of a DAS he does not follow.
    $hidden = makeOpportunity(Organisation::query()->create(['name' => 'Le Bistrot']));
    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, crmItem(ReferenceList::Das, 'Métiers supports'));
    $this->actingAs($responsable)->postJson("/crm/opportunities/{$hidden->id}/estimate")->assertNotFound();

    // Read-only roles may ask the estimate (it writes nothing).
    $this->actingAs(assistant())->postJson("/crm/opportunities/{$hidden->id}/estimate")->assertOk()->assertJson(['amount_cents' => 0]);
});

it('filters the list by training and exports the trainings', function () {
    $user = commercial();
    $this->actingAs($user);
    $organisation = Organisation::query()->create(['name' => 'Le Bistrot']);
    $haccp = otTraining('HACCP');
    $hygiene = otTraining('Hygiène');
    $alpha = makeOpportunity($organisation, ['title' => 'Alpha']);
    $bravo = makeOpportunity($organisation, ['title' => 'Bravo']);
    makeOpportunity($organisation, ['title' => 'Charlie']);
    OpportunityTrainings::sync($alpha, [['training_id' => $haccp->id, 'quantity' => '1'], ['training_id' => $hygiene->id, 'quantity' => '2']]);
    OpportunityTrainings::sync($bravo, [['training_id' => $hygiene->id, 'quantity' => '1']]);

    $titles = fn (string $query) => collect($this->get('/crm/opportunities?'.$query)->original->getData()['page']['props']['opportunities']['data'])->pluck('title')->sort()->values()->all();
    expect($titles('training='.$haccp->id))->toBe(['Alpha'])
        ->and($titles('training='.$hygiene->id))->toBe(['Alpha', 'Bravo'])
        ->and($titles('training=pas-un-uuid'))->toBe(['Alpha', 'Bravo', 'Charlie']);
    $this->get('/crm/opportunities')->assertInertia(fn (Assert $page) => $page
        ->where('filters.training', null)
        ->where('options.training', fn ($options) => collect($options)->pluck('label')->all() === ['HACCP', 'Hygiène']));
    $kanban = fn (string $query) => collect($this->get('/crm/opportunities/kanban?'.$query)->original->getData()['page']['props']['columns'])
        ->flatMap(fn (array $column) => array_column($column['cards'], 'title'))->sort()->values()->all();
    expect($kanban('training='.$haccp->id))->toBe(['Alpha'])
        ->and($kanban('training='.$hygiene->id))->toBe(['Alpha', 'Bravo'])
        ->and($kanban(''))->toBe(['Alpha', 'Bravo', 'Charlie']);
    $this->get('/crm/opportunities/kanban?training='.$haccp->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('filters.training', $haccp->id));

    $response = $this->get('/crm/opportunities/export?columns[]=title&columns[]=training_titles&sort=created_at');
    $response->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($path.'.xlsx', $response->streamedContent());
    $reader = new Reader;
    $reader->open($path.'.xlsx');
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn ($v) => (string) $v, $row->toArray());
        }
        break;
    }
    $reader->close();
    unlink($path.'.xlsx');
    unlink($path);

    expect($rows)->toBe([['Objet', 'Formations'], ['Alpha', 'HACCP ; Hygiène'], ['Bravo', 'Hygiène'], ['Charlie', '']]);
});
