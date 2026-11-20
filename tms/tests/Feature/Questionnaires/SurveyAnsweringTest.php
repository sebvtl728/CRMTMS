<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Questionnaires\Answering\SurveyAnswers;
use App\Modules\Questionnaires\Campaigns\SurveyLaunch;
use App\Modules\Questionnaires\Surveys\SurveyInvitation;
use App\Modules\Questionnaires\Surveys\SurveyResponse;
use App\Modules\Sessions\Sessions\TrainingSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/Support/surveys.php';

beforeEach(function () {
    svSetUp();
    Queue::fake();
});

/** A launched hot campaign; each invitation gets a known plain token (keyed by last name). */
function svLaunched(): array
{
    [$session, $people] = svSession();
    $campaign = app(SurveyLaunch::class)->launch($session->id, 'hot', admin());
    $tokens = [];
    foreach ($people as $name => $person) {
        [$plain, $hash] = SurveyInvitation::newToken();
        SurveyInvitation::query()->where('person_id', $person->id)->update(['token_hash' => $hash]);
        $tokens[$name] = $plain;
    }

    return [$campaign, $tokens, $people];
}

/** Valid answers to the default hot template. */
function svAnswers(array $overrides = []): array
{
    return ['answers' => ['global' => 5, 'objectifs' => 4, 'animation' => 4, 'supports' => 5, 'organisation' => 4, 'recommande' => 'Oui', 'commentaires' => 'Très bien', ...$overrides]];
}

it('shows the questionnaire of a personal link', function () {
    [, $tokens] = svLaunched();

    $this->get("/questionnaire/{$tokens['Durand']}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/survey')->where('state', 'open')->where('first_name', 'Léa')
        ->where('title', 'Questionnaire de satisfaction de fin de formation')->has('questions', 7)
        ->where('deadline', '01/10/2026'));
});

it('records the answers once with their score', function () {
    [, $tokens, $people] = svLaunched();

    $this->post("/questionnaire/{$tokens['Durand']}", svAnswers())->assertRedirect();

    $invitation = SurveyInvitation::query()->where('person_id', $people['Durand']->id)->sole();
    expect($invitation->answered_at)->not->toBeNull()->and($invitation->answered_via)->toBe('email')
        ->and($invitation->response->score)->toBe(440)
        ->and($invitation->response->answers['commentaires'])->toBe('Très bien');
    $this->get("/questionnaire/{$tokens['Durand']}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'answered'));
    $this->post("/questionnaire/{$tokens['Durand']}", svAnswers())->assertStatus(409);
    expect(SurveyResponse::query()->count())->toBe(1);
});

it('refuses incomplete answers', function () {
    [, $tokens] = svLaunched();

    $this->post("/questionnaire/{$tokens['Durand']}", svAnswers(['global' => null, 'objectifs' => 9]))
        ->assertSessionHasErrors(['answers.global', 'answers.objectifs']);
    expect(SurveyResponse::query()->count())->toBe(0);
});

it('gives the same 404 for a malformed, unknown, closed, trashed-session or other organisme link', function () {
    [$campaign, $tokens] = svLaunched();
    $invalid = fn (string $url) => $this->get($url)->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page->component('public/survey')->where('state', 'invalid'));

    $invalid('/questionnaire/'.str_repeat('a', 64));
    $this->get('/questionnaire/abc')->assertNotFound();
    $this->travelTo(now()->addDays(31));
    $invalid("/questionnaire/{$tokens['Durand']}");
    $this->post("/questionnaire/{$tokens['Durand']}", svAnswers())->assertNotFound();
    $this->travelBack();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00', 'Europe/Paris'));
    TrainingSession::query()->findOrFail($campaign->training_session_id)->delete();
    $invalid("/questionnaire/{$tokens['Petit']}");
});

it('lets a trainee pick his name on the session QR page, then answer through a signed link', function () {
    [$campaign, $tokens, $people] = svLaunched();
    $qr = Crypt::decryptString($campaign->qr_token_encrypted);
    $this->post("/questionnaire/{$tokens['Petit']}", svAnswers());

    $this->get("/questionnaire/s/{$qr}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('public/survey-who')->where('trainees', fn ($list) => collect($list)->pluck('name')->all() === ['Alex R.', 'Léa D.']));

    $lea = SurveyInvitation::query()->where('person_id', $people['Durand']->id)->sole();
    $response = $this->post("/questionnaire/s/{$qr}", ['invitation_id' => $lea->id]);
    $signed = $response->headers->get('Location');
    expect($signed)->toContain('/questionnaire/q/'.$lea->id)->toContain('signature=');

    $this->get($signed)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('public/survey')->where('state', 'open'));
    $this->post($signed, svAnswers())->assertRedirect();
    expect($lea->fresh()->answered_via)->toBe('qr');

    // Her e-mail link then says she answered; no second response.
    $this->get("/questionnaire/{$tokens['Durand']}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'answered'));
    $this->post("/questionnaire/{$tokens['Durand']}", svAnswers())->assertStatus(409);
    expect(SurveyResponse::query()->count())->toBe(2);
});

it('refuses on the QR page an invitation of another campaign, an answered one, and an unsigned link', function () {
    [$campaign, , $people] = svLaunched();
    $qr = Crypt::decryptString($campaign->qr_token_encrypted);
    [$other] = svSession('2026-08-30', ['Blanc' => null]);
    $foreign = app(SurveyLaunch::class)->launch($other->id, 'hot', admin())->invitations()->sole();

    $this->post("/questionnaire/s/{$qr}", ['invitation_id' => $foreign->id])->assertNotFound();
    $this->post("/questionnaire/s/{$qr}", ['invitation_id' => 'nope'])->assertNotFound();
    $lea = SurveyInvitation::query()->where('person_id', $people['Durand']->id)->sole();
    $this->get("/questionnaire/q/{$lea->id}")->assertForbidden();
    $this->get('/questionnaire/s/'.str_repeat('b', 64))->assertNotFound();
});

it('refuses a second response sent at the same time', function () {
    [, $tokens, $people] = svLaunched();
    $invitation = SurveyInvitation::query()->where('person_id', $people['Durand']->id)->sole();
    $answers = app(SurveyAnswers::class);

    $answers->submit($invitation, svAnswers()['answers'], 'email');
    // A stale copy loaded before the first answer: the lock re-reads the row.
    expect(fn () => $answers->submit($invitation, svAnswers()['answers'], 'qr'))
        ->toThrow(UserFacingHttpException::class, 'Vous avez déjà répondu à ce questionnaire.');
    expect(SurveyResponse::query()->count())->toBe(1);
});
