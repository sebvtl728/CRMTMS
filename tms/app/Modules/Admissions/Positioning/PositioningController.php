<?php

namespace App\Modules\Admissions\Positioning;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureController;
use App\Modules\Admissions\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** « Envoyer le test » of a candidature (right to change it). */
class PositioningController extends Controller
{
    public function send(Request $request, Candidature $candidature, PositioningSendings $sendings): RedirectResponse
    {
        $user = CandidatureController::user($request);
        CandidatureController::authorizeOn($user, Permissions::CANDIDATURES_UPDATE, $candidature);
        abort_if($candidature->trashed(), 404);
        $sendings->send($candidature, $request->only(['version_ids', 'online_test_id', 'to', 'subject', 'body']), $user);

        return back()->with('status', 'Test de positionnement en cours d\'envoi.');
    }

    public function link(Request $request, Candidature $candidature, PositioningSendings $sendings): RedirectResponse
    {
        $user = CandidatureController::user($request);
        CandidatureController::authorizeOn($user, Permissions::CANDIDATURES_UPDATE, $candidature);
        abort_if($candidature->trashed(), 404);
        $sendings->link($candidature, $request->only(['version_ids', 'online_test_id']), $user);

        return back()->with('status', 'Lien du test généré : copiez-le et transmettez-le au candidat.');
    }
}
