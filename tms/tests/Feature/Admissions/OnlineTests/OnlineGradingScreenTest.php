<?php

use App\Modules\Admissions\Gdpr\CandidatureAnonymisation;
use App\Modules\Admissions\OnlineTests\OnlineAttempt;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Access\AccessScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/admissions.php';
require_once __DIR__.'/Support.php';

beforeEach(function () {
    adSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

/** A submitted attempt (8 points auto, « Que signifie TVA ? » to grade on 10); returns [candidature, attempt]. */
function otSubmitted(): array
{
    $test = otPublished();
    $c = adCandidature();
    test()->actingAs(admin(twoFactor: true));
    test()->post("/admissions/candidatures/{$c->id}/positioning/link", ['online_test_id' => $test->id]);
    $token = substr(test()->get("/admissions/candidatures/{$c->id}")->original->getData()['page']['props']['positioning']['link']['url'], -64);
    Auth::logout();
    test()->flushSession();
    test()->postJson("/test-positionnement/{$token}/start");
    test()->postJson("/test-positionnement/{$token}/events", ['type' => 'blur']);
    test()->putJson("/test-positionnement/{$token}/answers", ['answers' => ['q-tf' => true, 'q-num' => '38,50', 'q-one' => 'b', 'q-many' => ['x', 'z'], 'q-open' => 'Taxe sur la valeur ajoutée']]);
    test()->postJson("/test-positionnement/{$token}/submit");

    return [$c, OnlineAttempt::query()->sole()];
}

it('shows the answers, the automatic score and the traces on the candidature', function () {
    [$c] = otSubmitted();

    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->where('positioning.attempt.auto_score', 8)
        ->where('positioning.attempt.max_score', 20)
        ->where('positioning.attempt.score', null)
        ->where('positioning.attempt.exits', 1)
        ->where('positioning.attempt.pending', ['q-open'])
        ->where('positioning.attempt.sections.1.questions.4.given', 'Taxe sur la valeur ajoutée')
        ->where('positioning.attempt.sections.1.questions.2.earned', 0)
        ->where('positioning.attempt.sections.1.questions.2.right', '30 jours ouvrables')
        ->where('positioning.attempt.can_grade', true));
});

it('grades the open questions by hand, then gives the final score', function () {
    [$c, $attempt] = otSubmitted();
    $this->actingAs(admin(twoFactor: true));

    $this->put("/admissions/candidatures/{$c->id}/online-attempts/{$attempt->id}/grades", ['scores' => ['q-open' => 11]])
        ->assertSessionHasErrors(['scores.q-open' => 'La note dépasse le barème de la question.']);
    $this->put("/admissions/candidatures/{$c->id}/online-attempts/{$attempt->id}/grades", ['scores' => ['q-tf' => 1]])
        ->assertSessionHasErrors(['scores.q-tf' => 'Cette question se corrige seule.']);
    $this->put("/admissions/candidatures/{$c->id}/online-attempts/{$attempt->id}/grades", ['scores' => ['q-open' => 9]])
        ->assertRedirect()->assertSessionHas('status', 'Correction enregistrée : 17 / 20.');

    expect($attempt->fresh()->score)->toBe(17)->and($c->fresh()->positioning_result)->toBe('17 / 20');

    $reader = adUser([Permissions::CANDIDATURES_VIEW => null]);
    $this->actingAs($reader)->put("/admissions/candidatures/{$c->id}/online-attempts/{$attempt->id}/grades", ['scores' => ['q-open' => 1]])->assertForbidden();
});

it('anonymisation clears the answers and the traces of the attempts', function () {
    [$c, $attempt] = otSubmitted();
    app(CandidatureAnonymisation::class)->anonymise($c->fresh());

    expect($attempt->fresh()->answers)->toBe([])->and($attempt->fresh()->events)->toBe([]);
});

/** The HTML sent to Gotenberg. */
function otPdfHtml(): string
{
    $html = '';
    Http::assertSent(function (Request $request) use (&$html) {
        foreach ($request->data() as $part) {
            if (($part['filename'] ?? null) === 'index.html') {
                $html = (string) $part['contents'];
            }
        }

        return true;
    });

    return $html;
}

it('downloads the copy of the candidate as a pdf: answers, right answers, points, traces, score', function () {
    [$c, $attempt] = otSubmitted();
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    Http::fake(['gotenberg:3000/*' => Http::response('%PDF-1.7 copie', 200, ['Content-Type' => 'application/pdf'])]);
    $this->actingAs(admin(twoFactor: true));

    $response = $this->get("/admissions/candidatures/{$c->id}/online-attempts/{$attempt->id}/pdf")->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('test-'.$c->number.'.pdf');

    expect(otPdfHtml())->toContain('Test tertiaire')->toContain('Léa Durand')->toContain($c->number)
        ->toContain('Que signifie TVA ?')->toContain('Taxe sur la valeur ajoutée')
        ->toContain('30 jours ouvrables')->toContain('8 / 20')->toContain('1 sortie de la page');

    // Not for who cannot see the candidature; not before the answers are sent.
    $this->actingAs(adUser([Permissions::CANDIDATURES_VIEW => AccessScope::Own]))
        ->get("/admissions/candidatures/{$c->id}/online-attempts/{$attempt->id}/pdf")->assertNotFound();
});

it('escapes what the candidate typed in the pdf', function () {
    [$c, $attempt] = otSubmitted();
    $attempt->forceFill(['answers' => [...$attempt->answers, 'q-open' => '<script>alert(1)</script>']])->save();
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    Http::fake(['gotenberg:3000/*' => Http::response('%PDF-1.7', 200)]);

    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}/online-attempts/{$attempt->id}/pdf")->assertOk();
    expect(otPdfHtml())->not->toContain('<script>alert')->toContain('&lt;script&gt;');
});
