<?php

use App\Modules\Admissions\Candidatures\CandidatureFile;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Admissions\Gdpr\CandidatureAnonymisation;
use App\Modules\Admissions\Positioning\PositioningSending;
use App\Modules\Admissions\Positioning\SendPositioningTest;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/positioning.php';

beforeEach(function () {
    adSetUp();
    fakeImportDisk();
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

/** Sends the test as an admin and returns [candidature, test, plain token], signed out. */
function posLink(): array
{
    [$c, $test] = posCandidature();
    test()->actingAs(admin(twoFactor: true));
    posSend($c, $test);
    $token = null;
    Queue::assertPushed(SendPositioningTest::class, function (SendPositioningTest $job) use (&$token) {
        $token = $job->token;

        return true;
    });
    Auth::logout();
    test()->flushSession();

    return [$c, $test, (string) $token];
}

function posCopy(string $name = 'copie.pdf', string $content = POSITIONING_PDF): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

it('shows the candidate the tests to download, without an account and with no personal data but the first name', function () {
    [$c, $test, $token] = posLink();

    $this->get("/test-positionnement/{$token}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('public/positioning-test')
        ->where('state', 'open')
        ->where('first_name', 'Léa')
        ->where('training', 'CAP Cuisine')
        ->where('deadline', '03/12/2026')
        ->has('tests', 1)
        ->where('tests.0.title', 'Test cuisine')
        ->missing('email')->missing('last_name'));

    $this->get("/test-positionnement/{$token}/tests/{$test->current_version_id}")->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->get("/test-positionnement/{$token}/tests/".Str::uuid7())->assertNotFound();
});

it('receives the copy once: files on the candidature, date, stage, journal', function () {
    [$c, , $token] = posLink();

    $this->post("/test-positionnement/{$token}", ['files' => [posCopy(), posCopy('page2.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='))], 'done' => '1'])
        ->assertRedirect("/test-positionnement/{$token}")->assertSessionHas('status', 'Merci, votre copie a bien été reçue.');

    $files = CandidatureFile::query()->where('candidature_id', $c->id)->get();
    expect($files)->toHaveCount(2)
        ->and($files->pluck('kind')->unique()->all())->toBe(['positioning'])
        ->and($files->pluck('uploaded_by')->unique()->all())->toBe([null])
        ->and($c->fresh()->positioning_received_on->toDateString())->toBe('2026-11-19')
        ->and($c->fresh()->status)->toBe(CandidatureStatus::PositioningReceived)
        ->and(PositioningSending::query()->sole()->submitted_at)->not->toBeNull()
        ->and(AuditLog::query()->where('event', 'candidature.positioning_submitted')->count())->toBe(1);

    // Used: the page says so, a second copy is refused.
    $this->get("/test-positionnement/{$token}")->assertOk()->assertInertia(fn (Assert $p) => $p->where('state', 'submitted')->where('submitted_on', '19/11/2026'));
    $this->post("/test-positionnement/{$token}", ['files' => [posCopy()], 'done' => '1'])->assertNotFound();
    expect(CandidatureFile::query()->count())->toBe(2);
});

it('refuses a disguised or missing file and an unchecked box, keeping the link', function () {
    [$c, , $token] = posLink();

    $this->post("/test-positionnement/{$token}", ['files' => [posCopy('virus.pdf', 'MZ'.str_repeat("\0", 50))], 'done' => '1'])->assertSessionHasErrors(['files.0' => 'Formats acceptés : PDF, JPG, PNG.']);
    $this->post("/test-positionnement/{$token}", ['files' => [], 'done' => '1'])->assertSessionHasErrors(['files' => 'Joignez votre copie (PDF, JPG ou PNG).']);
    $this->post("/test-positionnement/{$token}", ['files' => array_fill(0, 6, posCopy())])->assertSessionHasErrors(['files', 'done']);
    expect(CandidatureFile::query()->count())->toBe(0)->and(PositioningSending::query()->sole()->submitted_at)->toBeNull();
});

it('answers the same invalid page for an unknown, expired, revoked or exited link, never moving a stage back', function () {
    [$c, $test, $token] = posLink();

    $this->get('/test-positionnement/'.str_repeat('a', 64))->assertNotFound()->assertInertia(fn (Assert $p) => $p->component('public/positioning-test')->where('state', 'invalid'));

    $this->travelTo(CarbonImmutable::parse('2026-12-04 00:01', 'Europe/Paris'));
    $this->get("/test-positionnement/{$token}")->assertNotFound()->assertInertia(fn (Assert $p) => $p->where('state', 'invalid'));
    $this->post("/test-positionnement/{$token}", ['files' => [posCopy()], 'done' => '1'])->assertNotFound();

    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Europe/Paris'));
    app(CandidatureTransitions::class)->move($c->fresh(), CandidatureStatus::Rejected, admin(), crmItem(ReferenceList::CandidatureRefusalReason, 'Autre')->id);
    $this->get("/test-positionnement/{$token}")->assertNotFound()->assertInertia(fn (Assert $p) => $p->where('state', 'invalid'));
});

it('never opens a link on the host of another organisme', function () {
    [, , $token] = posLink();
    $other = Tenant::factory()->create();

    app(TenantContext::class)->run($other, function () use ($token) {
        expect(PositioningSending::findByToken($token))->toBeNull();
    });
});

it('keeps the stage when it is already past the test', function () {
    [$c, , $token] = posLink();
    $c->fresh()->forceFill(['positioning_received_on' => '2026-11-19'])->save(); // received on paper (compulsory before the interview)
    app(CandidatureTransitions::class)->move($c->fresh(), CandidatureStatus::Interview, admin());

    $this->post("/test-positionnement/{$token}", ['files' => [posCopy()], 'done' => '1'])->assertRedirect();
    expect($c->fresh()->status)->toBe(CandidatureStatus::Interview);
});

it('anonymisation clears the e-mails of the sendings and closes the link', function () {
    [$c, , $token] = posLink();
    app(CandidatureAnonymisation::class)->anonymise($c->fresh());

    $sending = PositioningSending::query()->sole();
    expect([$sending->email, $sending->subject, $sending->message])->toBe([null, null, null])
        ->and($sending->revoked_at)->not->toBeNull();
    $this->get("/test-positionnement/{$token}")->assertNotFound();
});
