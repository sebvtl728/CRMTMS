<?php

namespace App\Modules\Sessions\Trainers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * « Mon planning »: the page of the trainer linked to the account (his
 * unavailabilities, entered by himself; his séances come with the planning
 * pages). Open to a linked trainer, or to a user holding the séances on
 * « Ses séances » (not linked yet: told so); 403 otherwise.
 */
class MyPlanningController extends Controller
{
    public function show(Request $request, Fiches $fiches): Response
    {
        $user = $this->user($request);
        $trainer = Trainers::forUser($user);
        abort_if($trainer === null && Scopes::of($user, Permissions::SEANCES_VIEW) !== AccessScope::Trainer, 403);

        return Inertia::render('sessions/my-planning', [
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
