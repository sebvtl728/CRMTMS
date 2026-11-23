<?php

namespace App\Modules\Admissions\Booklet;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureController;
use App\Modules\Admissions\Permissions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** « Livret d'apprentissage (PDF) » of a candidature with a contract (view right on it). */
class BookletController extends Controller
{
    public function show(Request $request, Candidature $candidature, BookletPdf $booklet): Response
    {
        CandidatureController::authorizeOn(CandidatureController::user($request), Permissions::CANDIDATURES_VIEW, $candidature);

        return response($booklet->pdf($candidature), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.BookletPdf::fileName($candidature).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
