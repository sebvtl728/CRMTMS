<?php

namespace App\Modules\Questionnaires\Campaigns;

use App\Http\Controllers\Controller;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Public\Surveys;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * « Lancer le questionnaire » on the session page: needs the right to
 * update the session; launching again invites the trainees added since.
 */
class SurveyCampaignController extends Controller
{
    public function launch(Request $request, SurveyLaunch $launch, string $sessionId, string $kind): RedirectResponse
    {
        abort_unless(in_array($kind, ['hot', 'cold'], true), 404);
        /** @var User $user */
        $user = $request->user();
        abort_unless(Surveys::session($sessionId) !== null && Surveys::allows($user, $sessionId, false), 404);
        abort_unless(Surveys::allows($user, $sessionId, true), 403);

        $launch->launch($sessionId, $kind, $user);
        $label = $kind === 'hot' ? 'à chaud' : 'à froid';
        $status = $launch->invited === 0
            ? "Questionnaire {$label} : tous les destinataires sont déjà invités."
            : "Questionnaire {$label} lancé : {$launch->mailed} e-mail".($launch->mailed > 1 ? 's' : '').' en cours d\'envoi.';

        return back()->with('status', $status);
    }
}
