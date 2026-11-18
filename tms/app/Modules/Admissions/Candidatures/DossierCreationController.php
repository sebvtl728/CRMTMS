<?php

namespace App\Modules\Admissions\Candidatures;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Public\Admission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * « Créer le dossier »: the update right on the candidature and the right to
 * create a dossier in its DAS (sessions.dossiers.update and its scope).
 */
class DossierCreationController extends Controller
{
    public function defaults(Request $request, Candidature $candidature, DossierCreation $creation): JsonResponse
    {
        self::authorize($request, $candidature);

        return response()->json([
            ...$creation->defaults($candidature),
            'sessions' => $candidature->training_id !== null ? Admission::sessionOptions($candidature->training_id) : [],
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, Candidature $candidature, DossierCreation $creation): RedirectResponse
    {
        $user = self::authorize($request, $candidature);
        $dossierId = $creation->create($candidature, $request->all(), $user);

        return redirect("/dossiers/{$dossierId}")->with('status', 'Dossier de formation créé : '.$candidature->number.' est apprenant.');
    }

    private static function authorize(Request $request, Candidature $candidature): User
    {
        $user = CandidatureController::user($request);
        CandidatureController::authorizeOn($user, Permissions::CANDIDATURES_UPDATE, $candidature);
        abort_unless(Admission::mayCreateDossier($user, $candidature->das_id), 403);

        return $user;
    }
}
