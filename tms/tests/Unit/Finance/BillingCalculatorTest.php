<?php

use App\Modules\Finance\Billing\BillingCalculator;
use App\Modules\Finance\Billing\CutOff;
use App\Modules\Finance\Billing\Shares;

/*
 * The money core of the invoicing (spec 0.9 § 4.3 and § 4 bis), on plain
 * arrays shaped like Sessions\Public\Billing::dossier() / hours(): every
 * amount in integer cents, every duration in minutes.
 */

const BC_HOTEL = ['type' => 'organisation', 'id' => 'org-hotel'];

/** @return array<string, mixed> */
function bcLine(string $id, int $totalCents, string $kind = 'training', ?string $pricing = 'per_trainee', array $sessionIds = ['s1']): array
{
    return ['id' => $id, 'kind' => $kind, 'pricing_mode' => $kind === 'fee' ? null : $pricing, 'label' => $id, 'total_cents' => $totalCents,
        'session_ids' => $kind === 'fee' ? [] : $sessionIds];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function bcEnrolment(string $id, string $lineId, ?int $shareCents, array $extra = []): array
{
    return ['id' => $id, 'line_id' => $lineId, 'status' => 'enrolled', 'price_share_cents' => $shareCents, 'session_id' => 's1',
        'entered_on' => '2026-09-01', 'left_on' => null, 'abandoned' => false, 'force_majeure' => false, ...$extra];
}

/**
 * An agreed funding paid directly by the OPCO, for the whole dossier.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function bcFunding(string $id, int $amountCents, array $extra = []): array
{
    return ['id' => $id, 'funder' => ['type' => 'organisation', 'id' => 'org-opco'], 'external_billing' => false, 'amount_cents' => $amountCents,
        'status' => 'agreed', 'agreement_number' => 'PEC-1', 'pays_directly' => true, 'line_id' => null, 'enrolment_id' => null, ...$extra];
}

/**
 * @param  list<array<string, mixed>>  $lines
 * @param  list<array<string, mixed>>  $enrolments
 * @param  list<array<string, mixed>>  $fundings
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function bcDossier(array $lines, array $enrolments, array $fundings = [], array $extra = []): array
{
    return [
        'id' => 'd1', 'contractor' => BC_HOTEL, 'price_cents' => array_sum(array_column($lines, 'total_cents')),
        'abandon' => ['mode' => 'prorata', 'dedit_percent' => null],
        'lines' => $lines, 'enrolments' => $enrolments, 'fundings' => $fundings,
        'sessions' => [['id' => 's1', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'done']],
        ...$extra,
    ];
}

/**
 * @param  array<string, array{0: int, 1: int, 2?: int}>  $enrolments  id => [planned, realised, absent]
 * @param  array<string, array{0: int, 1: int}>  $sessions  group session id => [planned, realised]
 * @param  array<string, list<string>>  $closed  session id => closed months
 * @return array<string, mixed>
 */
function bcHours(array $enrolments, array $sessions = [], array $closed = ['s1' => ['2026-09']], array $allClosed = ['s1' => true], string $session = 's1'): array
{
    $e = [];
    foreach ($enrolments as $id => $m) {
        $e[] = ['enrolment_id' => $id, 'session_id' => $session, 'planned_minutes' => $m[0], 'realised_minutes' => $m[1], 'absent_minutes' => $m[2] ?? 0,
            'all_months_closed' => $allClosed[$session] ?? false, 'closed_months' => $closed[$session] ?? []];
    }
    $s = [];
    foreach ($sessions as $id => $m) {
        $s[] = ['session_id' => $id, 'planned_minutes' => $m[0], 'realised_minutes' => $m[1], 'all_months_closed' => $allClosed[$id] ?? false, 'closed_months' => $closed[$id] ?? []];
    }

    return ['enrolments' => $e, 'sessions' => $s];
}

/**
 * @param  list<array{due_on: string, percent: int}>  $schedule
 * @return array<string, mixed>
 */
function bcPlan(string $rhythm = 'end', string $basis = 'realised', array $schedule = []): array
{
    return ['rhythm' => $rhythm, 'basis' => $basis, 'unjustified_absences' => null, 'absences' => 'not_billed', 'schedule' => $schedule, 'locked' => false];
}

function bcRun(array $dossier, array $hours, ?array $plan = null, string $absences = 'not_billed', ?CutOff $cut = null): Shares
{
    return BillingCalculator::cumulative($dossier, $hours, $plan ?? bcPlan(), $absences, $cut ?? CutOff::on('2026-10-15'));
}

/** @return array<string, int> recipient key => training cents */
function bcTraining(Shares $shares): array
{
    return array_map(fn (array $r) => $r['training_cents'], $shares->byRecipient);
}

// ------------------------------------------------------------ training lines

it('bills a trainee at the realised share of his planned hours: 20 h planned, 14 h done at 1 000 € ⇒ 700 €', function () {
    $shares = bcRun(bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000)]), bcHours(['e1' => [1200, 840]]));

    expect($shares->ready)->toBeTrue()
        ->and($shares->reason)->toBeNull()
        ->and($shares->billableCents)->toBe(70000)
        ->and(bcTraining($shares))->toBe(['contractor' => 70000])
        ->and($shares->byRecipient['contractor']['recipient'])->toBe(['type' => 'contractor', 'id' => 'org-hotel', 'fiche_type' => 'organisation'])
        ->and($shares->detail)->toBe([
            ['line_id' => 'l1', 'enrolment_id' => 'e1', 'planned' => 1200, 'realised' => 840, 'ratio' => '0.7000', 'amount_cents' => 70000, 'dedit_cents' => 0, 'absences_cents' => 0],
        ]);
});

