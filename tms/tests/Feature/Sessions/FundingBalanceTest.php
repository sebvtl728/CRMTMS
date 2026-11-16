<?php

use App\Modules\Catalogue\Training;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierWriter;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Fundings\Funding;
use App\Modules\Sessions\Fundings\FundingBalance;
use App\Modules\Sessions\Fundings\FundingStatus;
use App\Modules\Sessions\Fundings\FundingWriter;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\SessionsServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    inTenant();
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00', 'Europe/Paris'));
});

function balTraining(): Training
{
    return Training::query()->where('title', 'HACCP 3 jours')->first()
        ?? Training::query()->create(['title' => 'HACCP 3 jours', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id, 'duration_hours' => '21.00']);
}

/**
 * A dossier of « Hôtel du Parc » with these lines (training lines of HACCP).
 *
 * @param  list<array<string, mixed>>  $lines
 */
function balDossier(array $lines): Dossier
{
    $training = balTraining();

    return app(DossierWriter::class)->create([
        'company_id' => (Company::query()->where('is_default', true)->first() ?? Company::factory()->default()->create())->id,
        'account_organisation_id' => Organisation::query()->create(['name' => 'Hôtel du Parc'])->id,
        'account_person_id' => null,
        'das_id' => $training->das_id,
        'lines' => array_map(fn (array $l) => [
            'kind' => 'training', 'training_id' => $training->id, 'label' => 'HACCP', 'quantity' => '1', 'unit' => 'forfait',
            'unit_price' => '3000', 'discount_percent' => '', 'pricing_mode' => 'group', ...$l,
        ], $lines),
    ]);
}

function balOrganisation(string $name): Organisation
{
    return Organisation::query()->create(['name' => $name]);
}

/**
 * @param  array<string, mixed>  $data
 */
function balFund(Dossier $dossier, string $amount, string $type = 'OPCO', array $data = []): Funding
{
    return app(FundingWriter::class)->create($dossier, [
        'funder_organisation_id' => balOrganisation($type.' '.Str::random(4))->id,
        'funding_type_id' => crmItem(ReferenceList::FundingType, $type)->id,
        'amount' => $amount,
        ...$data,
    ]);
}

function balSession(): TrainingSession
{
    return app(SessionWriter::class)->create([
        'training_id' => balTraining()->id, 'kind' => 'inter', 'organisation_id' => null, 'starts_on' => '2026-12-07', 'ends_on' => '2026-12-09',
        'planned_hours' => '', 'location_kind' => 'remote', 'location_text' => null, 'capacity' => 10, 'responsible_user_id' => null,
        'status' => 'planned', 'notes' => null,
    ]);
}

function balEnrol(Dossier $dossier, TrainingSession $session): Enrolment
{
    return app(Enrolments::class)->enrol($dossier, $dossier->lines()->firstOrFail(), $session, makePerson('Stagiaire '.Str::random(6))->id);
}

// ---------------------------------------------------------------- dossier (Review Focus 1)

it('balances OPCO 2 100 + entreprise 900 against a price of 3 000: nothing left', function () {
    $dossier = balDossier([['unit_price' => '3000']]);
    balFund($dossier, '2100');
    balFund($dossier, '900', 'Entreprise');

    $balance = FundingBalance::for($dossier->refresh());

    expect($balance['price_cents'])->toBe(300000)
        ->and($balance['funded_cents'])->toBe(300000)
        ->and($balance['remaining_cents'])->toBe(0)
        ->and($balance['lines'])->toBe([])
        ->and($balance['enrolments'])->toBe([])
        ->and(FundingBalance::message($balance['remaining_cents']))->toBe('Reste à financer : 0,00 €');
});

it('no longer counts a refused funding: 2 100 left to fund', function () {
    $dossier = balDossier([['unit_price' => '3000']]);
    $opco = balFund($dossier, '2100');
    balFund($dossier, '900', 'Entreprise');

    app(FundingWriter::class)->update($opco, ['status' => FundingStatus::Refused->value]);
    $balance = FundingBalance::for($dossier->refresh());

    expect($balance['funded_cents'])->toBe(90000)
        ->and($balance['remaining_cents'])->toBe(210000)
        ->and(FundingBalance::message($balance['remaining_cents']))->toBe('Reste à financer : 2 100,00 €')
        // No automatic transfer: the other funding is unchanged.
        ->and(Funding::query()->where('id', '<>', $opco->id)->sole()->amount)->toBe('900.00');
});

it('shows the exact excess when the fundings exceed the price', function () {
    $dossier = balDossier([['unit_price' => '3000']]);
    balFund($dossier, '3000');
    balFund($dossier, '500,01', 'Entreprise');

    $balance = FundingBalance::for($dossier->refresh());

    expect($balance['remaining_cents'])->toBe(-50001)
        ->and(FundingBalance::message($balance['remaining_cents']))->toBe('Financements supérieurs au prix : 500,01 €');
});

