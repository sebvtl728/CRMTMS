<?php

namespace App\Http\Controllers;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Dashboard\CommercialSummary;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Search\RecentViews;
use App\Modules\Crm\Visibility\CrmVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Accueil »: shortcuts, counters, the commercial summary (« Mes relances »,
 * weighted pipeline of the month, next meetings) and the last fiches the
 * user opened. Everything follows what the user may see (Responsable DAS:
 * his DAS only); nothing CRM without crm.view.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        assert($user instanceof User);
        $crm = $user->can(Permissions::VIEW);

        return Inertia::render('dashboard', [
            'recent_views' => $crm ? RecentViews::for($user) : [],
            'counters' => $crm ? [
                'organisations' => CrmVisibility::organisations(Organisation::query(), $user)->count(),
                'people' => CrmVisibility::people(Person::query(), $user)->count(),
            ] : null,
            'commercial' => $crm ? CommercialSummary::for($user, CarbonImmutable::now()) : null,
        ]);
    }
}
