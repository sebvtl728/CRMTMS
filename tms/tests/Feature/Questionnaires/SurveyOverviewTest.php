<?php

use App\Modules\Core\Audit\AuditLog;
use App\Modules\Crm\Public\FicheBlocks;
use App\Modules\Qualite\Public\Measures;
use App\Modules\Questionnaires\Answering\SurveyAnswers;
use App\Modules\Questionnaires\Campaigns\SurveyLaunch;
use App\Modules\Questionnaires\Permissions;
use App\Modules\Questionnaires\Results\SurveyOverview;
use App\Modules\Questionnaires\Surveys\SurveyInvitation;
use App\Modules\Sessions\Permissions as SessionsPermissions;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/Support/surveys.php';

beforeEach(function () {
    svSetUp();
    Queue::fake();
});

function svAnswerMarks(SurveyInvitation $invitation, int $mark): void
{
    $keys = ['global', 'objectifs', 'animation', 'supports', 'organisation'];
    app(SurveyAnswers::class)->submit($invitation, [...array_fill_keys($keys, $mark), 'recommande' => 'Oui'], 'email');
}

/** Two ended sessions: 3 trainees (2 answers: 5 and 3), 2 trainees (1 answer: 4). */
function svTwoSessions(): array
{
    [$a, $pa] = svSession('2026-08-31');
    [$b, $pb] = svSession('2026-08-20', ['Blanc' => null, 'Noir' => null]);
    app(SurveyLaunch::class)->launch($a->id, 'hot', admin());
    app(SurveyLaunch::class)->launch($b->id, 'hot', admin());
    $inv = fn ($p) => SurveyInvitation::query()->where('person_id', $p->id)->sole();
    svAnswerMarks($inv($pa['Durand']), 5);
    svAnswerMarks($inv($pa['Petit']), 3);
    svAnswerMarks($inv($pb['Blanc']), 4);

    return [$a, $b, $pa];
}

it('lists the campaigns per session with their rates, and the totals', function () {
    [$a, $b] = svTwoSessions();

    $rows = SurveyOverview::rows(['from' => '2026-08-01', 'to' => '2026-08-31']);
    $totals = SurveyOverview::totals($rows);

    expect(collect($rows)->pluck('session_id')->all())->toBe([$a->id, $b->id])
        ->and($rows[0])->toMatchArray(['kind' => 'hot', 'audience' => 'trainee', 'invited' => 3, 'answered' => 2, 'response_rate' => 67, 'average' => 400, 'satisfaction_rate' => 50])
        ->and($totals)->toMatchArray(['invited' => 5, 'answered' => 3, 'response_rate' => 60, 'average' => 400, 'satisfaction_rate' => 67])
        ->and(SurveyOverview::rows(['from' => '2026-08-25', 'to' => '2026-08-31']))->toHaveCount(1)
        ->and(SurveyOverview::rows(['kind' => 'cold']))->toBe([]);
});

it('shows the page and exports the CSV with the right only', function () {
    svTwoSessions();
    $this->actingAs(userWithPermissions([Permissions::VIEW]));

    $this->get('/qualite/questionnaires?from=2026-08-01&to=2026-08-31')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('qualite/questionnaires')->has('rows', 2)->where('totals.answered', 3));
    $csv = $this->get('/qualite/questionnaires/export?from=2026-08-01&to=2026-08-31')->assertOk()->streamedContent();
    expect($csv)->toStartWith("\xEF\xBB\xBFSession;Fin;Type;Destinataires;Invités;Réponses;Taux de réponse;Satisfaction moyenne;Satisfaits")
        ->and($csv)->toContain(';67 %;4/5;50 %');
    expect(AuditLog::query()->where('event', 'questionnaires.results.exported')->exists())->toBeTrue();

    $this->actingAs(userWithPermissions(['crm.fiches.view']));
    $this->get('/qualite/questionnaires')->assertForbidden();
    $this->get('/qualite/questionnaires/export')->assertForbidden();
});

it('shows the satisfaction of the hot questionnaire on the trainings followed', function () {
    [, , $people] = svTwoSessions();
    $viewer = userWithPermissions([SessionsPermissions::SESSIONS_VIEW, 'crm.fiches.view']);

    $rows = collect(FicheBlocks::for('person', $people['Durand']->id, $viewer))->firstWhere('key', 'sessions.enrolments')['rows'];
    $roux = collect(FicheBlocks::for('person', $people['Roux']->id, $viewer))->firstWhere('key', 'sessions.enrolments')['rows'];

    expect($rows[0]['meta'])->toBe('Terminée le 31/08/2026 · Satisfaction : 5/5')
        ->and($roux[0]['meta'])->toBe('Terminée le 31/08/2026 · Satisfaction : -');
});

it('declares the Qualiopi measures of indicators 30 and 2', function () {
    svTwoSessions();

    $measures = collect(Measures::for(30))->keyBy('key');
    expect($measures['questionnaires.hot.response'])->toMatchArray(['value' => '60 %', 'tone' => 'ok'])
        ->and($measures['questionnaires.hot.response']['detail'])->toContain('3 réponses sur 5')
        ->and($measures['questionnaires.hot.satisfaction'])->toMatchArray(['value' => '67 %'])
        ->and($measures['questionnaires.hot.satisfaction']['detail'])->toContain('4/5')
        ->and($measures['questionnaires.cold.response'])->toMatchArray(['value' => '-', 'tone' => 'warn'])
        ->and($measures['questionnaires.client.satisfaction'])->toMatchArray(['value' => '-', 'detail' => 'Questionnaire entreprise non utilisé.'])
        ->and(collect(Measures::for(2))->pluck('key'))->toContain('questionnaires.hot.satisfaction');
});
