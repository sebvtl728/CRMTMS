<?php

use App\Modules\Catalogue\Training;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierLine;
use App\Modules\Sessions\Dossiers\DossierWriter;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Enrolments\PriceShares;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsServiceProvider;
use Carbon\CarbonImmutable;

beforeEach(function () {
    inTenant();
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function psTraining(): Training
{
    return Training::query()->first()
        ?? Training::query()->create(['title' => 'HACCP 3 jours', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id, 'duration_hours' => '21.00']);
}

function psSession(int $capacity = 10): TrainingSession
{
    return app(SessionWriter::class)->create([
        'training_id' => psTraining()->id, 'kind' => 'inter', 'starts_on' => '2026-12-07', 'ends_on' => '2026-12-09',
        'location_kind' => 'remote', 'capacity' => $capacity, 'status' => 'planned',
    ]);
}

function psDossier(string $unitPrice = '1000', string $mode = 'per_trainee'): Dossier
{
    return app(DossierWriter::class)->create([
        'company_id' => (Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create())->id,
        'account_organisation_id' => Organisation::query()->create(['name' => 'Hôtel du Parc'])->id,
        'das_id' => psTraining()->das_id,
        'lines' => [['kind' => 'training', 'training_id' => psTraining()->id, 'label' => 'HACCP', 'quantity' => '1', 'unit' => 'forfait',
            'unit_price' => $unitPrice, 'discount_percent' => '', 'pricing_mode' => $mode]],
    ]);
}

function psEnrol(Dossier $dossier, TrainingSession $session): Enrolment
{
    $enrolment = app(Enrolments::class)->enrol($dossier, $dossier->lines()->firstOrFail(), $session, makePerson('Stagiaire')->id);
    // Distinct instants: the order of enrolment is created_at, then id.
    test()->travel(1)->seconds();

    return $enrolment;
}

/**
 * @return list<?string> shares in order of enrolment
 */
function psShares(Dossier $dossier): array
{
    return Enrolment::query()->where('dossier_id', $dossier->id)->orderBy('created_at')->orderBy('id')->pluck('price_share')->all();
}

it('splits 1 000 € between 3 trainees as 333,34 + 333,33 + 333,33 (Review Focus 3)', function () {
    $dossier = psDossier();
    $session = psSession();
    psEnrol($dossier, $session);
    expect(psShares($dossier))->toBe(['1000.00']);

    psEnrol($dossier, $session);
    expect(psShares($dossier))->toBe(['500.00', '500.00']);

    psEnrol($dossier, $session);
    expect(psShares($dossier))->toBe(['333.34', '333.33', '333.33']);
});

it('computes the shares again at each cancellation, an abandon keeping its share', function () {
    $dossier = psDossier();
    $session = psSession();
    $a = psEnrol($dossier, $session);
    $b = psEnrol($dossier, $session);
    psEnrol($dossier, $session);
    $enrolments = app(Enrolments::class);

    $enrolments->abandon($a, CarbonImmutable::parse('2026-12-08'), crmItem(ReferenceList::AbandonReason, 'Maladie')->id);
    expect(psShares($dossier))->toBe(['333.34', '333.33', '333.33']);

    $enrolments->cancel($b);
    expect(psShares($dossier))->toBe(['500.00', null, '500.00']);
});

it('gives no share to the waiting list nor to a line priced for the group', function () {
    $dossier = psDossier();
    $session = psSession(1);
    psEnrol($dossier, $session);
    $waiting = psEnrol($dossier, $session);
    expect(psShares($dossier))->toBe(['1000.00', null]);

    // Promoted, it shares the price.
    app(Enrolments::class)->cancel(Enrolment::query()->where('dossier_id', $dossier->id)->orderBy('created_at')->firstOrFail());
    app(Enrolments::class)->promote($waiting);
    expect(psShares($dossier))->toBe([null, '1000.00']);

    $group = psDossier('2500', 'group');
    psEnrol($group, psSession());
    expect(psShares($group))->toBe([null]);
});

it('follows a change of the price or of the pricing mode of the line', function () {
    $dossier = psDossier();
    $session = psSession();
    psEnrol($dossier, $session);
    psEnrol($dossier, $session);
    $line = $dossier->lines()->firstOrFail();
    $payload = fn (array $o) => [
        'company_id' => $dossier->company_id, 'account_organisation_id' => $dossier->account_organisation_id, 'das_id' => $dossier->das_id,
        'lines' => [['id' => $line->id, 'kind' => 'training', 'training_id' => $line->training_id, 'label' => 'HACCP', 'quantity' => '1',
            'unit' => 'forfait', 'unit_price' => '100,01', 'discount_percent' => '', 'pricing_mode' => 'per_trainee', ...$o]],
    ];

    app(DossierWriter::class)->update($dossier, $payload([]));
    expect(psShares($dossier))->toBe(['50.01', '50.00']);

    app(DossierWriter::class)->update($dossier, $payload(['pricing_mode' => DossierLine::GROUP]));
    expect(psShares($dossier))->toBe([null, null]);
});

it('splits to the cent with an exact sum, the remainder to the first ones', function (int $cents, int $count) {
    $shares = PriceShares::split($cents, $count);

    expect($shares)->toHaveCount($count)
        ->and(array_sum($shares))->toBe($cents)
        ->and(max($shares) - min($shares))->toBeLessThanOrEqual(1)
        ->and($shares)->toBe(collect($shares)->sortDesc()->values()->all());
})->with([
    [100000, 3],
    [10000, 7],
    [1, 3],
    [0, 4],
    [99999999999, 13],
]);
