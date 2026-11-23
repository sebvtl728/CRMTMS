<?php

use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00', 'Europe/Paris'));
});

function nextActionOn(Opportunity $opportunity, string $type, string $subject, ?string $due, ?string $done = null): Activity
{
    return Activity::query()->create([
        'opportunity_id' => $opportunity->id,
        'organisation_id' => $opportunity->organisation_id,
        'type_id' => crmItem(ReferenceList::ActionType, $type)->id,
        'subject' => $subject,
        'due_at' => $due === null ? null : CarbonImmutable::parse($due, 'Europe/Paris'),
        'done_at' => $done === null ? null : CarbonImmutable::parse($done, 'Europe/Paris'),
    ]);
}

it('shows the earliest dated action still to do on each row of the opportunity list', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $opportunity = makeOpportunity($org, ['title' => 'Formation HACCP']);
    $quiet = makeOpportunity($org, ['title' => 'Sans action']);

    nextActionOn($opportunity, 'Appel', 'Déjà fait', '2026-11-10 09:00', '2026-11-10 09:30');
    nextActionOn($opportunity, 'Rappel', 'Plus tard', '2026-11-20 14:00');
    nextActionOn($opportunity, 'Appel', 'Relancer le gérant', '2026-11-12 09:00');

    $rows = collect($this->get('/crm/opportunities')->assertOk()->original->getData()['page']['props']['opportunities']['data'])
        ->keyBy('title');

    expect($rows['Formation HACCP']['next_action'])->toBe([
        'type' => 'Appel',
        'subject' => 'Relancer le gérant',
        'due_at' => CarbonImmutable::parse('2026-11-12 09:00', 'Europe/Paris')->toIso8601String(),
        'overdue' => true,
    ])->and($rows['Sans action']['next_action'])->toBeNull();
});

it('shows the next action on the pipeline cards, not late when due today', function () {
    $this->actingAs(commercial());
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $opportunity = makeOpportunity($org, ['title' => 'Formation HACCP']);
    nextActionOn($opportunity, 'Rappel', 'Rappeler', '2026-11-14 08:00');

    $cards = collect($this->get('/crm/opportunities/kanban')->assertOk()->original->getData()['page']['props']['columns'])
        ->flatMap(fn (array $c) => $c['cards'])->keyBy('title');

    expect($cards['Formation HACCP']['next_action'])->toMatchArray(['type' => 'Rappel', 'subject' => 'Rappeler', 'overdue' => false]);
});

it('hides the next action from a user who may not see actions', function () {
    $org = Organisation::query()->create(['name' => 'Le Bistrot']);
    $opportunity = makeOpportunity($org, ['title' => 'Formation HACCP']);
    nextActionOn($opportunity, 'Appel', 'Relancer', '2026-11-20 09:00');

    $this->actingAs(userWithPermissions(['crm.opportunities.view']));
    $rows = collect($this->get('/crm/opportunities')->assertOk()->original->getData()['page']['props']['opportunities']['data']);

    expect($rows->firstWhere('title', 'Formation HACCP')['next_action'])->toBeNull();
});
