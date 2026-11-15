<?php

namespace App\Modules\Crm\Revenue;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Visibility\CrmVisibility;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Revenue (chiffre d'affaires) by DAS over a period, with the target of that
 * exact period type: one aggregated query per source, amounts in integer
 * cents (summed with brick/math, never floats; the rate alone is a float).
 *
 * Rows: every active DAS of the reference list the user may see (zeros
 * included), plus a deactivated DAS holding revenue or a target on the
 * period, in the order of the list. « Ses DAS » keeps the followed DAS rows
 * (computed on the whole organisme); « Ses fiches » counts what is his only.
 */
final class DasRevenue
{
    /**
     * @return array{
     *     rows: list<array{das_id: string, das_label: string, quotes_cents: int, opportunities_cents: int, total_cents: int, target_cents: int|null, rate: float|null}>,
     *     totals: array{quotes_cents: int, opportunities_cents: int, total_cents: int, target_cents: int|null, targeted_total_cents: int, rate: float|null}
     * }
     *
     * totals.rate compares the target with targeted_total_cents, the revenue
     * of the DAS that have a target only (null without any target).
     */
    public static function for(User $user, Period $period): array
    {
        $scope = RevenueSources::scope($user);

        $quotes = self::sums(RevenueSources::quoteLines($user, $period)
            ->selectRaw(RevenueSources::QUOTE_DAS.' as das, sum(round(quote_lines.total_ht * 100)::bigint) as cents')
            ->groupByRaw(RevenueSources::QUOTE_DAS)
            ->get());
        $opportunities = self::sums(RevenueSources::wonOpportunities($user, $period)
            ->selectRaw('opportunities.das_id as das, sum(round(opportunities.amount * 100)::bigint) as cents')
            ->groupBy('opportunities.das_id')
            ->get());
        $targets = self::sums(DB::table('revenue_targets')
            ->where('period_type', $period->type)
            ->whereDate('period_start', $period->start->toDateString())
            ->selectRaw('das_id as das, round(amount * 100)::bigint as cents')
            ->get());

        $followed = $scope === AccessScope::Das ? CrmVisibility::followedDas($user) : null;
        $rows = [];
        $totals = [BigInteger::zero(), BigInteger::zero(), null];
        $targeted = BigInteger::zero();

        foreach (ReferenceItem::query()->where('list', ReferenceList::Das)->orderBy('position')->orderBy('label')->get() as $das) {
            $id = (string) $das->getKey();
            if ($followed !== null && ! in_array($id, $followed, true)) {
                continue;
            }
            $used = isset($quotes[$id]) || isset($opportunities[$id]) || isset($targets[$id]);
            if (! $das->is_active && ! $used) {
                continue;
            }

            $q = $quotes[$id] ?? BigInteger::zero();
            $o = $opportunities[$id] ?? BigInteger::zero();
            $total = $q->plus($o);
            $target = $targets[$id] ?? null;

            $rows[] = [
                'das_id' => $id,
                'das_label' => $das->label,
                'quotes_cents' => $q->toInt(),
                'opportunities_cents' => $o->toInt(),
                'total_cents' => $total->toInt(),
                'target_cents' => $target?->toInt(),
                'rate' => $target === null || $target->isZero() ? null : self::rate($total, $target),
            ];
            $totals = [$totals[0]->plus($q), $totals[1]->plus($o), $target === null ? $totals[2] : ($totals[2] ?? BigInteger::zero())->plus($target)];
            if ($target !== null) {
                $targeted = $targeted->plus($total);
            }
        }

        return [
            'rows' => $rows,
            'totals' => [
                'quotes_cents' => $totals[0]->toInt(),
                'opportunities_cents' => $totals[1]->toInt(),
                'total_cents' => $totals[0]->plus($totals[1])->toInt(),
                'target_cents' => $totals[2]?->toInt(),
                'targeted_total_cents' => $targeted->toInt(),
                'rate' => $totals[2] === null || $totals[2]->isZero() ? null : self::rate($targeted, $totals[2]),
            ],
        ];
    }

    /**
     * Percent of the target reached, one decimal, half up.
     */
    private static function rate(BigInteger $total, BigInteger $target): float
    {
        return $total->multipliedBy(100)->toBigDecimal()->dividedBy($target, 1, RoundingMode::HalfUp)->toFloat();
    }

    /**
     * @param  iterable<object>  $rows  das, cents
     * @return array<string, BigInteger>
     */
    private static function sums(iterable $rows): array
    {
        $sums = [];
        foreach ($rows as $row) {
            if ($row->das === null) {
                continue;
            }
            $sums[(string) $row->das] = ($sums[(string) $row->das] ?? BigInteger::zero())->plus(BigInteger::of((string) $row->cents));
        }

        return $sums;
    }
}