it('never bills a trainee more than his share, whatever the hours done', function () {
    $shares = bcRun(bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000)]), bcHours(['e1' => [1200, 1500]]));

    expect($shares->billableCents)->toBe(100000)
        ->and($shares->detail[0]['ratio'])->toBe('1.0000');
});

it('bills a group line at the realised share of its session: 3 000 €, 35 h planned, 28 h held ⇒ 2 400 €', function () {
    $dossier = bcDossier([bcLine('l2', 300000, 'training', 'group', ['s2'])], [bcEnrolment('e3', 'l2', null, ['session_id' => 's2'])],
        extra: ['sessions' => [['id' => 's2', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => 'done']]]);
    $hours = bcHours(['e3' => [2100, 0]], ['s2' => [2100, 1680]], ['s2' => ['2026-09']], ['s2' => true], 's2');

    $shares = bcRun($dossier, $hours);

    expect($shares->billableCents)->toBe(240000)
        ->and($shares->detail)->toBe([
            ['line_id' => 'l2', 'enrolment_id' => null, 'planned' => 2100, 'realised' => 1680, 'ratio' => '0.8000', 'amount_cents' => 240000, 'dedit_cents' => 0, 'absences_cents' => 0],
        ]);
});

it('bills a fee line in full at the end', function () {
    $shares = bcRun(bcDossier([bcLine('l1', 100000), bcLine('f1', 15000, 'fee')], [bcEnrolment('e1', 'l1', 100000)]), bcHours(['e1' => [1200, 600]]));

    expect($shares->billableCents)->toBe(65000)
        ->and($shares->detail[1])->toBe(['line_id' => 'f1', 'enrolment_id' => null, 'planned' => null, 'realised' => null, 'ratio' => '1.0000', 'amount_cents' => 15000, 'dedit_cents' => 0, 'absences_cents' => 0]);
});

it('bills the planned price on a planned basis, whatever was done', function () {
    $shares = bcRun(bcDossier([bcLine('l1', 200000), bcLine('f1', 15000, 'fee')], [bcEnrolment('e1', 'l1', 100000), bcEnrolment('e2', 'l1', 100000)]),
        bcHours(['e1' => [1200, 0], 'e2' => [1200, 300]]), bcPlan('end', 'planned'));

    expect($shares->billableCents)->toBe(215000)
        ->and(bcTraining($shares))->toBe(['contractor' => 215000]);
});

// ------------------------------------------------------------ abandon

