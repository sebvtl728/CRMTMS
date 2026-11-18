<?php

namespace App\Modules\Admissions\Alternance;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureController;
use App\Modules\Admissions\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The alternance contract of a candidature (update right on it). */
class AlternanceController extends Controller
{
    public function update(Request $request, Candidature $candidature, AlternanceWriter $writer): RedirectResponse
    {
        $user = CandidatureController::user($request);
        CandidatureController::authorizeOn($user, Permissions::CANDIDATURES_UPDATE, $candidature);
        $writer->save($candidature, $request->all(), $user);

        return back()->with('status', 'Contrat enregistré.');
    }

    public function destroy(Request $request, Candidature $candidature, AlternanceWriter $writer): RedirectResponse
    {
        CandidatureController::authorizeOn(CandidatureController::user($request), Permissions::CANDIDATURES_UPDATE, $candidature);
        $writer->delete($candidature);

        return back()->with('status', 'Contrat retiré.');
    }
}
