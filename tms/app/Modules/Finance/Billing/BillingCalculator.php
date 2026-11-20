<?php

namespace App\Modules\Finance\Billing;

use App\Modules\Finance\Plans\BillingPlans;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * What a dossier owes up to a cut-off, cumulated, per recipient (spec 0.9
 * § 4.3 and § 4 bis): a pure computation (no database, no clock) on the
 * arrays of Sessions\Public\Billing::dossier() and ::hours() - hours read up
 * to the cut-off month for « monthly » - and the plan of BillingPlans::for().
 * Integer cents only (brick/math, half up), never a float.
 *
 * Billable B(D), the training part:
 *  - basis « realised »: a line priced per trainee = Σ its enrolments' price
 *    share × realised / planned minutes (capped at 1), each rounded to the
 *    cent; a line priced for the group = its total × realised / planned
 *    minutes of its sessions; a fee line in full;
 *  - basis « planned »: the price (« end », the last instalment of a
 *    schedule); « monthly » is billed on the realised basis only
 *    (BillingPlans refuses it planned; a legacy monthly plan on the planned
 *    basis is computed at the realised hours);
 *  - an abandon (« prorata_dedit », not force majeure, left on or before
 *    the cut-off), realised basis: a dédit of the percent of the share not
 *    done, to the contractor alone, apart from B;
 *  - unjustified absences, realised basis and « bill_contractor » only: the
 *    trainee's share × absent / planned minutes (at most what is left of his
 *    share), to the contractor alone, apart from B; never for a trainee who
 *    left;
 *  - dédits and absences concern the lines priced per trainee only: a line
 *    priced for the group (billed on the séances held) has neither.
 *
 * Shares of B: each agreed funding (its funder not the contractor) paying
 * directly or billed outside the TMS (external: its part leaves the
 * contractor's invoice, the TMS never invoices it) takes min(amount,
 * amount × billable of its target / price of its target), its target being
 * the enrolment or the line it funds, else the dossier; capped at what is
 * left of its target and of B, in the order of the fundings. The contractor
 * takes the rest, rounding remainders included: the shares sum up to B.
 *
 * Rhythms and cut-off (not ready: no amount, a French reason):
 *  - « end »: every session (not cancelled) over before the cut-off day and
 *    all its months closed, else « Formation non terminée »;
 *  - « monthly » (CutOff::month()): the months of every session up to the
 *    cut-off month closed, else « Mois non clôturé »;
 *  - « schedule »: the percents of the instalments due on or before the
 *    cut-off day (none: « Échéance non atteinte »); before the last one,
 *    the contractor alone, (price − the funders' planned shares) × percent
 *    (the funders are never billed in advance, listed at 0): B is that
 *    contractor part, while the detail gives each line's total × percent
 *    (so the detail does not add up to B); at the last one, as « end » on a
 *    realised basis (training over), the planned price otherwise.
 *
 * @phpstan-import-type Detail from Shares
 * @phpstan-import-type RecipientShare from Shares
 */
final class BillingCalculator
{
    public const MONTH_NOT_CLOSED = 'Mois non clôturé';

    public const NOT_FINISHED = 'Formation non terminée';

    public const NOT_DUE = 'Échéance non atteinte';

    private const REALISED = 'realised';

    private const PLANNED = 'planned';

    /**
     * @param  array<string, mixed>  $dossier  Billing::dossier()
     * @param  array{enrolments: list<array<string, mixed>>, sessions: list<array<string, mixed>>}  $hours  Billing::hours(), up to $cut->month for « monthly »
     * @param  array<string, mixed>  $plan  BillingPlans::for()
     * @param  string  $absencesRule  « not_billed » or « bill_contractor »
     *
     * @throws InvalidArgumentException a « monthly » plan cut on a day
     */
    public static function cumulative(array $dossier, array $hours, array $plan, string $absencesRule, CutOff $cut): Shares
    {
        $planned = $plan['basis'] === BillingPlans::PLANNED;

        if ($plan['rhythm'] === BillingPlans::MONTHLY) {
            if ($cut->month === null) {
                throw new InvalidArgumentException('A monthly plan is cut on a month (CutOff::month()).');
            }

            return self::monthClosed($dossier, $hours, $cut->month)
                ? self::compute($dossier, $hours, self::REALISED, $absencesRule, $cut)
                : Shares::notReady(self::MONTH_NOT_CLOSED);
        }

        if ($plan['rhythm'] === BillingPlans::SCHEDULE) {
            $percent = 0;
            foreach ($plan['schedule'] as $instalment) {
                if ($instalment['due_on'] <= $cut->date) {
                    $percent += (int) $instalment['percent'];
                }
            }
            if ($percent === 0) {
                return Shares::notReady(self::NOT_DUE);
            }
            if ($percent < 100) {
                return self::instalment($dossier, $percent, $cut);
            }
        }

        if ($planned && $plan['rhythm'] === BillingPlans::SCHEDULE) {
            return self::compute($dossier, $hours, self::PLANNED, $absencesRule, $cut);
        }

        return self::finished($dossier, $hours, $cut)
            ? self::compute($dossier, $hours, $planned ? self::PLANNED : self::REALISED, $absencesRule, $cut)
            : Shares::notReady(self::NOT_FINISHED);
    }

    /**
     * B, the dédits, the absences and the shares of the recipients.
     *
     * @param  array<string, mixed>  $dossier
     * @param  array{enrolments: list<array<string, mixed>>, sessions: list<array<string, mixed>>}  $hours
     */
    private static function compute(array $dossier, array $hours, string $measure, string $absencesRule, CutOff $cut): Shares
    {
        $byEnrolment = array_column($hours['enrolments'], null, 'enrolment_id');
        $bySession = array_column($hours['sessions'], null, 'session_id');
        $deditPercent = ($dossier['abandon']['mode'] ?? null) === 'prorata_dedit' ? (int) $dossier['abandon']['dedit_percent'] : 0;
        $billAbsences = $measure === self::REALISED && $absencesRule === BillingPlans::BILL_CONTRACTOR;

        $billable = 0;
        $dedit = 0;
        $absences = 0;
        $detail = [];
        /** @var array<string, array{0: int, 1: int}> $lineTargets billable, price */
        $lineTargets = [];
        /** @var array<string, array{0: int, 1: int}> $enrolmentTargets billable, price share */
        $enrolmentTargets = [];

        foreach ($dossier['lines'] as $line) {
            $total = (int) $line['total_cents'];
            $amount = 0;

            if ($line['kind'] === 'fee' || $measure === self::PLANNED) {
                $amount = $total;
                $detail[] = self::detail($line['id'], null, null, null, '1.0000', $amount);
                foreach ($dossier['enrolments'] as $e) {
                    if ($e['line_id'] === $line['id'] && $e['price_share_cents'] !== null) {
                        $enrolmentTargets[$e['id']] = [(int) $e['price_share_cents'], (int) $e['price_share_cents']];
                    }
                }
            } elseif ($line['pricing_mode'] === 'group') {
                $planned = 0;
                $done = 0;
                foreach ($line['session_ids'] as $sessionId) {
                    $session = $bySession[$sessionId] ?? null;
                    if ($session !== null) {
                        $planned += (int) $session['planned_minutes'];
                        $done += (int) $session['realised_minutes'];
                    }
                }
                $amount = self::prorate($total, min($done, $planned), $planned);
                $detail[] = self::detail($line['id'], null, $planned, $done, self::ratio(min($done, $planned), $planned), $amount);
            } else {
                foreach ($dossier['enrolments'] as $e) {
                    if ($e['line_id'] !== $line['id']) {
                        continue;
                    }
                    $share = (int) ($e['price_share_cents'] ?? 0);
                    $h = $byEnrolment[$e['id']] ?? ['planned_minutes' => 0, 'realised_minutes' => 0, 'absent_minutes' => 0];
                    $planned = (int) $h['planned_minutes'];
                    $done = (int) $h['realised_minutes'];
                    $part = self::prorate($share, min($done, $planned), $planned);

                    $eDedit = 0;
                    $eAbsences = 0;
                    if ($e['abandoned'] && $deditPercent > 0 && ! $e['force_majeure']
                        && ($e['left_on'] === null || $e['left_on'] <= $cut->date)) {
                        $eDedit = self::prorate($share - $part, $deditPercent, 100);
                    }
                    if ($billAbsences && ! $e['abandoned']) {
                        $eAbsences = min(self::prorate($share, (int) $h['absent_minutes'], $planned), $share - $part);
                    }

                    $enrolmentTargets[$e['id']] = [$part, $share];
                    $detail[] = self::detail($line['id'], $e['id'], $planned, $done, self::ratio(min($done, $planned), $planned), $part, $eDedit, $eAbsences);
                    $amount += $part;
                    $dedit += $eDedit;
                    $absences += $eAbsences;
                }
            }

            $lineTargets[$line['id']] = [$amount, $total];
            $billable += $amount;
        }

        $funders = self::funderShares($dossier, $billable, $lineTargets, $enrolmentTargets);

        return new Shares(true, null, $billable, $dedit, $absences, self::recipients($dossier, $billable, $funders, $dedit, $absences), $detail);
    }

    /**
     * An instalment before the last one: the contractor's planned part ×
     * the percent reached, nothing to the funders (never billed in advance).
     * The detail gives each line's planned total × the percent.
     *
     * @param  array<string, mixed>  $dossier
     */
    private static function instalment(array $dossier, int $percent, CutOff $cut): Shares
    {
        $planned = self::compute($dossier, ['enrolments' => [], 'sessions' => []], self::PLANNED, BillingPlans::NOT_BILLED, $cut);
        $contractor = self::prorate($planned->byRecipient['contractor']['training_cents'], $percent, 100);
        $recipients = array_map(fn (array $r) => [...$r, 'training_cents' => 0], $planned->byRecipient);
        $recipients['contractor']['training_cents'] = $contractor;
        $ratio = self::ratio($percent, 100);
        $detail = array_map(fn (array $d) => [...$d, 'ratio' => $ratio, 'amount_cents' => self::prorate($d['amount_cents'], $percent, 100)], $planned->detail);

        return new Shares(true, null, $contractor, 0, 0, $recipients, $detail);
    }

    /**
     * The share of B of each funding taking one, by funding id.
     *
     * @param  array<string, mixed>  $dossier
     * @param  array<string, array{0: int, 1: int}>  $lineTargets
     * @param  array<string, array{0: int, 1: int}>  $enrolmentTargets
     * @return array<string, array{funding: array<string, mixed>, cents: int}>
     */
    private static function funderShares(array $dossier, int $billable, array $lineTargets, array $enrolmentTargets): array
    {
        $contractor = $dossier['contractor'];
        $left = $billable;
        $targetLeft = [];
        $shares = [];

        foreach ($dossier['fundings'] as $f) {
            $isContractor = $f['funder']['type'] === $contractor['type'] && $f['funder']['id'] === $contractor['id'];
            if ($f['status'] !== 'agreed' || $isContractor || (! $f['pays_directly'] && ! $f['external_billing'])) {
                continue;
            }
            [$key, [$target, $base]] = match (true) {
                $f['enrolment_id'] !== null => ['e:'.$f['enrolment_id'], $enrolmentTargets[$f['enrolment_id']] ?? [0, 0]],
                $f['line_id'] !== null => ['l:'.$f['line_id'], $lineTargets[$f['line_id']] ?? [0, 0]],
                default => ['dossier', [$billable, (int) $dossier['price_cents']]],
            };
            $amount = (int) $f['amount_cents'];
            $cents = $base > 0 ? min($amount, self::prorate($amount, $target, $base)) : 0;
            $cents = max(0, min($cents, $targetLeft[$key] ?? $target, $left));
            $targetLeft[$key] = ($targetLeft[$key] ?? $target) - $cents;
            $left -= $cents;
            $shares[$f['id']] = ['funding' => $f, 'cents' => $cents];
        }

        return $shares;
    }

    /**
     * @param  array<string, mixed>  $dossier
     * @param  array<string, array{funding: array<string, mixed>, cents: int}>  $funders
     * @return array<string, RecipientShare>
     */
    private static function recipients(array $dossier, int $billable, array $funders, int $dedit, int $absences): array
    {
        $recipients = ['contractor' => [
            'recipient' => ['type' => 'contractor', 'id' => (string) $dossier['contractor']['id'], 'fiche_type' => (string) $dossier['contractor']['type']],
            'training_cents' => $billable - array_sum(array_column($funders, 'cents')),
            'dedit_cents' => $dedit,
            'absences_cents' => $absences,
            'external' => false,
        ]];
        foreach ($funders as $id => $share) {
            $f = $share['funding'];
            $recipients["funding:{$id}"] = [
                'recipient' => ['type' => 'funder', 'id' => (string) $f['funder']['id'], 'fiche_type' => (string) $f['funder']['type'], 'funding_id' => (string) $id],
                'training_cents' => $share['cents'],
                'dedit_cents' => 0,
                'absences_cents' => 0,
                'external' => (bool) $f['external_billing'],
            ];
        }

        return $recipients;
    }

    /**
     * Every session (not cancelled) has its months up to $month closed.
     *
     * @param  array<string, mixed>  $dossier
     * @param  array{enrolments: list<array<string, mixed>>, sessions: list<array<string, mixed>>}  $hours
     */
    private static function monthClosed(array $dossier, array $hours, string $month): bool
    {
        $closed = [];
        foreach ([...$hours['enrolments'], ...$hours['sessions']] as $row) {
            $closed[$row['session_id']] = [...($closed[$row['session_id']] ?? []), ...$row['closed_months']];
        }
        foreach ($dossier['sessions'] as $session) {
            if ($session['status'] === 'cancelled') {
                continue;
            }
            $last = min(substr($session['ends_on'], 0, 7), $month);
            for ($m = substr($session['starts_on'], 0, 7); $m <= $last; $m = self::nextMonth($m)) {
                if (! in_array($m, $closed[$session['id']] ?? [], true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Every session (not cancelled) ended before the cut-off day, all its months closed.
     *
     * @param  array<string, mixed>  $dossier
     * @param  array{enrolments: list<array<string, mixed>>, sessions: list<array<string, mixed>>}  $hours
     */
    private static function finished(array $dossier, array $hours, CutOff $cut): bool
    {
        $allClosed = [];
        foreach ([...$hours['enrolments'], ...$hours['sessions']] as $row) {
            $allClosed[$row['session_id']] = (bool) $row['all_months_closed'];
        }
        foreach ($dossier['sessions'] as $session) {
            if ($session['status'] !== 'cancelled' && ($session['ends_on'] >= $cut->date || ! ($allClosed[$session['id']] ?? false))) {
                return false;
            }
        }

        return true;
    }

    /** round_half_up($cents × $numerator / $denominator), 0 when the denominator is not positive. */
    private static function prorate(int $cents, int $numerator, int $denominator): int
    {
        return $denominator > 0
            ? BigInteger::of($cents)->multipliedBy($numerator)->dividedBy($denominator, RoundingMode::HalfUp)->toInt()
            : 0;
    }

    /** « 0.7000 » */
    private static function ratio(int $numerator, int $denominator): string
    {
        return $denominator > 0
            ? (string) BigDecimal::of($numerator)->dividedBy($denominator, 4, RoundingMode::HalfUp)
            : '0.0000';
    }

    private static function nextMonth(string $month): string
    {
        [$year, $m] = array_map('intval', explode('-', $month));

        return $m === 12 ? sprintf('%04d-01', $year + 1) : sprintf('%04d-%02d', $year, $m + 1);
    }

    /**
     * @return Detail
     */
    private static function detail(string $lineId, ?string $enrolmentId, ?int $planned, ?int $realised, string $ratio, int $amount, int $dedit = 0, int $absences = 0): array
    {
        return ['line_id' => $lineId, 'enrolment_id' => $enrolmentId, 'planned' => $planned, 'realised' => $realised, 'ratio' => $ratio,
            'amount_cents' => $amount, 'dedit_cents' => $dedit, 'absences_cents' => $absences];
    }
}
