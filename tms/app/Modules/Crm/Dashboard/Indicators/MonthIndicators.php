<?php

namespace App\Modules\Crm\Dashboard\Indicators;

use App\Modules\Catalogue\Trainings;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Dashboard\DashboardFilters;
use App\Modules\Crm\Dashboard\Indicator;
use App\Modules\Crm\Dashboard\IndicatorValue;
use App\Modules\Crm\Dashboard\Sources;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Revenue\DasRevenue;
use App\Modules\Crm\Revenue\Period;
use Illuminate\Support\Facades\DB;

/**
 * Cards of the month / direction tab (lot 1e): conversion, forecast, sales
 * cycle, trainings sold, entreprises / particuliers, lead origins, loss
 * reasons and revenue by DAS (formulas: spec lot 1e § 3.2).
 */
final class MonthIndicators extends BaseIndicator
{
    /** @return list<self> */
    public static function all(): array
    {
        $m = Indicator::MONTH;

        return [
            new self('crm.das_revenue', $m, 'CA signé par DAS', 'Devis acceptés (par ligne), sinon opportunités gagnées sans devis accepté ; objectifs du CA par DAS', Permissions::REVENUE_VIEW),
            new self('crm.conversion', $m, 'Taux de conversion', 'Opportunités gagnées / opportunités créées dans la période', Permissions::OPPORTUNITIES_VIEW),
            new self('crm.forecast', $m, 'CA prévisionnel', 'Somme des montants des opportunités ouvertes à clôturer dans la période', Permissions::OPPORTUNITIES_VIEW),
            new self('crm.sales_cycle', $m, 'Durée du cycle de vente', 'Moyenne des jours entre création et gain des opportunités gagnées dans la période', Permissions::OPPORTUNITIES_VIEW),
            new self('crm.trainings_sold', $m, 'Formations vendues', 'Lignes de formation des devis acceptés, sinon formations des opportunités gagnées sans devis', Permissions::OPPORTUNITIES_VIEW),
            new self('crm.target_split', $m, 'Entreprises / particuliers', 'CA signé de la période par cible', Permissions::REVENUE_VIEW),
            new self('crm.lead_origins', $m, 'Origine des leads', 'CA signé de la période par origine de la fiche', Permissions::REVENUE_VIEW),
            new self('crm.loss_reasons', $m, 'Motifs de perte', 'Opportunités perdues dans la période, par motif', Permissions::OPPORTUNITIES_VIEW),
        ];
    }

    public function value(DashboardFilters $f, User $user): IndicatorValue
    {
        $from = $f->from->toDateString();
        $to = $f->to->toDateString();

        return match ($this->key()) {
            'crm.conversion' => $this->conversion($f, $user),
            'crm.forecast' => new IndicatorValue((int) Sources::opportunities($f, $user)->whereNull('opportunities.won_on')->whereNull('opportunities.lost_on')
                ->whereBetween('opportunities.expected_close_on', [$from, $to])->sum(DB::raw('round(coalesce(opportunities.amount, 0) * 100)')), IndicatorValue::CENTS),
            'crm.sales_cycle' => $this->cycle($f, $user),
            'crm.trainings_sold' => $this->trainingsSold($f, $user),
            'crm.target_split' => $this->targetSplit($f, $user),
            'crm.lead_origins' => $this->origins($f, $user),
            'crm.loss_reasons' => $this->losses($f, $user),
            default => $this->dasRevenue($f, $user),
        };
    }

    public function detailUrl(DashboardFilters $f): string
    {
        return match ($this->key()) {
            'crm.das_revenue' => '/crm/revenue',
            'crm.forecast' => Sources::opportunityListUrl($f, ['close_from' => $f->from->toDateString(), 'close_to' => $f->to->toDateString()]),
            default => Sources::opportunityListUrl($f),
        };
    }

    private function conversion(DashboardFilters $f, User $user): IndicatorValue
    {
        $created = Sources::opportunities($f, $user)->whereBetween('opportunities.created_at', [$f->start(), $f->end()]);

        return new IndicatorValue(self::rate((clone $created)->whereNotNull('opportunities.won_on')->count(), $created->count()), IndicatorValue::PERCENT);
    }

    private function cycle(DashboardFilters $f, User $user): IndicatorValue
    {
        $days = Sources::opportunities($f, $user)->whereBetween('opportunities.won_on', [$f->from->toDateString(), $f->to->toDateString()])
            ->get()->map(fn ($o) => $o->cycleDays())->filter(fn ($d) => $d !== null)->sort()->values();
        if ($days->isEmpty()) {
            return new IndicatorValue(null, IndicatorValue::DAYS);
        }
        $n = $days->count();
        $median = $n % 2 === 1 ? $days[intdiv($n, 2)] : ($days[$n / 2 - 1] + $days[$n / 2]) / 2;

        return new IndicatorValue(round($days->avg(), 1), IndicatorValue::DAYS, note: 'Médiane : '.str_replace('.', ',', (string) $median).' j');
    }