it('bills an abandon at the prorata of the hours done plus 30 % of the rest as a dédit, to the contractor only', function () {
    $dossier = bcDossier([bcLine('l1', 200000)], [
        bcEnrolment('e1', 'l1', 100000),
        bcEnrolment('e2', 'l1', 100000, ['status' => 'abandoned', 'abandoned' => true, 'left_on' => '2026-09-10']),
    ], [bcFunding('f1', 200000)], ['abandon' => ['mode' => 'prorata_dedit', 'dedit_percent' => 30]]);

    $shares = bcRun($dossier, bcHours(['e1' => [1200, 1200], 'e2' => [1200, 300]]));

    // e2: 1 000 € × 300 / 1 200 = 250 € ; dédit 30 % × 750 € = 225 €.
    expect($shares->billableCents)->toBe(125000)
        ->and($shares->deditCents)->toBe(22500)
        ->and($shares->detail[1])->toMatchArray(['enrolment_id' => 'e2', 'amount_cents' => 25000, 'dedit_cents' => 22500])
        // The funder takes its share of the hours done (2 000 € × 1 250 / 2 000), never of the dédit.
        ->and(bcTraining($shares))->toBe(['contractor' => 0, 'funding:f1' => 125000])
        ->and($shares->byRecipient['contractor']['dedit_cents'])->toBe(22500)
        ->and($shares->byRecipient['funding:f1']['dedit_cents'])->toBe(0);
});

it('never costs a dédit on a force majeure abandon nor under the prorata rule', function () {
    $abandon = bcEnrolment('e2', 'l1', 100000, ['status' => 'abandoned', 'abandoned' => true, 'left_on' => '2026-09-10', 'force_majeure' => true]);
    $hours = bcHours(['e2' => [1200, 300]]);

    $forceMajeure = bcRun(bcDossier([bcLine('l1', 100000)], [$abandon], [], ['abandon' => ['mode' => 'prorata_dedit', 'dedit_percent' => 30]]), $hours);
    $prorata = bcRun(bcDossier([bcLine('l1', 100000)], [[...$abandon, 'force_majeure' => false]]), $hours);

    expect([$forceMajeure->billableCents, $forceMajeure->deditCents])->toBe([25000, 0])
        ->and([$prorata->billableCents, $prorata->deditCents])->toBe([25000, 0]);
});

// ------------------------------------------------------------ direct funders

it('shares the realised amount with an agreed funder paying directly: 1 200 € on 2 000 € at 50 % ⇒ OPCO 600 €, contractor 400 €', function () {
    $dossier = bcDossier([bcLine('l1', 200000)], [bcEnrolment('e1', 'l1', 100000), bcEnrolment('e2', 'l1', 100000)], [bcFunding('f1', 120000)]);

    $shares = bcRun($dossier, bcHours(['e1' => [1200, 600], 'e2' => [1200, 600]]));

    expect($shares->billableCents)->toBe(100000)
        ->and(bcTraining($shares))->toBe(['contractor' => 40000, 'funding:f1' => 60000])
        ->and($shares->byRecipient['funding:f1']['recipient'])->toBe(['type' => 'funder', 'id' => 'org-opco', 'fiche_type' => 'organisation', 'funding_id' => 'f1'])
        ->and($shares->byRecipient['funding:f1']['external'])->toBeFalse();
});

it('leaves the whole amount to the contractor while the funding is only requested, or not paid directly', function () {
    $lines = [bcLine('l1', 200000)];
    $enrolments = [bcEnrolment('e1', 'l1', 100000), bcEnrolment('e2', 'l1', 100000)];
    $hours = bcHours(['e1' => [1200, 600], 'e2' => [1200, 600]]);

    foreach ([['status' => 'requested'], ['status' => 'refused'], ['pays_directly' => false]] as $change) {
        $shares = bcRun(bcDossier($lines, $enrolments, [bcFunding('f1', 120000, $change)]), $hours);
        expect(bcTraining($shares))->toBe(['contractor' => 100000]);
    }
});

it('never gives a share to a funding whose funder is the contractor itself', function () {
    $shares = bcRun(bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000)], [bcFunding('f1', 50000, ['funder' => BC_HOTEL])]),
        bcHours(['e1' => [1200, 1200]]));

    expect(bcTraining($shares))->toBe(['contractor' => 100000]);
});

