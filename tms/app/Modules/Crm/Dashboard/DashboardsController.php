<?php

namespace App\Modules\Crm\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** « Tableaux de bord » (lot 1e): the week and month tabs, their filters and cards. */
class DashboardsController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $filters = DashboardFilters::from($request->query(), CarbonImmutable::now(), (string) $request->query('tab', Indicator::WEEK));

        return Inertia::render('crm/dashboards', [
            'filters' => $filters->toArray(),
            'period' => ['current' => $filters->label(), 'previous' => $filters->previous()->label()],
            'cards' => Indicators::for($filters->tab, $filters, $user),
            'options' => [
                'das' => ReferenceItem::query()->where('list', ReferenceList::Das)->orderBy('position')->get(['id', 'label'])
                    ->map(fn (ReferenceItem $d) => ['value' => $d->id, 'label' => $d->label])->all(),
                'responsibles' => User::query()->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name'])
                    ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->all(),
            ],
            'can' => ['export' => $user->can('crm.export')],
        ]);
    }
}
