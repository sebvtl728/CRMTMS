<?php

namespace App\Modules\Sessions\Modules;

use App\Http\Controllers\Controller;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * « Modules » of the « Planning » tab of a session: add, change, delete,
 * switch the dating mode. sessions.sessions.update and its scope on the
 * session (SessionPolicy); a module of another session is a 404.
 */
class SessionModuleController extends Controller
{
    public function __construct(private readonly SessionModules $modules) {}

    public function store(Request $request, TrainingSession $trainingSession): RedirectResponse
    {
        Gate::authorize('update', $trainingSession);

        $this->modules->save($trainingSession, $request->all());

        return $this->back($trainingSession, 'Module ajouté.');
    }

    public function update(Request $request, TrainingSession $trainingSession, SessionModule $sessionModule): RedirectResponse
    {
        Gate::authorize('update', $trainingSession);
        self::assertOf($trainingSession, $sessionModule);

        $this->modules->save($trainingSession, $request->all(), $sessionModule);

        return $this->back($trainingSession, 'Module enregistré.');
    }

    public function destroy(TrainingSession $trainingSession, SessionModule $sessionModule): RedirectResponse
    {
        Gate::authorize('update', $trainingSession);
        self::assertOf($trainingSession, $sessionModule);

        $this->modules->delete($sessionModule);

        return $this->back($trainingSession->refresh(), 'Module supprimé.');
    }

    public function mode(Request $request, TrainingSession $trainingSession, SessionModule $sessionModule): RedirectResponse
    {
        Gate::authorize('update', $trainingSession);
        self::assertOf($trainingSession, $sessionModule);
        $mode = $request->input('dating_mode');

        $this->modules->switchMode($sessionModule, is_string($mode) ? $mode : '');

        return $this->back($trainingSession, 'Mode de datation modifié.');
    }

    /**
     * « Reprendre les modules de la formation »: what the session would get
     * (the training's modules) against what it has; locked once a module
     * has séances or hours entered.
     */
    public function catalogue(TrainingSession $trainingSession): JsonResponse
    {
        Gate::authorize('update', $trainingSession);

        return response()->json($this->modules->catalogueComparison($trainingSession));
    }

    /**
     * Replaces the modules of the session by those of its training (409
     * once a module has séances or hours entered).
     */
    public function fromCatalogue(TrainingSession $trainingSession): RedirectResponse
    {
        Gate::authorize('update', $trainingSession);

        $this->modules->resetFromTraining($trainingSession);

        return $this->back($trainingSession->refresh(), 'Modules de la formation repris.');
    }

    private static function assertOf(TrainingSession $session, SessionModule $module): void
    {
        abort_unless($module->training_session_id === $session->id, 404);
    }

    /**
     * Back to the session, with the warning of trainees planned above its
     * new hours (their enrolments unchanged).
     */
    private function back(TrainingSession $session, string $status): RedirectResponse
    {
        $warning = SessionModules::enrolmentWarning($session);

        return to_route('sessions.show', $session)->with('status', $status)->with('warning', $warning);
    }
}
