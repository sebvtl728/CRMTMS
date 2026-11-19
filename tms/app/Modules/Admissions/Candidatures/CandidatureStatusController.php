<?php

namespace App\Modules\Admissions\Candidatures;

use App\Http\Controllers\Controller;
use App\Modules\Admissions\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A change of stage of a candidature (CandidatureTransitions). */
class CandidatureStatusController extends Controller
{
    public function __invoke(Request $request, Candidature $candidature, CandidatureTransitions $transitions): RedirectResponse
    {
        $user = CandidatureController::user($request);
        CandidatureController::authorizeOn($user, Permissions::CANDIDATURES_UPDATE, $candidature);
        $data = $request->validate(
            ['status' => ['required', Rule::enum(CandidatureStatus::class)], 'decision_reason_id' => ['nullable', 'string']],
            ['status.required' => 'Choisissez une étape.', 'status.enum' => 'Étape inconnue.'],
        );
        $transitions->move($candidature, CandidatureStatus::from($data['status']), $user, $data['decision_reason_id'] ?? null);

        return back()->with('status', 'Étape : '.$candidature->status->label().'.');
    }
}
