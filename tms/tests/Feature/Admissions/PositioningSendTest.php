<?php

use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Admissions\Permissions;
use App\Modules\Admissions\Positioning\PositioningMail;
use App\Modules\Admissions\Positioning\PositioningSending;
use App\Modules\Admissions\Positioning\SendPositioningTest;
use App\Modules\Catalogue\Brochures\BrochureStore;
use App\Modules\Core\References\ReferenceList;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/positioning.php';

beforeEach(function () {
    adSetUp();
    fakeImportDisk();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

it('offers on the candidature the tests of its training and das, with the prefilled e-mail', function () {
    [$c, $test] = posCandidature();
    posTest('Test incendie', [crmItem(ReferenceList::Das, 'Métiers supports')->id]);

    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->has('positioning.tests', 1)
        ->where('positioning.tests.0.version_id', $test->current_version_id)
        ->where('positioning.tests.0.title', 'Test cuisine')
        ->where('positioning.defaults.to', 'lea.durand@example.test')
        ->where('positioning.defaults.body', fn (string $b) => str_contains($b, '{lien}') && str_contains($b, 'Léa') && str_contains($b, '03/12/2026'))
        ->has('positioning.sendings', 0)
        ->where('positioning.can_send', true));
});

it('sends the test: a link valid 14 days, the date and the stage, the job queued', function () {
    Queue::fake();
    [$c, $test] = posCandidature();
    $this->actingAs(admin(twoFactor: true));

    posSend($c, $test)->assertRedirect()->assertSessionHas('status', 'Test de positionnement en cours d\'envoi.');

    $sending = PositioningSending::query()->sole();
    expect($sending->token_hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($sending->expires_at->setTimezone('Europe/Paris')->format('Y-m-d H:i'))->toBe('2026-12-03 23:59')
        ->and($sending->versionIds())->toBe([$test->current_version_id])
        ->and($sending->email_status)->toBe(PositioningSending::PENDING)
        ->and($c->fresh()->positioning_sent_on->toDateString())->toBe('2026-11-19')
        ->and($c->fresh()->status)->toBe(CandidatureStatus::PositioningSent);
    Queue::assertPushed(SendPositioningTest::class, fn (SendPositioningTest $job) => $job->sendingId === $sending->id
        && hash('sha256', $job->token) === $sending->token_hash);
});

it('mails the test with the link and the pdf, then marks it sent', function () {
    Mail::fake();
    [$c, $test] = posCandidature();
    $this->actingAs(admin(twoFactor: true));
    posSend($c, $test);

    $sending = PositioningSending::query()->sole();
    expect($sending->email_status)->toBe(PositioningSending::SENT);
    Mail::assertSent(PositioningMail::class, function (PositioningMail $mail) {
        $mail->build();

        return $mail->hasTo('lea.durand@example.test')
            && preg_match('#/test-positionnement/[0-9a-f]{64}#', $mail->text) === 1
            && ! str_contains($mail->text, '{lien}')
            && count($mail->rawAttachments) === 1;
    });
});

it('revokes the previous link on a new sending, never moves the stage back', function () {
    Queue::fake();
    [$c, $test] = posCandidature();
    $this->actingAs(admin(twoFactor: true));
    posSend($c, $test);
    $c->fresh()->forceFill(['positioning_received_on' => '2026-11-19'])->save(); // the test came back (compulsory before the interview)
    app(CandidatureTransitions::class)->move($c->fresh(), CandidatureStatus::Interview, admin());
    posSend($c, $test);

    expect(PositioningSending::query()->whereNotNull('revoked_at')->count())->toBe(1)
        ->and(PositioningSending::query()->whereNull('revoked_at')->count())->toBe(1)
        ->and($c->fresh()->status)->toBe(CandidatureStatus::Interview);
});

it('refuses a message without the link, a test not offered, a bad address, and a reader', function () {
    Queue::fake();
    [$c, $test] = posCandidature();
    $plaquette = app(BrochureStore::class)->create('Plaquette', UploadedFile::fake()->createWithContent('p.pdf', POSITIONING_PDF), [], [$c->das_id]);
    $this->actingAs(admin(twoFactor: true));

    posSend($c, $test, ['body' => 'Bonjour'])->assertSessionHasErrors(['body' => 'Le message doit contenir {lien} (le lien du candidat).']);
    posSend($c, $test, ['version_ids' => [$plaquette->current_version_id]])->assertSessionHasErrors(['version_ids' => 'Choisissez au moins un test proposé pour cette candidature.']);
    posSend($c, $test, ['to' => 'pas-une-adresse'])->assertSessionHasErrors('to');
    expect(PositioningSending::query()->count())->toBe(0);

    $reader = adUser([Permissions::CANDIDATURES_VIEW => null]);
    $this->actingAs($reader);
    posSend($c, $test)->assertForbidden();
});

it('generates a link to copy without any e-mail, shown on the card while it works', function () {
    Queue::fake();
    [$c, $test] = posCandidature();
    $this->actingAs(admin(twoFactor: true));

    $this->post("/admissions/candidatures/{$c->id}/positioning/link", ['version_ids' => [$test->current_version_id]])
        ->assertRedirect()->assertSessionHas('status', 'Lien du test généré : copiez-le et transmettez-le au candidat.');
    Queue::assertNothingPushed();

    $sending = PositioningSending::query()->sole();
    expect($sending->email)->toBeNull()
        ->and($sending->email_status)->toBe(PositioningSending::NO_EMAIL)
        ->and($c->fresh()->status)->toBe(CandidatureStatus::PositioningSent);

    $link = $this->get("/admissions/candidatures/{$c->id}")->original->getData()['page']['props']['positioning']['link'];
    expect($link['url'])->toMatch('#/test-positionnement/[0-9a-f]{64}$#')
        ->and($link['expires_on'])->toBe('03/12/2026');
    $url = $link['url'];
    $token = substr((string) $url, -64);
    expect(hash('sha256', $token))->toBe($sending->token_hash);

    // The link opens for the candidate.
    $this->get("/test-positionnement/{$token}")->assertOk();

    // A reader of the candidature does not get it.
    $this->actingAs(adUser([Permissions::CANDIDATURES_VIEW => null]))->get("/admissions/candidatures/{$c->id}")
        ->assertInertia(fn (Assert $p) => $p->where('positioning.link', null));
});

it('shows the link of an e-mail sending too, the newest one only', function () {
    Queue::fake();
    [$c, $test] = posCandidature();
    $this->actingAs(admin(twoFactor: true));
    posSend($c, $test);
    $this->post("/admissions/candidatures/{$c->id}/positioning/link", ['version_ids' => [$test->current_version_id]]);

    $active = PositioningSending::query()->whereNull('revoked_at')->sole();
    $this->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->where('positioning.link.url', fn (string $url) => hash('sha256', substr($url, -64)) === $active->token_hash));
});
