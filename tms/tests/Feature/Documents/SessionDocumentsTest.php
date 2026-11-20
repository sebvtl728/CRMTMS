<?php

use App\Modules\Documents\Document;
use App\Modules\Documents\DocumentStatus;
use App\Modules\Documents\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/documents.php';

beforeEach(function () {
    fakeImportDisk();
    plSetUp();
    docGotenberg();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

it('shows the documents block of the session and its summary cards', function () {
    $x = docFinishedSession();

    $this->actingAs(admin(twoFactor: true))->get("/sessions/{$x['session']->id}")->assertInertia(fn (Assert $p) => $p
        ->where('blocks', fn ($blocks) => ($b = collect($blocks)->firstWhere('key', 'documents.session')) !== null
            && $b['component'] === 'documents.session'
            && $b['props']['can_manage'] === true
            && collect($b['props']['types'])->pluck('value')->all() === ['convocation', 'completion_certificate', 'realisation_certificate']
            && count($b['props']['trainees']) === 2)
        ->where('checklist', fn ($cards) => ($c = collect($cards)->firstWhere('key', 'documents.convocations')) !== null
            && $c['value'] === '0 / 2' && $c['state'] === 'todo'
            && collect($cards)->firstWhere('key', 'documents.attestations')['state'] === 'todo'));
});

it('issues and sends the documents of the whole session, or of one trainee', function () {
    $x = docFinishedSession();
    $this->actingAs(admin(twoFactor: true))->from("/sessions/{$x['session']->id}");

    $this->post("/sessions/{$x['session']->id}/documents", ['type' => 'convocation'])->assertSessionHas('status', '2 documents émis.');
    $this->post("/sessions/{$x['session']->id}/documents/send", ['type' => 'convocation'])
        ->assertSessionHas('status', '1 envoi en cours ; 1 stagiaire sans adresse e-mail.');
    expect(Document::query()->where('enrolment_id', $x['lea']->id)->sole()->status)->toBe(DocumentStatus::Sent);

    $this->post("/enrolments/{$x['lea']->id}/documents", ['type' => 'completion_certificate'])->assertSessionHasNoErrors()
        ->assertSessionHas('status', fn ($s) => str_starts_with($s, 'Attestation de fin de formation DOC-'));
    $this->post("/sessions/{$x['session']->id}/documents", ['type' => 'convention'])->assertSessionHasErrors('type');

    $this->get("/sessions/{$x['session']->id}")->assertInertia(fn (Assert $p) => $p
        ->where('checklist', fn ($cards) => collect($cards)->firstWhere('key', 'documents.convocations')['value'] === '1 / 2'));
});

it('refuses the session actions without the right to manage documents', function () {
    $x = docFinishedSession();
    $reader = l2User([Permissions::VIEW => null, App\Modules\Sessions\Permissions::SESSIONS_VIEW => null, App\Modules\Sessions\Permissions::DOSSIERS_VIEW => null]);

    $this->actingAs($reader)->post("/sessions/{$x['session']->id}/documents", ['type' => 'convocation'])->assertForbidden();
    $this->actingAs($reader)->post("/enrolments/{$x['lea']->id}/documents", ['type' => 'convocation'])->assertForbidden();
    expect(Document::query()->count())->toBe(0);
});

it('asks for the attestations on the last day of the training', function () {
    $dossier = l2Dossier();
    $session = l2Session(null, ['starts_on' => '2026-11-16', 'ends_on' => '2026-11-19', 'planned_hours' => '28']);
    l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa']));

    $this->actingAs(admin(twoFactor: true))->get("/sessions/{$session->id}")->assertInertia(fn (Assert $p) => $p
        ->where('checklist', fn ($cards) => collect($cards)->firstWhere('key', 'documents.attestations')['state'] === 'todo'));
});
