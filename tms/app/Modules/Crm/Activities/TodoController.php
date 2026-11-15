<?php

namespace App\Modules\Crm\Activities;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « À faire »: the viewer's actions in three sections (en retard,
 * aujourd'hui, cette semaine). With crm.activities.team the viewer may
 * switch to the whole team (every action he may see); otherwise the filter
 * is ignored.
 */
class TodoController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        $canTeam = $user->can(Permissions::ACTIVITIES_TEAM);
        $team = $canTeam && $request->boolean('team');
        $todo = TodoQuery::for($user, CarbonImmutable::now(), $team);

        return Inertia::render('crm/todo', [
            'sections' => array_map(fn ($rows) => $rows->map(fn (Activity $a) => ActivityRows::row($a))->values()->all(), $todo),
            'team' => $team,
            'can_team' => $canTeam,
            'can_edit' => $user->can(Permissions::ACTIVITIES_UPDATE),
            'status' => $request->session()->get('status'),
        ]);
    }
}
