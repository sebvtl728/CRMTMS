<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Opportunities\OpportunityStage;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Permissions;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

/**
 * An action of $responsible on $organisation, due at a Paris time.
 *
 * @param  array<string, mixed>  $attributes
 */
function dashAction(User $responsible, Organisation $organisation, string $type, string $due, array $attributes = []): Activity
{
    return Activity::query()->create([
        'type_id' => crmItem(ReferenceList::ActionType, $type)->id,
        'subject' => $type.' '.$due,
        'due_at' => CarbonImmutable::parse($due, 'Europe/Paris'),
        'organisation_id' => $organisation->id,
        'responsible_user_id' => $responsible->id,
        ...$attributes,
    ]);
}

/**
 * The « commercial » prop of the dashboard.
 *
 * @return array<string, mixed>
 */
function dashCommercial(User $user): ?array
{
    test()->actingAs($user);

    return test()->get('/dashboard')->assertOk()->original->getData()['page']['props']['commercial'];
}

it('shows my follow-ups, the weighted pipeline of the paris month and my next meetings', function () {
    // Saturday 14/11/2026, 10:00 in Paris.
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00', 'Europe/Paris'));
    $me = commercial();
    $colleague = commercial();
    $this->actingAs($me);
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Lac']);

    // Follow-ups: same rules as « À faire » (Paris days, mine only, not done).
    dashAction($me, $hotel, 'Rappel', '2026-11-13 18:00:00');
    dashAction($me, $hotel, 'Rappel', '2026-11-10 09:00:00');
    dashAction($me, $hotel, 'Rappel', '2026-11-14 16:00:00');
    dashAction($me, $hotel, 'Rappel', '2026-11-15 09:00:00'); // tomorrow
    dashAction($me, $hotel, 'Rappel', '2026-11-13 09:00:00', ['done_at' => CarbonImmutable::parse('2026-11-13 10:00:00', 'Europe/Paris')]);
    dashAction($colleague, $hotel, 'Rappel', '2026-11-12 09:00:00');

    // Pipeline: open opportunities closing in November (Paris), weighted.
    makeOpportunity($hotel, ['title' => 'Nouveau novembre', 'amount' => '1000.00', 'expected_close_on' => '2026-11-30']); // 10 %
    makeOpportunity($hotel, ['title' => 'Proposition novembre', 'amount' => '2000.00', 'stage_id' => pipelineStage('Proposition')->id, 'expected_close_on' => '2026-11-01']); // 60 %
    makeOpportunity($hotel, ['title' => 'Décembre', 'amount' => '9000.00', 'expected_close_on' => '2026-12-01']);
    makeOpportunity($hotel, ['title' => 'Octobre', 'amount' => '9000.00', 'expected_close_on' => '2026-10-31']);
    makeOpportunity($hotel, ['title' => 'Sans date', 'amount' => '9000.00']);
    $lost = makeOpportunity($hotel, ['title' => 'Perdue', 'amount' => '9000.00', 'expected_close_on' => '2026-11-20']);
    app(OpportunityStage::class)->move($lost, pipelineStage('Perdu'), crmItem(ReferenceList::LossReason, 'Prix')->id, null, null);
    $won = makeOpportunity($hotel, ['title' => 'Gagnée', 'amount' => '9000.00', 'expected_close_on' => '2026-11-20']);
    app(OpportunityStage::class)->move($won, pipelineStage('Gagné'), null, null, null);
    $trashed = Organisation::query()->create(['name' => 'Fermé']);
    makeOpportunity($trashed, ['title' => 'Fiche supprimée', 'amount' => '9000.00', 'expected_close_on' => '2026-11-20']);
    $trashed->delete();

    // Meetings: mine, to do, from now on, meeting types only, in time order.
    dashAction($me, $hotel, 'Rendez-vous physique', '2026-11-17 14:00:00', ['location' => 'Annecy']);
    dashAction($me, $hotel, 'Prise de rendez-vous', '2026-11-16 09:30:00');
    dashAction($me, $hotel, 'Rendez-vous physique', '2026-11-14 08:00:00'); // earlier today: past
    dashAction($me, $hotel, 'Appel', '2026-11-16 08:00:00'); // a call, not a meeting
    dashAction($colleague, $hotel, 'Rendez-vous physique', '2026-11-16 08:00:00');

    $commercial = dashCommercial($me);

    expect($commercial['follow_ups'])->toBe(['overdue' => 2, 'today' => 2]) // the 8:00 meeting of today is to do too
        ->and($commercial['pipeline'])->toMatchArray([
            'weighted_amount' => '1300.00',
            'count' => 2,
            'month' => 'Novembre 2026',
            'close_from' => '2026-11-01',
            'close_to' => '2026-11-30',
        ])
        ->and(collect($commercial['meetings'])->map(fn ($m) => [$m['day'], $m['time'], $m['type']])->all())->toBe([
            ['Lundi 16 novembre', '09:30', 'Prise de rendez-vous'],
            ['Mardi 17 novembre', '14:00', 'Rendez-vous physique'],
        ])
        ->and($commercial['meetings'][1])->toMatchArray(['location' => 'Annecy', 'organisation' => ['id' => $hotel->id, 'name' => 'Hôtel du Lac']])
        ->and($commercial['meetings'][1])->not->toHaveKey('report');
});

