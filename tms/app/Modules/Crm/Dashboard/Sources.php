<?php

namespace App\Modules\Crm\Dashboard;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Opportunities\OpportunityListQuery;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteListQuery;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Database\Eloquent\Builder;

/**
 * The rows behind the dashboard cards (lot 1e), from the queries of the
 * lists (same scope, same trash rule): a card counts what its « Voir le
 * détail » lists. Filters: DAS, target, responsible; the period is the
 * card's own (each card has its reference date).
 */
final class Sources
{
    /** @return Builder<Opportunity> */
    public static function opportunities(DashboardFilters $f, User $user): Builder
    {
        return OpportunityListQuery::build([...OpportunityListQuery::filters([]),
            'das' => $f->das, 'target' => self::opportunityTarget($f), 'responsible' => $f->responsible], $user)->reorder();
    }

    /** @return Builder<Quote> */
    public static function quotes(DashboardFilters $f, User $user): Builder
    {
        $query = QuoteListQuery::build([...QuoteListQuery::filters([]), 'das' => $f->das, 'responsible' => $f->responsible], $user)->reorder();

        return $f->target === null ? $query : $query->whereIn('quotes.opportunity_id',
            Opportunity::query()->select('opportunities.id')->whereNotNull($f->target === 'organisation' ? 'opportunities.organisation_id' : 'opportunities.person_id'));
    }

    /**
     * Actions of the user's scope (as responsible: the collaborators are left
     * out, like ActivityStats) on live fiches; the DAS filter does not apply.
     *
     * @return Builder<Activity>
     */
    public static function activities(DashboardFilters $f, User $user): Builder
    {
        return CrmVisibility::activities(Activity::query(), $user, Permissions::ACTIVITIES_VIEW, collaborators: false)
            ->where(fn (Builder $q) => $q
                ->whereIn('activities.organisation_id', Organisation::query()->select('organisations.id'))
                ->orWhereIn('activities.person_id', Person::query()->select('people.id')))
            ->when($f->target === 'organisation', fn (Builder $q) => $q->whereNotNull('activities.organisation_id'))
            ->when($f->target === 'person', fn (Builder $q) => $q->whereNull('activities.organisation_id')->whereNotNull('activities.person_id'))
            ->when($f->responsible, fn (Builder $q, string $v) => $q->where('activities.responsible_user_id', $v));
    }

    /** The opportunity list's own word for a particulier. */
    public static function opportunityTarget(DashboardFilters $f): ?string
    {
        return match ($f->target) {
            'organisation' => 'organisation',
            'person' => 'individual',
            default => null,
        };
    }

    /**
     * Query string of the opportunity list for these filters (and extra ones).
     *
     * @param  array<string, string|null>  $extra
     */
    public static function opportunityListUrl(DashboardFilters $f, array $extra = []): string
    {
        return '/crm/opportunities?'.http_build_query(array_filter([
            'das' => $f->das, 'target' => self::opportunityTarget($f), 'responsible' => $f->responsible, ...$extra,
        ], fn ($v) => $v !== null && $v !== ''));
    }
}
