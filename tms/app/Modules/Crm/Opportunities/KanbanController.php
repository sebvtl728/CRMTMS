<?php

namespace App\Modules\Crm\Opportunities;

use App\Http\Controllers\Controller;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Support\FormOptions;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Pipeline »: one column per active stage, in pipeline order, with its
 * count, amount and weighted amount. Same filters, visibility and sort as
 * the list (OpportunityListQuery). Won and lost columns only hold the
 * opportunities closed in the last CLOSED_DAYS days; each column shows at
 * most CARDS cards (its totals count them all). Moves go through the
 * ordinary stage endpoint.
 */
class KanbanController extends Controller
{
    public const CLOSED_DAYS = 90;

    public const CARDS = 100;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        $filters = OpportunityListQuery::filters($request->query());
        $since = CarbonImmutable::now(config('app.timezone'))->subDays(self::CLOSED_DAYS)->toDateString();

        /** @return Builder<Opportunity> */
        $query = fn (): Builder => OpportunityListQuery::build($filters, $user)
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $open) => $open->whereNull('opportunities.won_on')->whereNull('opportunities.lost_on'))
                ->orWhere('opportunities.won_on', '>=', $since)
                ->orWhere('opportunities.lost_on', '>=', $since));

        $totals = $query()->reorder()->toBase()
            ->selectRaw('opportunities.stage_id, count(*) as count, coalesce(sum(opportunities.amount), 0) as amount, coalesce(sum(opportunities.weighted_amount), 0) as weighted')
            ->groupBy('opportunities.stage_id')
            ->get()->keyBy('stage_id');

        $stages = OpportunityStage::options();
        $columns = array_map(function (array $stage) use ($query, $totals): array {
            $total = $totals->get($stage['value']);

            return [
                'stage' => $stage,
                'count' => (int) ($total->count ?? 0),
                'amount' => number_format((float) ($total->amount ?? 0), 2, '.', ''),
                'weighted_amount' => number_format((float) ($total->weighted ?? 0), 2, '.', ''),
                'cards' => $query()->where('opportunities.stage_id', $stage['value'])
                    ->with(OpportunityRows::WITH)->limit(self::CARDS)->get()
                    ->map(fn (Opportunity $o) => OpportunityRows::row($o))->values()->all(),
            ];
        }, $stages);

        return Inertia::render('crm/opportunities/kanban', [
            'columns' => $columns,
            'filters' => $filters,
            'options' => OpportunityListQuery::options(),
            'stages' => $stages,
            'lossReasons' => FormOptions::reference(ReferenceList::LossReason),
            'closedDays' => self::CLOSED_DAYS,
            'cardsLimit' => self::CARDS,
            'can' => ['update' => $user->can(Permissions::UPDATE), 'export' => $user->can(Permissions::EXPORT)],
            'status' => $request->session()->get('status'),
        ]);
    }
}
