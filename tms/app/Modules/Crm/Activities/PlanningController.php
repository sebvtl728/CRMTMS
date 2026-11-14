<?php

namespace App\Modules\Crm\Activities;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Permissions;
use App\Modules\Crm\Support\FilterInput;
use App\Modules\Crm\Support\FormOptions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Planning »: the meetings and planned calls of a week (« ?week=2026-W47 »,
 * the current week when missing or invalid). Direction and admin may show
 * a colleague's (« ?user= »); for the others the choice is ignored. The
 * rows are always those the viewer may see.
 */
class PlanningController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $viewer = $request->user();
        assert($viewer instanceof User);

        $canTeam = in_array($viewer->role(), [Role::AdminOrganisme, Role::Direction], true);
        $owner = $viewer;
        $chosen = $canTeam ? FilterInput::id($request->query('user')) : null;
        if ($chosen !== null) {
            $owner = User::query()->find($chosen) ?? $viewer;
        }

        $now = CarbonImmutable::now();
        $current = WeekPlanning::current($now);
        $monday = WeekPlanning::monday($request->query('week')) ?? $current;

        return Inertia::render('crm/planning', [
            'week' => WeekPlanning::label($monday),
            'previous' => WeekPlanning::label($monday->subDays(7)),
            'next' => WeekPlanning::label($monday->addDays(7)),
            'current_week' => WeekPlanning::label($current),
            'today' => $now->setTimezone((string) config('app.timezone'))->toDateString(),
            'days' => WeekPlanning::days($viewer, $owner, $monday),
            'user' => $owner->id,
            'user_name' => $owner->name,
            'users' => $canTeam ? FormOptions::responsible($owner->id) : [],
            'can_team' => $canTeam,
            'can_report' => $viewer->can(Permissions::UPDATE),
        ]);
    }
}
