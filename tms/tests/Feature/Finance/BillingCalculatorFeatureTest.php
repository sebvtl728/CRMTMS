<?php

use App\Modules\Core\References\ReferenceList;
use App\Modules\Finance\Billing\BillingCalculator;
use App\Modules\Finance\Billing\CutOff;
use App\Modules\Finance\Plans\BillingPlans;
use App\Modules\Sessions\Attendance\Attendances;
use App\Modules\Sessions\Attendance\Closures;
use App\Modules\Sessions\Enrolments\Enrolments;
use App\Modules\Sessions\Public\Billing;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
});

/**
 * A dossier of 2 100 € HT: HACCP per trainee (2 × 1 000 €) on a session of
 * September 2026 (21 h planned), a fee of 100 €; Léa present 3 × 3 h and
 * absent 3 h, Tom present 3 h then gone on 5 September (Démission;
 * prorata + 30 % dédit); OPCO agreed, paid directly, 1 200 €.
 *
 * @return array<string, mixed>
 */
function bcfSeed(bool $close = true): array
{
    $training = l2Training();
    $dossier = l2Dossier(null, $training, [
        'abandon_mode' => 'prorata_dedit',
        'abandon_dedit_percent' => 30,
        'lines' => [
            ['kind' => 'training', 'training_id' => $training->id, 'label' => 'HACCP', 'quantity' => '2', 'unit' => 'stagiaire', 'unit_price' => '1000', 'discount_percent' => '', 'pricing_mode' => 'per_trainee'],
            ['kind' => 'fee', 'label' => 'Déplacement', 'quantity' => '1', 'unit' => 'forfait', 'unit_price' => '100', 'discount_percent' => ''],
        ],
    ]);
    $session = plSession('2026-09-01', '2026-09-30');
    $module = plModule($session);
    $lea = l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa']));
    $tom = l2Enrol($dossier, $session, makePerson('Petit', ['first_name' => 'Tom']));
    $attendances = app(Attendances::class);
    foreach (['2026-09-02', '2026-09-03', '2026-09-04', '2026-09-07'] as $i => $day) {
        $seance = plSeance($module, $day, '09:00', '12:00');
        $attendances->record($seance, $lea->id, $i === 3 ? 'absent' : 'present', null, null);
        if ($i === 0) {
            $attendances->record($seance, $tom->id, 'present', null, null);
        }
    }
    app(Enrolments::class)->abandon($tom, CarbonImmutable::parse('2026-09-05'), crmItem(ReferenceList::AbandonReason, 'Démission')->id);
    if ($close) {
        app(Closures::class)->close($session->refresh(), '2026-09');
    }
    $opco = l2Funding($dossier, ['amount' => '1 200,00', 'status' => 'agreed', 'agreement_number' => 'PEC-2026-42', 'agreed_on' => '2026-09-01', 'pays_directly' => true]);

    return compact('dossier', 'session', 'lea', 'tom', 'opco');
}

it('computes the shares of a seeded dossier from the public billing surface of Sessions', function () {
    $x = bcfSeed();
    $id = $x['dossier']->id;
    app(BillingPlans::class)->save($id, ['rhythm' => 'end', 'basis' => 'realised', 'unjustified_absences' => 'bill_contractor']);
    $plan = app(BillingPlans::class)->for($id);
    $hours = Billing::hours($id);
    expect(array_column($hours['enrolments'], 'planned_minutes'))->toBe([1260, 1260]);

    $shares = BillingCalculator::cumulative(Billing::dossier($id), $hours, $plan, $plan['absences'], CutOff::on('2026-11-17'));

    // Léa 1 000 € × 540 / 1 260 = 428,57 € ; Tom 1 000 € × 180 / 1 260 = 142,86 € ; fee 100 €.
    expect($shares->ready)->toBeTrue()
        ->and($shares->billableCents)->toBe(42857 + 14286 + 10000)
        // Tom: 30 % of the 857,14 € not done; Léa: 180 min absent at 1 000 € / 1 260 min.
        ->and($shares->deditCents)->toBe(25714)
        ->and($shares->absencesCents)->toBe(14286)
        // OPCO: 1 200 € × 671,43 / 2 100 = 383,67 € ; the contractor takes the rest.
        ->and(array_map(fn (array $r) => $r['training_cents'], $shares->byRecipient))->toBe(['contractor' => 28776, "funding:{$x['opco']->id}" => 38367])
        ->and($shares->byRecipient['contractor']['recipient'])->toBe(['type' => 'contractor', 'id' => $x['dossier']->account_organisation_id, 'fiche_type' => 'organisation'])
        ->and($shares->byRecipient["funding:{$x['opco']->id}"]['recipient']['funding_id'])->toBe($x['opco']->id)
        ->and(array_column($shares->detail, 'enrolment_id'))->toBe([$x['lea']->id, $x['tom']->id, null]);
});

it('waits for the months of the dossier to be closed', function () {
    $x = bcfSeed(close: false);
    $id = $x['dossier']->id;
    $plans = app(BillingPlans::class);

    $end = BillingCalculator::cumulative(Billing::dossier($id), Billing::hours($id), $plans->for($id), 'not_billed', CutOff::on('2026-11-17'));
    $plans->save($id, ['rhythm' => 'monthly', 'basis' => 'realised']);
    $monthly = BillingCalculator::cumulative(Billing::dossier($id), Billing::hours($id, '2026-09'), $plans->for($id), 'not_billed', CutOff::month('2026-09'));

    expect([$end->ready, $end->reason])->toBe([false, 'Formation non terminée'])
        ->and([$monthly->ready, $monthly->reason])->toBe([false, 'Mois non clôturé']);

    app(Closures::class)->close($x['session']->refresh(), '2026-09');
    $closed = BillingCalculator::cumulative(Billing::dossier($id), Billing::hours($id, '2026-09'), $plans->for($id), 'not_billed', CutOff::month('2026-09'));

    expect($closed->ready)->toBeTrue()
        ->and($closed->billableCents)->toBe(42857 + 14286 + 10000)
        ->and($closed->absencesCents)->toBe(0);
});