it('removes the part of a funding billed outside the TMS from the contractor, without invoicing it: CPF 500 €', function () {
    $cpf = bcFunding('f2', 50000, ['funder' => ['type' => 'organisation', 'id' => 'org-cdc'], 'external_billing' => true, 'pays_directly' => false]);
    $shares = bcRun(bcDossier([bcLine('l1', 200000)], [bcEnrolment('e1', 'l1', 100000), bcEnrolment('e2', 'l1', 100000)], [$cpf]),
        bcHours(['e1' => [1200, 1200], 'e2' => [1200, 1200]]));

    expect($shares->billableCents)->toBe(200000)
        ->and(bcTraining($shares))->toBe(['contractor' => 150000, 'funding:f2' => 50000])
        ->and($shares->byRecipient['funding:f2']['external'])->toBeTrue();

    // Requested only: nothing is removed.
    $requested = bcRun(bcDossier([bcLine('l1', 200000)], [bcEnrolment('e1', 'l1', 100000), bcEnrolment('e2', 'l1', 100000)], [[...$cpf, 'status' => 'requested']]),
        bcHours(['e1' => [1200, 1200], 'e2' => [1200, 1200]]));
    expect(bcTraining($requested))->toBe(['contractor' => 200000]);
});

it('makes a targeted funding follow the billable amount of its target', function () {
    $dossier = bcDossier([bcLine('l1', 200000), bcLine('f1', 20000, 'fee')], [bcEnrolment('e1', 'l1', 100000), bcEnrolment('e2', 'l1', 100000)], [
        bcFunding('onE1', 80000, ['enrolment_id' => 'e1']),
        bcFunding('onLine', 100000, ['funder' => ['type' => 'organisation', 'id' => 'org-region'], 'line_id' => 'l1']),
    ]);

    $shares = bcRun($dossier, bcHours(['e1' => [1200, 600], 'e2' => [1200, 1200]]));

    // e1: 500 € billable ⇒ 800 € × 500 / 1 000 = 400 € ; l1: 1 500 € ⇒ 1 000 € × 1 500 / 2 000 = 750 €.
    expect($shares->billableCents)->toBe(170000)
        ->and(bcTraining($shares))->toBe(['contractor' => 55000, 'funding:onE1' => 40000, 'funding:onLine' => 75000]);
});

it('caps a funder at its amount and the funders together at the billable amount', function () {
    $dossier = bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000)], [
        bcFunding('a', 80000), bcFunding('b', 80000, ['funder' => ['type' => 'organisation', 'id' => 'org-region']]),
    ]);

    $shares = bcRun($dossier, bcHours(['e1' => [1200, 1200]]));

    expect(bcTraining($shares))->toBe(['contractor' => 0, 'funding:a' => 80000, 'funding:b' => 20000])
        ->and(array_sum(bcTraining($shares)))->toBe($shares->billableCents);
});

it('gives the rounding remainder to the contractor: the shares add up to the billable amount', function () {
    // 1 000 € at 1/3 ⇒ 333,33 € ; the OPCO (500 €) takes 500 × 333,33 / 1 000 = 166,665 ⇒ 166,67 €.
    $dossier = bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000)], [bcFunding('f1', 50000)]);

    $shares = bcRun($dossier, bcHours(['e1' => [300, 100]]));

    expect($shares->billableCents)->toBe(33333)
        ->and(bcTraining($shares))->toBe(['contractor' => 16666, 'funding:f1' => 16667]);
});

it('rounds each trainee to the cent half up, then sums', function () {
    // 3 trainees of 333,34 / 333,33 / 333,33 € at 2/3 ⇒ 222,23 + 222,22 + 222,22.
    $dossier = bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 33334), bcEnrolment('e2', 'l1', 33333), bcEnrolment('e3', 'l1', 33333)]);

    $shares = bcRun($dossier, bcHours(['e1' => [300, 200], 'e2' => [300, 200], 'e3' => [300, 200]]));

    expect(array_column($shares->detail, 'amount_cents'))->toBe([22223, 22222, 22222])
        ->and($shares->billableCents)->toBe(66667);
});

// ------------------------------------------------------------ unjustified absences

it('bills unjustified absences to the contractor at the hourly rate of the trainee, or not at all', function () {
    $dossier = bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000)], [bcFunding('f1', 100000)]);
    $hours = bcHours(['e1' => [1200, 900, 180]]);

    $billed = bcRun($dossier, $hours, absences: 'bill_contractor');
    $notBilled = bcRun($dossier, $hours, absences: 'not_billed');

    // 1 000 € / 1 200 min × 180 min = 150 € to the contractor; the funder only pays the hours done.
    expect($billed->absencesCents)->toBe(15000)
        ->and($billed->byRecipient['contractor']['absences_cents'])->toBe(15000)
        ->and($billed->byRecipient['funding:f1']['absences_cents'])->toBe(0)
        ->and(bcTraining($billed))->toBe(['contractor' => 0, 'funding:f1' => 75000])
        ->and($billed->detail[0]['absences_cents'])->toBe(15000)
        ->and($notBilled->absencesCents)->toBe(0)
        ->and($notBilled->byRecipient['contractor']['absences_cents'])->toBe(0);
});

