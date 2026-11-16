<?php

namespace App\Modules\Sessions\Planning;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Placement periods of the « Planning » tab of a session and the placement
 * of each trainee (host entreprise, own dates): sessions.seances.manage
 * with its scope on the session; a period or an enrolment of another
 * session is a 404.
 */
class PlacementController extends Controller
{
    public function store(Request $request, TrainingSession $trainingSession): RedirectResponse
    {
        SeanceController::authorizeManage($request, $trainingSession);

        PlacementPeriods::save($trainingSession, $request->all());

        return self::back($trainingSession, 'Période de stage ajoutée.');
    }

    public function update(Request $request, TrainingSession $trainingSession, PlacementPeriod $placementPeriod): RedirectResponse
    {
        SeanceController::authorizeManage($request, $trainingSession);
        self::assertOf($trainingSession, $placementPeriod);

        PlacementPeriods::save($trainingSession, $request->all(), $placementPeriod);

        return self::back($trainingSession, 'Période de stage enregistrée.');
    }

    public function destroy(Request $request, TrainingSession $trainingSession, PlacementPeriod $placementPeriod): RedirectResponse
    {
        SeanceController::authorizeManage($request, $trainingSession);
        self::assertOf($trainingSession, $placementPeriod);

        PlacementPeriods::delete($placementPeriod);

        return self::back($trainingSession, 'Période de stage supprimée.');
    }

    public function host(Request $request, TrainingSession $trainingSession, PlacementPeriod $placementPeriod, Enrolment $enrolment): RedirectResponse
    {
        SeanceController::authorizeManage($request, $trainingSession);
        self::assertOf($trainingSession, $placementPeriod);
        abort_unless($enrolment->training_session_id === $trainingSession->id, 404);

        PlacementPeriods::setHost($placementPeriod, $enrolment, $request->all());

        return self::back($trainingSession, 'Stage du stagiaire enregistré.');
    }

    public function removeHost(Request $request, TrainingSession $trainingSession, PlacementPeriod $placementPeriod, Enrolment $enrolment): RedirectResponse
    {
        SeanceController::authorizeManage($request, $trainingSession);
        self::assertOf($trainingSession, $placementPeriod);
        abort_unless($enrolment->training_session_id === $trainingSession->id, 404);

        PlacementPeriods::removeHost($placementPeriod, $enrolment);

        return self::back($trainingSession, 'Stage du stagiaire retiré.');
    }

    /**
     * Entreprises of the organisme to host a trainee (identity only, out of
     * the trash), never cached.
     */
    public function organisations(Request $request, TrainingSession $trainingSession, Fiches $fiches): JsonResponse
    {
        SeanceController::authorizeManage($request, $trainingSession);
        $q = $request->query('q');

        return response()->json(['entreprises' => is_string($q) && mb_strlen(trim($q)) >= 2 ? $fiches->searchOrganisations(mb_substr($q, 0, 100)) : []])
            ->header('Cache-Control', 'no-store, private');
    }

    private static function assertOf(TrainingSession $session, PlacementPeriod $period): void
    {
        abort_unless($period->training_session_id === $session->id, 404);
    }

    private static function back(TrainingSession $session, string $status): RedirectResponse
    {
        return to_route('sessions.show', $session)->with('status', $status);
    }
}
