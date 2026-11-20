<?php

namespace App\Modules\Questionnaires\Answering;

use App\Http\Controllers\Controller;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Crm\Public\Fiches;
use App\Modules\Questionnaires\Surveys\SurveyInvitation;
use App\Modules\Sessions\Public\Surveys;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The public pages of a questionnaire (no account; the host gives the
 * organisme): the personal link of an invitation, the session QR page where
 * a trainee picks his name, then a short signed link to his questionnaire.
 * Every invalid link (malformed, unknown, closed, session trashed or
 * cancelled, another organisme): the same « lien invalide » page (404).
 */
class SurveyAnswerController extends Controller
{
    public const SIGNED_HOURS = 2;

    public function __construct(private readonly SurveyAnswers $answers) {}

    public function show(Request $request, string $token): SymfonyResponse
    {
        return $this->page($request, SurveyAnswers::invitation($token));
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $invitation = SurveyAnswers::invitation($token);
        abort_if($invitation === null, 404);
        $this->answers->submit($invitation, $request->input('answers'), SurveyInvitation::VIA_EMAIL);

        return back();
    }

    public function who(Request $request, string $token): SymfonyResponse
    {
        $campaign = SurveyAnswers::campaign($token);
        if ($campaign === null) {
            return $this->invalid($request);
        }

        return Inertia::render('public/survey-who', [
            'organisme' => $this->organisme(),
            'title' => $campaign->title,
            'session' => (string) (Surveys::session($campaign->training_session_id)['label'] ?? ''),
            'people' => SurveyAnswers::waiting($campaign),
        ])->toResponse($request);
    }

    public function choose(Request $request, string $token): RedirectResponse
    {
        $campaign = SurveyAnswers::campaign($token);
        $id = $request->input('invitation_id');
        $invitation = $campaign !== null && is_string($id) && Str::isUuid($id)
            ? $campaign->invitations()->whereKey($id)->whereNull('answered_at')->first() : null;
        abort_if($invitation === null, 404);

        return redirect()->to(URL::temporarySignedRoute('survey.signed.show', now()->addHours(self::SIGNED_HOURS), ['invitation' => $invitation->id]));
    }

    public function signedShow(Request $request, string $invitation): SymfonyResponse
    {
        return $this->page($request, $this->signed($invitation));
    }

    public function signedSubmit(Request $request, string $invitation): RedirectResponse
    {
        $found = $this->signed($invitation);
        abort_if($found === null, 404);
        $this->answers->submit($found, $request->input('answers'), SurveyInvitation::VIA_QR);

        return back();
    }

    private function signed(string $id): ?SurveyInvitation
    {
        $invitation = Str::isUuid($id) ? SurveyInvitation::query()->find($id) : null;

        return $invitation !== null && SurveyAnswers::live($invitation->campaign) ? $invitation : null;
    }

    private function page(Request $request, ?SurveyInvitation $invitation): SymfonyResponse
    {
        if ($invitation === null) {
            return $this->invalid($request);
        }
        $campaign = $invitation->campaign;
        $person = app(Fiches::class)->personLabels([$invitation->person_id])[$invitation->person_id] ?? null;

        return Inertia::render('public/survey', [
            'state' => $invitation->answered_at !== null ? 'answered' : 'open',
            'organisme' => $this->organisme(),
            'title' => $campaign->title,
            'session' => (string) (Surveys::session($campaign->training_session_id)['label'] ?? ''),
            'first_name' => (string) ($person['first_name'] ?? ''),
            'deadline' => $campaign->closes_on->format('d/m/Y'),
            'questions' => $invitation->answered_at !== null ? [] : $campaign->questions,
        ])->toResponse($request);
    }

    private function invalid(Request $request): SymfonyResponse
    {
        return Inertia::render('public/survey', ['state' => 'invalid'])->toResponse($request)->setStatusCode(404);
    }

    private function organisme(): string
    {
        return (string) (app(TenantContext::class)->current()->name ?? '');
    }
}
