<?php

namespace App\Modules\Crm\Revenue;

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Visibility\CrmVisibility;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * What the revenue is made of, and the detail of one DAS line.
 *
 *  - quotes: lines of the accepted quotes (accepted_on in the period, quote
 *    and opportunity not trashed), by line DAS, else the DAS of the
 *    opportunity;
 *  - opportunities: won (stage meta.outcome = won, won_on in the period,
 *    not trashed, amount set) with no accepted quote at all, by their DAS.
 *
 * Scope of crm.revenue.view: « Tout l'organisme » everything; « Ses DAS »
 * everything of the organisme (the caller keeps the followed DAS only);
 * « Ses fiches » only what is his (CrmVisibility). Amounts are summed in
 * cents, integers only.
 */
final class RevenueSources
{
    /** Line DAS of a quote line, else the DAS of its opportunity. */
    public const QUOTE_DAS = 'coalesce(quote_lines.das_id, opportunities.das_id)';

    /** Accepted quote lines, one row per line (joined to their opportunity). */
    public static function quoteLines(User $user, Period $period): QueryBuilder
    {
        $query = Quote::query()
            ->join('opportunities', 'opportunities.id', '=', 'quotes.opportunity_id')
            ->join('quote_lines', 'quote_lines.quote_id', '=', 'quotes.id')
            ->whereNull('opportunities.deleted_at')
            ->where('quotes.status', 'accepted')
            ->whereBetween('quotes.accepted_on', [$period->start->toDateString(), $period->end->toDateString()]);

        return self::ownQuotes($query, $user)->toBase();
    }

    /** Won opportunities of the period without any accepted quote. */
    public static function wonOpportunities(User $user, Period $period): QueryBuilder
    {
        $query = Opportunity::query()
            ->join('reference_items as stages', 'stages.id', '=', 'opportunities.stage_id')
            ->whereRaw("stages.meta->>'outcome' = 'won'")
            ->whereBetween('opportunities.won_on', [$period->start->toDateString(), $period->end->toDateString()])
            ->whereNotNull('opportunities.amount')
            ->whereNotExists(fn ($sub) => $sub
                ->select(DB::raw(1))
                ->from('quotes')
                ->whereColumn('quotes.opportunity_id', 'opportunities.id')
                ->where('quotes.status', 'accepted')
                ->whereNull('quotes.deleted_at'));

        return self::ownOpportunities($query, $user)->toBase();
    }

    /**
     * Scope of the revenue right; 403 without it.
     */
    public static function scope(User $user): AccessScope
    {
        $scope = Scopes::of($user, Permissions::REVENUE_VIEW);
        abort_if($scope === null, 403);

        return $scope;
    }

    /**
     * Quotes and opportunities making a DAS line, oldest first.
     *
     * @return list<array{kind: 'quote'|'opportunity', id: string, number_or_title: string, account_label: string, date: string, amount_cents: int}>
     */
    public static function for(User $user, Period $period, string $dasId): array
    {
        $scope = self::scope($user);
        if ($scope === AccessScope::Das && ! in_array($dasId, CrmVisibility::followedDas($user), true)) {
            return [];
        }

        $label = fn (string $table) => "coalesce({$table}_org.name, nullif(trim(concat_ws(' ', {$table}_person.first_name, {$table}_person.last_name)), ''), '')";

        $quotes = self::quoteLines($user, $period)
            ->whereRaw(self::QUOTE_DAS.' = ?', [$dasId])
            ->leftJoin('organisations as q_org', 'q_org.id', '=', 'quotes.organisation_id')
            ->leftJoin('people as q_person', 'q_person.id', '=', 'quotes.person_id')
            ->groupBy('quotes.id', 'quotes.number', 'quotes.accepted_on', 'q_org.name', 'q_person.first_name', 'q_person.last_name')
            ->selectRaw('quotes.id, quotes.number as title, quotes.accepted_on as day, '.$label('q').' as account, sum(round(quote_lines.total_ht * 100)::bigint) as cents')
            ->get();

        $opportunities = self::wonOpportunities($user, $period)
            ->where('opportunities.das_id', $dasId)
            ->leftJoin('organisations as o_org', 'o_org.id', '=', 'opportunities.organisation_id')
            ->leftJoin('people as o_person', 'o_person.id', '=', 'opportunities.person_id')
            ->selectRaw('opportunities.id, opportunities.title, opportunities.won_on as day, '.$label('o').' as account, round(opportunities.amount * 100)::bigint as cents')
            ->get();

        $rows = [];
        foreach ($opportunities as $row) {
            $rows[] = self::row('opportunity', $row);
        }
        foreach ($quotes as $row) {
            $rows[] = self::row('quote', $row);
        }
        usort($rows, fn (array $a, array $b) => [$a['date'], $a['kind'] === 'quote' ? 1 : 0, $a['number_or_title']] <=> [$b['date'], $b['kind'] === 'quote' ? 1 : 0, $b['number_or_title']]);

        return $rows;
    }

    /**
     * @param  'quote'|'opportunity'  $kind
     * @return array{kind: 'quote'|'opportunity', id: string, number_or_title: string, account_label: string, date: string, amount_cents: int}
     */
    private static function row(string $kind, object $row): array
    {
        return [
            'kind' => $kind,
            'id' => (string) $row->id,
            'number_or_title' => (string) $row->title,
            'account_label' => (string) $row->account,
            'date' => substr((string) $row->day, 0, 10),
            'amount_cents' => BigInteger::of((string) $row->cents)->toInt(),
        ];
    }

    /**
     * « Ses fiches » restricts to what is his; the other scopes read it all.
     *
     * @param  Builder<Quote>  $query
     * @return Builder<Quote>
     */
    private static function ownQuotes(Builder $query, User $user): Builder
    {
        return self::scope($user) === AccessScope::Own ? CrmVisibility::quotes($query, $user, Permissions::REVENUE_VIEW) : $query;
    }

    /**
     * @param  Builder<Opportunity>  $query
     * @return Builder<Opportunity>
     */
    private static function ownOpportunities(Builder $query, User $user): Builder
    {
        return self::scope($user) === AccessScope::Own ? CrmVisibility::opportunities($query, $user, Permissions::REVENUE_VIEW) : $query;
    }
}