    private function trainingsSold(DashboardFilters $f, User $user): IndicatorValue
    {
        $signed = Sources::signed($f, $user)->pluck('opportunity_id')->all();
        $quoted = DB::table('quote_lines')->join('quotes', 'quotes.id', '=', 'quote_lines.quote_id')
            ->whereIn('quotes.opportunity_id', $signed)->where('quotes.status', 'accepted')->whereNull('quotes.deleted_at')
            ->whereBetween('quotes.accepted_on', [$f->from->toDateString(), $f->to->toDateString()])
            ->whereNotNull('quote_lines.training_id')->pluck('quote_lines.training_id');
        $withQuote = DB::table('quotes')->whereIn('opportunity_id', $signed)->where('status', 'accepted')->whereNull('deleted_at')->pluck('opportunity_id')->all();
        $fromOpportunities = DB::table('opportunity_trainings')->whereIn('opportunity_id', array_diff($signed, $withQuote))->pluck('training_id');
        $counts = $quoted->merge($fromOpportunities)->countBy()->sortDesc();
        $titles = app(Trainings::class)->findMany($counts->keys()->all());
        $rows = $counts->map(fn (int $n, string $id) => ['label' => $titles[$id]->title ?? 'Formation supprimée', 'value' => $n])
            ->sortBy([['value', 'desc'], ['label', 'asc']])->values()->all();

        return new IndicatorValue((int) $counts->sum(), IndicatorValue::COUNT, $rows);
    }

    private function targetSplit(DashboardFilters $f, User $user): IndicatorValue
    {
        $signed = Sources::signed($f, $user);
        $orgs = (int) $signed->whereNotNull('organisation_id')->sum('cents');
        $people = (int) $signed->whereNull('organisation_id')->sum('cents');

        return new IndicatorValue($orgs + $people, IndicatorValue::CENTS, [['label' => 'Entreprises', 'value' => $orgs], ['label' => 'Particuliers', 'value' => $people]],
            note: $signed->whereNotNull('organisation_id')->count().' vente(s) entreprises, '.$signed->whereNull('organisation_id')->count().' particuliers');
    }

    private function origins(DashboardFilters $f, User $user): IndicatorValue
    {
        $signed = Sources::signed($f, $user);
        $orgOrigins = Organisation::withTrashed()->whereIn('id', $signed->pluck('organisation_id')->filter()->all())->pluck('origin_id', 'id');
        $personOrigins = Person::withTrashed()->whereIn('id', $signed->pluck('person_id')->filter()->all())->pluck('origin_id', 'id');
        $labels = ReferenceItem::withTrashed()->whereIn('id', [...$orgOrigins->filter()->values(), ...$personOrigins->filter()->values()])->pluck('label', 'id');
        $sums = [];
        foreach ($signed as $row) {
            $origin = $row->organisation_id !== null ? ($orgOrigins[$row->organisation_id] ?? null) : ($personOrigins[$row->person_id] ?? null);
            $label = $origin === null ? 'Non renseignée' : ($labels[$origin] ?? 'Non renseignée');
            $sums[$label] = ($sums[$label] ?? 0) + $row->cents;
        }
        arsort($sums);
        $rows = array_map(fn (string $label, int $cents) => ['label' => $label, 'value' => $cents], array_keys($sums), $sums);
        // « Non renseignée » last.
        usort($rows, fn ($a, $b) => [$a['label'] === 'Non renseignée', -$a['value']] <=> [$b['label'] === 'Non renseignée', -$b['value']]);

        return new IndicatorValue(array_sum($sums), IndicatorValue::CENTS, $rows);
    }

    private function losses(DashboardFilters $f, User $user): IndicatorValue
    {
        $lost = Sources::opportunities($f, $user)->whereBetween('opportunities.lost_on', [$f->from->toDateString(), $f->to->toDateString()])
            ->get(['opportunities.loss_reason_id']);
        $labels = ReferenceItem::withTrashed()->whereIn('id', $lost->pluck('loss_reason_id')->filter()->unique()->all())->pluck('label', 'id');
        $rows = $lost->countBy(fn ($o) => $o->loss_reason_id === null ? 'Sans motif' : ($labels[$o->loss_reason_id] ?? 'Sans motif'))
            ->map(fn (int $n, string $label) => ['label' => $label, 'value' => $n])->sortBy([['value', 'desc'], ['label', 'asc']])->values()->all();

        return new IndicatorValue($lost->count(), IndicatorValue::COUNT, $rows);
    }

    private function dasRevenue(DashboardFilters $f, User $user): IndicatorValue
    {
        $period = match ($f->preset) {
            'this_month', 'last_month' => Period::of('month', $f->from->format('Y-m')),
            'this_quarter' => Period::of('quarter', $f->from->year.'-T'.(intdiv($f->from->month - 1, 3) + 1)),
            'this_year' => Period::of('year', (string) $f->from->year),
            default => Period::range($f->from, $f->to),
        };
        $revenue = DasRevenue::for($user, $period);
        $rows = collect($revenue['rows'])->filter(fn (array $r) => $f->das === null || $r['das_id'] === $f->das);
        $targets = $rows->pluck('target_cents')->filter(fn ($t) => $t !== null);

        return new IndicatorValue((int) $rows->sum('total_cents'), IndicatorValue::CENTS,
            $rows->map(fn (array $r) => ['label' => $r['das_label'], 'value' => $r['total_cents']])->values()->all(),
            $targets->isEmpty() ? null : (int) $targets->sum());
    }
}
