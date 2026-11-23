<?php

namespace App\Modules\Admissions\Cerfa;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureController;
use App\Modules\Admissions\Permissions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** « Fiche Cerfa (PDF) » of a candidature with a contract (view right on it). */
class CerfaSheetController extends Controller
{
    public function show(Request $request, Candidature $candidature, CerfaSheet $sheet): Response
    {
        $user = CandidatureController::user($request);
        CandidatureController::authorizeOn($user, Permissions::CANDIDATURES_VIEW, $candidature);

        return response($sheet->pdf($candidature, $user), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.CerfaSheet::fileName($candidature).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