it('never bills the absences of a trainee who left', function () {
    $dossier = bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000, ['status' => 'abandoned', 'abandoned' => true, 'left_on' => '2026-09-10'])]);

    expect(bcRun($dossier, bcHours(['e1' => [1200, 300, 180]]), absences: 'bill_contractor')->absencesCents)->toBe(0);
});

// ------------------------------------------------------------ rhythms and cut-off

it('waits until every session is over and its months closed for an end-of-training invoice', function () {
    $dossier = bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000)]);

    $running = bcRun($dossier, bcHours(['e1' => [1200, 600]]), cut: CutOff::on('2026-09-30'));
    $notClosed = bcRun($dossier, bcHours(['e1' => [1200, 600]], allClosed: ['s1' => false]));

    foreach ([$running, $notClosed] as $shares) {
        expect($shares->ready)->toBeFalse()
            ->and($shares->reason)->toBe('Formation non terminée')
            ->and($shares->billableCents)->toBe(0)
            ->and($shares->byRecipient)->toBe([]);
    }
});

it('bills month by month the closed months only, the fees with the first invoice', function () {
    $dossier = bcDossier([bcLine('l1', 100000), bcLine('f1', 15000, 'fee')], [bcEnrolment('e1', 'l1', 100000)],
        extra: ['sessions' => [['id' => 's1', 'starts_on' => '2026-09-01', 'ends_on' => '2026-11-30', 'status' => 'running']]]);
    // Hours read up to October (closed months only): 480 of 1 200 min.
    $hours = bcHours(['e1' => [1200, 480]], closed: ['s1' => ['2026-09', '2026-10']], allClosed: ['s1' => false]);

    $october = bcRun($dossier, $hours, bcPlan('monthly'), cut: CutOff::month('2026-10'));
    $november = bcRun($dossier, $hours, bcPlan('monthly'), cut: CutOff::month('2026-11'));

    expect($october->ready)->toBeTrue()
        ->and($october->billableCents)->toBe(40000 + 15000)
        ->and($november->ready)->toBeFalse()
        ->and($november->reason)->toBe('Mois non clôturé');
});

it('waits for the month to be closed on every session of the dossier', function () {
    $dossier = bcDossier([bcLine('l1', 200000)], [bcEnrolment('e1', 'l1', 100000), bcEnrolment('e2', 'l1', 100000, ['session_id' => 's2'])], extra: ['sessions' => [
        ['id' => 's1', 'starts_on' => '2026-09-01', 'ends_on' => '2026-11-30', 'status' => 'running'],
        ['id' => 's2', 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-15', 'status' => 'running'],
        ['id' => 's3', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-15', 'status' => 'planned'],
    ]]);
    $hours = ['enrolments' => [
        ['enrolment_id' => 'e1', 'session_id' => 's1', 'planned_minutes' => 1200, 'realised_minutes' => 600, 'absent_minutes' => 0, 'all_months_closed' => false, 'closed_months' => ['2026-09', '2026-10']],
        ['enrolment_id' => 'e2', 'session_id' => 's2', 'planned_minutes' => 1200, 'realised_minutes' => 0, 'absent_minutes' => 0, 'all_months_closed' => false, 'closed_months' => []],
    ], 'sessions' => []];

    expect(bcRun($dossier, $hours, bcPlan('monthly'), cut: CutOff::month('2026-10'))->reason)->toBe('Mois non clôturé');

    $hours['enrolments'][1]['closed_months'] = ['2026-10'];
    // s3 starts in December: it has nothing to close yet.
    expect(bcRun($dossier, $hours, bcPlan('monthly'), cut: CutOff::month('2026-10'))->ready)->toBeTrue();
});

it('bills a monthly plan on the realised hours, even a legacy plan on the planned basis', function () {
    $dossier = bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000)],
        extra: ['sessions' => [['id' => 's1', 'starts_on' => '2026-09-01', 'ends_on' => '2026-11-30', 'status' => 'running']]]);
    $hours = bcHours(['e1' => [1200, 300]], closed: ['s1' => ['2026-09']], allClosed: ['s1' => false]);

    $shares = bcRun($dossier, $hours, bcPlan('monthly', 'planned'), cut: CutOff::month('2026-09'));

    expect($shares->billableCents)->toBe(25000)
        ->and($shares->detail[0])->toMatchArray(['planned' => 1200, 'realised' => 300]);
});