it('adds the amounts exactly to the cent, French input included', function () {
    $dossier = balDossier([['unit_price' => '0,30']]);
    balFund($dossier, '0,10');
    balFund($dossier, '0.20', 'Entreprise');
    $big = balFund($dossier, '1 234,56', 'CPF');

    expect($big->amount)->toBe('1234.56')
        ->and(FundingBalance::for($dossier->refresh())['remaining_cents'])->toBe(-123456);
});

// ---------------------------------------------------------------- line and enrolment

it('balances a targeted line, and only the lines targeted', function () {
    $dossier = balDossier([['unit_price' => '2000'], ['kind' => 'fee', 'training_id' => null, 'pricing_mode' => null, 'label' => 'Déplacement', 'unit_price' => '1000']]);
    $training = $dossier->lines->firstOrFail();
    balFund($dossier, '1500', 'OPCO', ['dossier_line_id' => $training->id]);
    balFund($dossier, '400', 'Entreprise', ['dossier_line_id' => $training->id, 'status' => 'refused']);
    balFund($dossier, '1000', 'Entreprise');

    $balance = FundingBalance::for($dossier->refresh());

    expect($balance['remaining_cents'])->toBe(50000)
        // The fee line, not targeted, is absent.
        ->and($balance['lines'])->toBe([$training->id => ['price_cents' => 200000, 'funded_cents' => 150000, 'remaining_cents' => 50000]]);
});

it('balances each enrolment targeted against its share of a per trainee line', function () {
    $dossier = balDossier([['unit_price' => '1000', 'pricing_mode' => 'per_trainee']]);
    $session = balSession();
    $first = balEnrol($dossier, $session);
    $second = balEnrol($dossier, $session);
    balEnrol($dossier, $session);

    balFund($dossier, '333,34', 'OPCO', ['enrolment_id' => $first->id]);
    balFund($dossier, '300', 'Personnel', ['enrolment_id' => $second->id]);

    $balance = FundingBalance::for($dossier->refresh());

    expect($balance['enrolments'])->toBe([
        $first->id => ['price_cents' => 33334, 'funded_cents' => 33334, 'remaining_cents' => 0, 'cancelled' => false],
        $second->id => ['price_cents' => 33333, 'funded_cents' => 30000, 'remaining_cents' => 3333, 'cancelled' => false],
    ])->and($balance['remaining_cents'])->toBe(100000 - 63334);
});

it('keeps the funding of a cancelled enrolment and flags it', function () {
    $dossier = balDossier([['unit_price' => '1000', 'pricing_mode' => 'per_trainee']]);
    $session = balSession();
    $enrolment = balEnrol($dossier, $session);
    balEnrol($dossier, $session);
    $funding = balFund($dossier, '500', 'OPCO', ['enrolment_id' => $enrolment->id]);

    app(Enrolments::class)->cancel($enrolment);
    $balance = FundingBalance::for($dossier->refresh());

    expect(Funding::query()->find($funding->id))->not->toBeNull()
        ->and($balance['enrolments'][$enrolment->id])->toBe(['price_cents' => 0, 'funded_cents' => 50000, 'remaining_cents' => -50000, 'cancelled' => true]);
});

// ---------------------------------------------------------------- review fixes

it('counts for a line the fundings of the line and of its enrolments', function () {
    $dossier = balDossier([['unit_price' => '3000', 'pricing_mode' => 'per_trainee']]);
    $line = $dossier->lines->firstOrFail();
    $session = balSession();
    $enrolments = [balEnrol($dossier, $session), balEnrol($dossier, $session), balEnrol($dossier, $session)];
    foreach ($enrolments as $enrolment) {
        balFund($dossier, '700', 'OPCO', ['enrolment_id' => $enrolment->id]);
    }
    balFund($dossier, '900', 'Entreprise', ['dossier_line_id' => $line->id]);

    $balance = FundingBalance::for($dossier->refresh());

    expect($balance['lines'])->toBe([$line->id => ['price_cents' => 300000, 'funded_cents' => 300000, 'remaining_cents' => 0]])
        ->and($balance['remaining_cents'])->toBe(0)
        ->and($balance['enrolments'][$enrolments[0]->id])->toBe(['price_cents' => 100000, 'funded_cents' => 70000, 'remaining_cents' => 30000, 'cancelled' => false]);
});

it('shows a line whose enrolments only are targeted', function () {
    $dossier = balDossier([['unit_price' => '3000', 'pricing_mode' => 'per_trainee']]);
    $line = $dossier->lines->firstOrFail();
    $session = balSession();
    $enrolment = balEnrol($dossier, $session);
    balEnrol($dossier, $session);
    balFund($dossier, '700', 'OPCO', ['enrolment_id' => $enrolment->id]);
    balFund($dossier, '100', 'OPCO', ['enrolment_id' => $enrolment->id, 'status' => 'refused']);

    expect(FundingBalance::for($dossier->refresh())['lines'])
        ->toBe([$line->id => ['price_cents' => 300000, 'funded_cents' => 70000, 'remaining_cents' => 230000]]);
});

it('words the funding of a cancelled enrolment as an amount to move', function () {
    expect(FundingBalance::message(-50000, true, 50000))->toBe('Financement à reporter : 500,00 €')
        ->and(FundingBalance::message(-50000))->toBe('Financements supérieurs au prix : 500,00 €');
});
