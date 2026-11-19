<?php

use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\OnlineTests\OnlineAttempt;
use App\Modules\Admissions\Positioning\PositioningSending;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/admissions.php';
require_once __DIR__.'/Support.php';

beforeEach(function () {
    adSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

/** A candidature with a link to the published online test; returns [candidature, token]. */
function otLink(): array
{
    $test = otPublished();
    $c = adCandidature(['person_id' => adPerson('Durand', ['email' => 'lea.durand@example.test'])->id, 'positioning_received_on' => null]);
    test()->actingAs(admin(twoFactor: true));
    test()->post("/admissions/candidatures/{$c->id}/positioning/link", ['online_test_id' => $test->id])->assertSessionHasNoErrors();
    $link = test()->get("/admissions/candidatures/{$c->id}")->original->getData()['page']['props']['positioning']['link'];
    Auth::logout();
    test()->flushSession();

    return [$c, substr($link['url'], -64)];
}

it('shows the online test to the candidate, without the right answers', function () {
    [, $token] = otLink();

    $this->get("/test-positionnement/{$token}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('public/positioning-test')
        ->where('mode', 'online')
        ->where('state', 'open')
        ->where('online.title', 'Test tertiaire')
        ->where('online.time_limit_minutes', 60)
        ->has('online.sections', 2)
        ->where('online.sections.1.questions.0.id', 'q-tf')
        ->missing('online.sections.1.questions.0.answer')
        ->missing('online.sections.1.questions.1.tolerance')
        ->where('online.attempt', null));
});

it('starts once, saves the answers as typed and keeps them on reload', function () {
    [, $token] = otLink();

    $this->postJson("/test-positionnement/{$token}/start")->assertOk()->assertJsonPath('remaining_seconds', 3600);
    $this->travel(5)->minutes();
    $this->postJson("/test-positionnement/{$token}/start")->assertOk()->assertJsonPath('remaining_seconds', 3300);
    expect(OnlineAttempt::query()->count())->toBe(1);

    $this->putJson("/test-positionnement/{$token}/answers", ['answers' => ['q-tf' => true, 'q-num' => '38,5']])->assertOk();
    $this->putJson("/test-positionnement/{$token}/answers", ['answers' => ['q-one' => 'a']])->assertOk();
    $this->putJson("/test-positionnement/{$token}/answers", ['answers' => ['inconnue' => 'x']])->assertUnprocessable();
    $this->putJson("/test-positionnement/{$token}/answers", ['answers' => ['q-one' => 'zz']])->assertUnprocessable();

    $this->get("/test-positionnement/{$token}")->assertInertia(fn (Assert $p) => $p
        ->where('online.attempt.answers', ['q-tf' => true, 'q-num' => '38,5', 'q-one' => 'a'])
        ->where('online.attempt.remaining_seconds', 3300));
});

it('records the exits and the blocked pastes', function () {
    [, $token] = otLink();
    $this->postJson("/test-positionnement/{$token}/start");
    $this->postJson("/test-positionnement/{$token}/events", ['type' => 'blur'])->assertOk();
    $this->postJson("/test-positionnement/{$token}/events", ['type' => 'paste_blocked'])->assertOk();
    $this->postJson("/test-positionnement/{$token}/events", ['type' => 'hack'])->assertUnprocessable();

    expect(collect(OnlineAttempt::query()->sole()->events)->pluck('type')->all())->toBe(['blur', 'paste_blocked']);
});

it('submits: auto score, the stage and the received date, then nothing changes any more', function () {
    [$c, $token] = otLink();
    $this->postJson("/test-positionnement/{$token}/start");
    $this->putJson("/test-positionnement/{$token}/answers", ['answers' => [
        'q-tf' => true, 'q-num' => '38,50', 'q-one' => 'b', 'q-many' => ['z', 'x'], 'q-open' => 'Taxe sur la valeur ajoutée',
    ]]);

    $this->postJson("/test-positionnement/{$token}/submit")->assertOk()->assertJsonPath('status', 'Merci, vos réponses ont bien été envoyées.');
    $attempt = OnlineAttempt::query()->sole();
    expect($attempt->auto_score)->toBe(8) // 2 + 3 + 0 + 3
        ->and($attempt->max_score)->toBe(20)
        ->and($c->fresh()->status)->toBe(CandidatureStatus::PositioningReceived)
        ->and($c->fresh()->positioning_received_on->toDateString())->toBe('2026-11-19')
        ->and($c->fresh()->positioning_result)->toBe('8 / 20 (réponses rédigées à corriger)')
        ->and(PositioningSending::query()->sole()->submitted_at)->not->toBeNull();

    $this->putJson("/test-positionnement/{$token}/answers", ['answers' => ['q-tf' => false]])->assertStatus(409);
    $this->get("/test-positionnement/{$token}")->assertInertia(fn (Assert $p) => $p->where('state', 'submitted'));
});

it('closes the test at its time limit: answers refused, attempt submitted', function () {
    [, $token] = otLink();
    $this->postJson("/test-positionnement/{$token}/start");
    $this->putJson("/test-positionnement/{$token}/answers", ['answers' => ['q-tf' => true]]);
    $this->travel(61)->minutes();

    $this->putJson("/test-positionnement/{$token}/answers", ['answers' => ['q-num' => '38.5']])->assertStatus(409)
        ->assertJsonPath('message', 'Le temps est écoulé : vos réponses ont été envoyées.');
    expect(OnlineAttempt::query()->sole()->submitted_at)->not->toBeNull()
        ->and(OnlineAttempt::query()->sole()->answers)->toBe(['q-tf' => true]);
});

it('refuses a malformed answer cleanly, never an error 500', function () {
    [, $token] = otLink();
    $this->postJson("/test-positionnement/{$token}/start");

    $this->putJson("/test-positionnement/{$token}/answers", ['answers' => ['q-many' => [['x'], 'z']]])->assertUnprocessable();
    $this->putJson("/test-positionnement/{$token}/answers", ['answers' => ['q-open' => ['texte']]])->assertUnprocessable();
    $this->putJson("/test-positionnement/{$token}/answers", ['answers' => 'texte'])->assertUnprocessable();
});
