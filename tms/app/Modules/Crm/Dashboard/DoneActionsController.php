<?php

namespace App\Modules\Crm\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Activities\ActivityRows;
use App\Modules\Crm\Dashboard\Indicators\ActivityIndicators;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Detail of the « Actions faites » and « Rendez-vous faits » cards: the
 * actions done in the period, with the dashboard filters and the same
 * query as the card (ActivityIndicators::done), latest first.
 */
class DoneActionsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        assert($user instanceof User);
        $meetings = $request->query('kind') === 'meetings';
        $filters = DashboardFilters::from($request->query(), CarbonImmutable::now(), (string) $request->query('tab', Indicator::MONTH));

        $page = ActivityIndicators::done($filters, $user, $meetings)->with(ActivityRows::WITH)
            ->orderByDesc('activities.done_at')->orderByDesc('activities.id')->paginate(50)->withQueryString();
        /** @var Collection<int, Activity> $items */
        $items = $page->getCollection();
        $page->setCollection(collect(ActivityRows::rows($items, $user)));

        return Inertia::render('crm/dashboards/actions', [
            'title' => $meetings ? 'Rendez-vous faits' : 'Actions faites',
            'period' => $filters->label(),
            'back' => '/crm/dashboards?'.http_build_query(array_filter(['tab' => $filters->tab, 'preset' => $filters->preset,
                'from' => $filters->preset === 'custom' ? $filters->from->toDateString() : null, 'to' => $filters->preset === 'custom' ? $filters->to->toDateString() : null,
                'das' => $filters->das, 'target' => $filters->target, 'responsible' => $filters->responsible], fn ($v) => $v !== null)),
            'actions' => $page,
        ]);
    }
}
