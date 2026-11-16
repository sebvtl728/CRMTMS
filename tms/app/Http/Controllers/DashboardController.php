<?php

namespace App\Http\Controllers;

use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Dashboard\CommercialSummary;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Search\RecentViews;
use App\Modules\Crm\Visibility\CrmVisibility;
use App\Modules\Sessions\Sessions\StartingSoon;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Accueil »: shortcuts, counters, the commercial summary (« Mes relances »,
 * weighted pipeline and quotes of the month, next meetings) and the last fiches the
 * user opened, the sessions starting within 7 days. Everything follows what the user may see (Responsable DAS:
 * his DAS only); nothing CRM without one « Consulter » right of a domain.
 * A trainer holding nothing but his séances goes to « Mon planning ».
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        if (Scopes::trainerOnly($user)) {
            return to_route('my-planning.show');
        }
        $crm = Permissions::anyView($user);

        return Inertia::render('dashboard', [
            'recent_views' => $crm ? RecentViews::for($user) : [],
            'counters' => $crm ? [
                'organisations' => CrmVisibility::organisations(Organisation::query(), $user)->count(),
                'people' => CrmVisibility::people(Person::query(), $user)->count(),
            ] : null,
            'commercial' => $crm ? CommercialSummary::for($user, CarbonImmutable::now()) : null,
            // « Sessions qui démarrent dans les 7 jours » (sessions.sessions.view and its scope).
            'sessions_starting' => StartingSoon::for($user, CarbonImmutable::now('Europe/Paris')),
        ]);
    }
}
