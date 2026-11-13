<?php

namespace App\Http\Controllers;

use App\Modules\Core\Users\User;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Search\RecentViews;
use App\Modules\Crm\Visibility\CrmVisibility;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Accueil »: shortcuts, counters and the last fiches the user opened.
 * Counters follow what the user may see (Responsable DAS: his DAS only).
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
        ]);
    }
}
