<?php

namespace App\Modules\Sessions\Trainers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\PlanningController;
use App\Modules\Sessions\Planning\PlanningPeriod;
use App\Modules\Sessions\Planning\PlanningRows;
use App\Modules\Sessions\Planning\Seance;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Mon planning »: the page of the trainer linked to the account: his
 * séances of a month or a week (whatever the scope of his rights: those
 * whose trainer he is, sessions in the trash left out), each opening its
 * attendance when he may enter it, and his unavailabilities, entered by
 * himself. Open to a linked trainer, or to a user holding the séances on
 * « Ses séances » (not linked yet: told so); 403 otherwise.
 */
class MyPlanningController extends Controller
{
    public function show(Request $request, Fiches $fiches): Response
    {
        $user = $this->user($request);
        $scoped = Scopes::of($user, Permissions::SEANCES_VIEW) === AccessScope::Trainer;
        // A trainer-scoped user without trainer record gets it now (missed role change, unlinked).
        $trainer = Trainers::forUser($user) ?? ($scoped ? Trainers::ensureForUser($user) : null);
        abort_if($trainer === null && ! $scoped, 403);
        $period = PlanningPeriod::fromRequest($request);
        $seances = $trainer === null ? new Collection : PlanningController::inPeriod(Seance::query()->where('trainer_id', $trainer->id), $period)
            ->orderBy('date')->orderBy('starts_at')->orderBy('id')->limit(PlanningRows::LIMIT)->get();

        return Inertia::render('sessions/my-planning', [
            'period' => $period->toArray(),
            ...$period->shading(),
            'seances' => PlanningRows::rows($seances, $user, false),
            'calendar_feed' => $user->can('calendar.subscribe'),
            'trainer' => $trainer === null ? null : [
                'id' => $trainer->id,
                'label' => $fiches->personLabels([$trainer->person_id])[$trainer->person_id]['label'] ?? $user->name,
                'status_label' => $trainer->statusLabel(),
            ],
            'unavailabilities' => $trainer === null ? [] : UnavailabilityInput::rows(
                $trainer->unavailabilities()->where('ends_at', '>', now()->subDays(30))->orderBy('starts_at')->get(),
            ),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function storeUnavailability(Request $request): RedirectResponse
    {
        $trainer = Trainers::forUser($this->user($request)) ?? abort(404);
        $input = UnavailabilityInput::from($request);
        Trainers::addUnavailability($trainer, $input['from'], $input['to'], $input['reason']);

        return back()->with('status', 'Indisponibilité enregistrée.');
    }

    public function destroyUnavailability(Request $request, TrainerUnavailability $unavailability): RedirectResponse
    {
        $trainer = Trainers::forUser($this->user($request));
        abort_if($trainer === null || $unavailability->trainer_id !== $trainer->id, 404);
        Trainers::removeUnavailability($unavailability);

        return back()->with('status', 'Indisponibilité supprimée.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