it('bills a schedule: the contractor\'s planned part by percent, the funders at the last instalment, at the realised', function () {
    $dossier = bcDossier([bcLine('l1', 200000)], [bcEnrolment('e1', 'l1', 100000), bcEnrolment('e2', 'l1', 100000)], [bcFunding('f1', 120000)],
        ['sessions' => [['id' => 's1', 'starts_on' => '2026-09-01', 'ends_on' => '2026-11-30', 'status' => 'done']]]);
    $plan = bcPlan('schedule', 'realised', [['due_on' => '2026-09-01', 'percent' => 30], ['due_on' => '2026-12-01', 'percent' => 70]]);
    $hours = bcHours(['e1' => [1200, 600], 'e2' => [1200, 600]], allClosed: ['s1' => true]);

    $before = bcRun($dossier, $hours, $plan, cut: CutOff::on('2026-08-31'));
    $first = bcRun($dossier, $hours, $plan, cut: CutOff::on('2026-09-15'));
    $last = bcRun($dossier, $hours, $plan, cut: CutOff::on('2026-12-01'));

    expect($before->ready)->toBeFalse()
        ->and($before->reason)->toBe('Échéance non atteinte')
        // (2 000 € − 1 200 € planned for the OPCO) × 30 % = 240 €, nothing to the OPCO in advance.
        ->and($first->ready)->toBeTrue()
        ->and($first->billableCents)->toBe(24000)
        ->and(bcTraining($first))->toBe(['contractor' => 24000, 'funding:f1' => 0])
        // Last instalment at the realised (50 %): OPCO 600 €, contractor 400 €.
        ->and($last->billableCents)->toBe(100000)
        ->and(bcTraining($last))->toBe(['contractor' => 40000, 'funding:f1' => 60000]);

    // The last instalment reached before the training is over: not yet.
    $unfinished = bcRun($dossier, bcHours(['e1' => [1200, 600], 'e2' => [1200, 600]], allClosed: ['s1' => false]), $plan, cut: CutOff::on('2026-12-01'));
    expect($unfinished->reason)->toBe('Formation non terminée');
});

it('bills a planned schedule in full at its last instalment, even before the end', function () {
    $dossier = bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000)], [bcFunding('f1', 40000)],
        ['sessions' => [['id' => 's1', 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31', 'status' => 'running']]]);
    $plan = bcPlan('schedule', 'planned', [['due_on' => '2026-09-01', 'percent' => 50], ['due_on' => '2026-10-01', 'percent' => 50]]);

    $shares = bcRun($dossier, bcHours(['e1' => [1200, 0]], allClosed: ['s1' => false]), $plan, cut: CutOff::on('2026-10-15'));

    expect(bcTraining($shares))->toBe(['contractor' => 60000, 'funding:f1' => 40000]);
});

it('reads a cut-off as a Paris day or a month', function () {
    expect((array) CutOff::on('2026-10-15'))->toBe(['date' => '2026-10-15', 'month' => null])
        ->and((array) CutOff::month('2026-02'))->toBe(['date' => '2026-02-28', 'month' => '2026-02'])
        ->and(fn () => CutOff::on('15/10/2026'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CutOff::month('2026-13'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => bcRun(bcDossier([bcLine('l1', 100000)], []), bcHours([]), bcPlan('monthly'), cut: CutOff::on('2026-10-15')))
        ->toThrow(InvalidArgumentException::class);
});

// ------------------------------------------------------------ advances (acomptes)

it('flags the instalments before the last one as advances of the contractor', function () {
    $dossier = bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000)]);
    $plan = bcPlan('schedule', 'realised', [['due_on' => '2026-09-01', 'percent' => 30], ['due_on' => '2026-12-01', 'percent' => 70]]);

    $shares = bcRun($dossier, bcHours(['e1' => [1200, 600]]), $plan);

    expect($shares->byRecipient['contractor']['advance'])->toBeTrue()
        ->and(bcTraining($shares))->toBe(['contractor' => 30000]);
});

