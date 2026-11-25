<?php

namespace App\Modules\Admissions\Alternance;

use App\Exceptions\UserFacingHttpException;
use App\Http\Controllers\Controller;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureController;
use App\Modules\Admissions\Permissions;
use App\Modules\Catalogue\Trainings;
use App\Modules\Core\Documents\Previews;
use App\Modules\Sessions\Public\Admission;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * « Convention d'alternance (aperçu PDF) »: the convention of the contract
 * before the dossier exists, rendered by the documents module (Core\
 * Documents\Previews); never stored nor numbered. The issued one comes with
 * the dossier.
 */
class AlternanceConventionController extends Controller
{
    public const PREVIEW = 'alternance_convention';

    public function show(Request $request, Candidature $candidature, Trainings $trainings): Response
    {
        CandidatureController::authorizeOn(CandidatureController::user($request), Permissions::CANDIDATURES_VIEW, $candidature);
        $contract = AlternanceContract::query()->where('candidature_id', $candidature->id)->first()
            ?? throw new UserFacingHttpException(409, 'Saisissez d\'abord le contrat d\'alternance.');
        $training = $candidature->training_id === null ? null : $trainings->find($candidature->training_id);
        $session = $candidature->target_session_id === null ? null : Admission::session($candidature->target_session_id);
        $centre = $training === null ? null : ($training->centre_hours ?? $training->duration_hours);

        $pdf = Previews::render(self::PREVIEW, [
            'alternance' => AlternanceFacts::of($candidature, $contract),
            'training_label' => $training->title ?? 'Formation à préciser',
            'sessions' => $session === null ? [] : [[
                'label' => $session['label'],
                'starts_on' => $session['starts_on'],
                'ends_on' => $session['ends_on'],
                'planned_minutes' => $centre === null || $centre === '' ? null : (int) round((float) $centre * 60),
                'place' => null,
            ]],
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="convention-alternance-'.Str::slug($candidature->number).'-apercu.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