it('keeps a responsable das to his das', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00:00', 'Europe/Paris'));
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $mine);
    $this->actingAs($responsable);

    $visible = Organisation::query()->create(['name' => 'Visible']);
    $visible->das()->sync([$mine->id => ['tenant_id' => tenant()->id]]);
    $hidden = Organisation::query()->create(['name' => 'Cachée']);
    $hidden->das()->sync([$other->id => ['tenant_id' => tenant()->id]]);

    makeOpportunity($visible, ['amount' => '1000.00', 'das_id' => $mine->id, 'expected_close_on' => '2026-11-20']);
    makeOpportunity($visible, ['amount' => '5000.00', 'das_id' => $other->id, 'expected_close_on' => '2026-11-20']);
    dashAction($responsable, $visible, 'Rappel', '2026-11-13 09:00:00');
    dashAction($responsable, $hidden, 'Rappel', '2026-11-13 09:00:00');
    dashAction($responsable, $visible, 'Rendez-vous physique', '2026-11-16 09:00:00');
    dashAction($responsable, $hidden, 'Rendez-vous physique', '2026-11-16 10:00:00');

    $commercial = dashCommercial($responsable);

    expect($commercial['follow_ups'])->toBe(['overdue' => 1, 'today' => 0])
        ->and($commercial['pipeline']['weighted_amount'])->toBe('100.00')
        ->and($commercial['pipeline']['count'])->toBe(1)
        ->and(collect($commercial['meetings'])->pluck('organisation.name')->all())->toBe(['Visible']);

    // Everyone else sees the whole organisme.
    expect(dashCommercial(direction(twoFactor: true))['pipeline']['weighted_amount'])->toBe('600.00');
});

it('cuts the month on paris time and leaves the summary out without a crm view right', function () {
    // 1 December 00:30 in Paris is still 30 November in UTC.
    $this->travelTo(CarbonImmutable::parse('2026-12-01 00:30:00', 'Europe/Paris'));
    $me = commercial();
    $this->actingAs($me);
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    makeOpportunity($hotel, ['amount' => '1000.00', 'expected_close_on' => '2026-11-30']);
    makeOpportunity($hotel, ['amount' => '2000.00', 'expected_close_on' => '2026-12-01']);

    expect(dashCommercial($me)['pipeline'])->toMatchArray(['weighted_amount' => '200.00', 'month' => 'Décembre 2026']);

    $nobody = User::factory()->create();
    expect(Permissions::anyView($nobody))->toBeFalse()
        ->and(dashCommercial($nobody))->toBeNull();
});