it('bills the advance of a funder before the training is over, the contractor nothing yet', function () {
    $opco = bcFunding('f1', 60000, ['advance_percent' => 30, 'advance_on' => '2026-09-01']);
    $dossier = bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000)], [$opco]);

    $shares = bcRun($dossier, bcHours(['e1' => [1200, 600]], [], ['s1' => []], ['s1' => false]));

    expect($shares->ready)->toBeTrue()
        ->and(bcTraining($shares))->toBe(['contractor' => 0, 'funding:f1' => 18000])
        ->and($shares->byRecipient['funding:f1']['advance'])->toBeTrue()
        ->and($shares->billableCents)->toBe(18000)
        ->and(array_sum(array_column($shares->detail, 'amount_cents')))->toBeGreaterThan(0);
});

it('waits for the date of the advance', function () {
    $opco = bcFunding('f1', 60000, ['advance_percent' => 30, 'advance_on' => '2026-11-01']);
    $dossier = bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000)], [$opco]);

    $shares = bcRun($dossier, bcHours(['e1' => [1200, 600]], [], ['s1' => []], ['s1' => false]));

    expect($shares->ready)->toBeFalse()->and($shares->reason)->toBe(BillingCalculator::NOT_FINISHED);
});

it('bills the funder at the realised once the training is over, whatever its advance', function () {
    $opco = bcFunding('f1', 60000, ['advance_percent' => 30, 'advance_on' => '2026-09-01']);
    $dossier = bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000)], [$opco]);

    $shares = bcRun($dossier, bcHours(['e1' => [1200, 1200]]));

    expect(bcTraining($shares))->toBe(['contractor' => 40000, 'funding:f1' => 60000])
        ->and($shares->byRecipient['funding:f1']['advance'])->toBeFalse();
});

it('keeps at least the advance of a funder in a month billed before the end', function () {
    $opco = bcFunding('f1', 60000, ['advance_percent' => 50, 'advance_on' => '2026-09-01']);
    $dossier = bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000)], [$opco],
        ['sessions' => [['id' => 's1', 'starts_on' => '2026-09-01', 'ends_on' => '2026-10-31', 'status' => 'planned']]]);

    $shares = bcRun($dossier, bcHours(['e1' => [1200, 240]], [], ['s1' => ['2026-09']], ['s1' => false]), bcPlan('monthly'), cut: CutOff::month('2026-09'));

    // Realised 20 %: 200 €, the OPCO 120 € of it, raised to its advance (300 €); the contractor keeps 80 €.
    expect(bcTraining($shares))->toBe(['contractor' => 8000, 'funding:f1' => 30000])
        ->and($shares->byRecipient['funding:f1']['advance'])->toBeTrue()
        ->and($shares->byRecipient['contractor']['advance'])->toBeFalse();
});

it('gives the funder its advance on an instalment of a schedule', function () {
    $opco = bcFunding('f1', 60000, ['advance_percent' => 30, 'advance_on' => '2026-09-01']);
    $dossier = bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000)], [$opco]);
    $plan = bcPlan('schedule', 'realised', [['due_on' => '2026-09-01', 'percent' => 30], ['due_on' => '2026-12-01', 'percent' => 70]]);

    $shares = bcRun($dossier, bcHours(['e1' => [1200, 600]]), $plan);

    // Contractor: (1 000 − 600) × 30 % = 120 €; OPCO: 600 × 30 % = 180 €.
    expect(bcTraining($shares))->toBe(['contractor' => 12000, 'funding:f1' => 18000]);
});

it('never asks an advance of a funding not agreed', function () {
    $opco = bcFunding('f1', 60000, ['status' => 'requested', 'advance_percent' => 30, 'advance_on' => '2026-09-01']);
    $dossier = bcDossier([bcLine('l1', 100000)], [bcEnrolment('e1', 'l1', 100000)], [$opco]);

    expect(bcRun($dossier, bcHours(['e1' => [1200, 600]], [], ['s1' => []], ['s1' => false]))->ready)->toBeFalse();
});
