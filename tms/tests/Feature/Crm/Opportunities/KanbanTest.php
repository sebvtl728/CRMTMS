<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Opportunities\OpportunityStage;
use App\Modules\Crm\Organisations\Organisation;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/**
 * @return array<string, array<string, mixed>> column label => column
 */
function kanbanColumns(mixed $response): array
{
    return collect($response->assertOk()->original->getData()['page']['props']['columns'])
        ->keyBy(fn (array $c) => $c['stage']['label'])->all();
}

it('shows the active stages as columns with exact count, amount and weighted totals', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Paris'));
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $stages = app(OpportunityStage::class);

    makeOpportunity($org, ['title' => 'A', 'amount' => 1000]);                 // Nouveau 10 % → 100
    makeOpportunity($org, ['title' => 'B', 'amount' => '500.50']);             // Nouveau 10 % → 50.05
    makeOpportunity($org, ['title' => 'C']);                                   // Nouveau, no amount
    $proposal = makeOpportunity($org, ['title' => 'D', 'amount' => 2000]);
    $stages->move($proposal, pipelineStage('Proposition'), null, null, null);  // 60 % → 1200
    $wonRecently = makeOpportunity($org, ['title' => 'E', 'amount' => 3000]);
    $stages->move($wonRecently, pipelineStage('Gagné'), null, null, CarbonImmutable::parse('2026-09-01'));
    $wonLongAgo = makeOpportunity($org, ['title' => 'F', 'amount' => 9999]);
    $stages->move($wonLongAgo, pipelineStage('Gagné'), null, null, CarbonImmutable::parse('2026-08-15'));
    $lost = makeOpportunity($org, ['title' => 'G', 'amount' => 700]);
    $stages->move($lost, pipelineStage('Perdu'), crmItem(ReferenceList::LossReason, 'Prix')->id, null, null);

    // Lost 91 days ago: out of the column.
    $this->travelTo(CarbonImmutable::parse('2026-08-14 10:00', 'Europe/Paris'));
    $oldLost = makeOpportunity($org, ['title' => 'H', 'amount' => 800]);
    $stages->move($oldLost, pipelineStage('Perdu'), crmItem(ReferenceList::LossReason, 'Prix')->id, null, null);
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Paris'));

    $response = $this->get('/crm/opportunities/kanban');
    $columns = kanbanColumns($response);

    expect(array_keys($columns))->toBe(['Nouveau', 'Contacté', 'Étude', 'Proposition', 'Négociation', 'Gagné', 'Perdu'])
        ->and($columns['Nouveau'])->toMatchArray(['count' => 3, 'amount' => '1500.50', 'weighted_amount' => '150.05'])
        ->and(collect($columns['Nouveau']['cards'])->pluck('title')->sort()->values()->all())->toBe(['A', 'B', 'C'])
        ->and($columns['Contacté'])->toMatchArray(['count' => 0, 'amount' => '0.00', 'weighted_amount' => '0.00', 'cards' => []])
        ->and($columns['Proposition'])->toMatchArray(['count' => 1, 'amount' => '2000.00', 'weighted_amount' => '1200.00'])
        ->and($columns['Gagné'])->toMatchArray(['count' => 1, 'amount' => '3000.00', 'weighted_amount' => '3000.00'])
        ->and(collect($columns['Gagné']['cards'])->pluck('title')->all())->toBe(['E'])
        ->and($columns['Perdu'])->toMatchArray(['count' => 1, 'amount' => '700.00', 'weighted_amount' => '0.00'])
        ->and(collect($columns['Perdu']['cards'])->pluck('title')->all())->toBe(['G'])
        ->and($columns['Gagné']['stage']['outcome'])->toBe('won');

    $response->assertInertia(fn ($page) => $page->component('crm/opportunities/kanban')
        ->has('lossReasons')->has('stages', 7)->where('can.update', true)->where('closedDays', 90));
});

it('leaves out inactive stages', function () {
    $this->actingAs(commercial());
    pipelineStage('Étude')->forceFill(['is_active' => false])->save();

    expect(array_keys(kanbanColumns($this->get('/crm/opportunities/kanban'))))->not->toContain('Étude');
});

it('applies the same filters and visibility as the list', function () {
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $seller = commercial();
    makeOpportunity($org, ['title' => 'Cuisine', 'das_id' => $mine->id, 'amount' => 100, 'responsible_user_id' => $seller->id]);
    makeOpportunity($org, ['title' => 'Bureautique', 'das_id' => $other->id, 'amount' => 200]);
    $trashedFiche = Organisation::query()->create(['name' => 'Fermé']);
    makeOpportunity($trashedFiche, ['title' => 'Fantôme', 'das_id' => $mine->id]);
    $trashedFiche->delete();

    $titles = fn (string $query) => collect(kanbanColumns($this->get('/crm/opportunities/kanban'.$query)))
        ->flatMap(fn (array $c) => collect($c['cards'])->pluck('title'))->sort()->values()->all();
    $listTitles = fn (string $query) => collect($this->get('/crm/opportunities'.$query)->original->getData()['page']['props']['opportunities']['data'])
        ->pluck('title')->sort()->values()->all();

    $this->actingAs($seller);
    foreach (['', '?das='.$other->id, '?q=cuis', '?mine=1', '?target=individual', '?stage='.pipelineStage('Nouveau')->id] as $query) {
        expect($titles($query))->toBe($listTitles($query));
    }
    expect($titles('?das='.$other->id))->toBe(['Bureautique'])
        ->and(kanbanColumns($this->get('/crm/opportunities/kanban?das='.$other->id))['Nouveau']['amount'])->toBe('200.00');
    $this->get('/crm/opportunities/kanban?das='.$mine->id)
        ->assertInertia(fn ($page) => $page->where('filters.das', $mine->id));

    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $mine);
    $this->actingAs($responsable);
    expect($titles(''))->toBe(['Cuisine']);
    $this->get('/crm/opportunities/kanban')->assertInertia(fn ($page) => $page->where('can.update', false));
});

it('drag to lost through the endpoint without a reason is refused and the card stays', function () {
    $this->actingAs(commercial());
    $opportunity = makeOpportunity(Organisation::query()->create(['name' => 'Le Bistrot']));

    $this->from('/crm/opportunities/kanban')->post("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => pipelineStage('Perdu')->id])
        ->assertRedirect('/crm/opportunities/kanban')
        ->assertSessionHasErrors(['loss_reason_id' => 'Le motif de perte est obligatoire.']);
    $this->postJson("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => pipelineStage('Perdu')->id])->assertStatus(422);

    expect(collect(kanbanColumns($this->get('/crm/opportunities/kanban'))['Nouveau']['cards'])->pluck('id')->all())->toBe([$opportunity->id]);

    // A move to an open stage goes back to the kanban.
    $this->from('/crm/opportunities/kanban')->post("/crm/opportunities/{$opportunity->id}/stage", ['stage_id' => pipelineStage('Étude')->id])
        ->assertRedirect('/crm/opportunities/kanban')->assertSessionHasNoErrors();
    expect(kanbanColumns($this->get('/crm/opportunities/kanban'))['Étude']['count'])->toBe(1);
});

it('needs to be signed in', function () {
    $this->get('/crm/opportunities/kanban')->assertRedirect('/login');
});
