<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Documents\Document;
use App\Modules\Documents\DocumentIssuer;
use App\Modules\Documents\DocumentStatus;
use App\Modules\Documents\Permissions;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Permissions as SessionsPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/documents.php';

beforeEach(function () {
    fakeImportDisk();
    plSetUp();
    docGotenberg();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

it('shows the documents block on the dossier, with what the viewer may do', function () {
    $dossier = docCompanyDossier();
    $doc = app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, $dossier->id);

    $this->actingAs(admin(twoFactor: true))->get("/dossiers/{$dossier->id}")->assertInertia(fn (Assert $p) => $p
        ->where('blocks', fn ($blocks) => ($block = collect($blocks)->firstWhere('key', 'documents.dossier')) !== null
            && $block['component'] === 'documents.dossier'
            && $block['props']['can_manage'] === true
            && $block['props']['issuable'] === [['value' => 'convention', 'label' => 'Convention de formation']]
            && $block['props']['documents'][0]['number'] === $doc->number
            && $block['props']['documents'][0]['status_label'] === 'Émis'
            && $block['props']['documents'][0]['defaults']['to'] === 'claire.martin@hotel-parc.test'));
});

it('issues, previews, downloads, sends and cancels from the dossier', function () {
    Mail::fake();
    $dossier = docCompanyDossier();
    $this->actingAs(admin(twoFactor: true))->from("/dossiers/{$dossier->id}");

    $this->post("/dossiers/{$dossier->id}/documents", ['type' => 'convention'])->assertRedirect("/dossiers/{$dossier->id}")
        ->assertSessionHas('status', 'Convention de formation DOC-2026-00001 émise.');
    $doc = Document::query()->sole();

    $this->get("/documents/{$doc->id}/pdf?apercu=1")->assertOk()->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename="DOC-2026-00001.pdf"');
    $this->get("/documents/{$doc->id}/pdf")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="DOC-2026-00001.pdf"');

    $this->post("/documents/{$doc->id}/send", ['to' => 'claire.martin@hotel-parc.test', 'subject' => 'Convention', 'body' => 'Bonjour.'])
        ->assertSessionHasNoErrors()->assertSessionHas('status');
    expect($doc->fresh()->status)->toBe(DocumentStatus::Sent);

    $this->post("/documents/{$doc->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post("/documents/{$doc->id}/cancel", ['reason' => 'Mauvaises dates'])->assertSessionHasNoErrors();
    expect($doc->fresh()->status)->toBe(DocumentStatus::Cancelled);
});

it('answers 404 out of the scope and 403 without the right to manage', function () {
    $mine = docCompanyDossier();
    $doc = app(DocumentIssuer::class)->issue(admin(), DocumentType::Convention, $mine->id);

    $stranger = l2User([Permissions::VIEW => AccessScope::Own, Permissions::MANAGE => AccessScope::Own, SessionsPermissions::DOSSIERS_VIEW => null]);
    $this->actingAs($stranger);
    $this->get("/documents/{$doc->id}/pdf")->assertNotFound();
    $this->post("/documents/{$doc->id}/cancel", ['reason' => 'x'])->assertNotFound();
    $this->post("/dossiers/{$mine->id}/documents", ['type' => 'convention'])->assertNotFound();

    $reader = l2User([Permissions::VIEW => null, SessionsPermissions::DOSSIERS_VIEW => null]);
    $this->actingAs($reader);
    $this->get("/documents/{$doc->id}/pdf")->assertOk();
    $this->post("/documents/{$doc->id}/cancel", ['reason' => 'x'])->assertForbidden();
    $this->post("/documents/{$doc->id}/send", ['to' => 'a@b.test', 'subject' => 's', 'body' => 'b'])->assertForbidden();
    $this->post("/dossiers/{$mine->id}/documents", ['type' => 'convention'])->assertForbidden();
    $this->get("/dossiers/{$mine->id}")->assertInertia(fn (Assert $p) => $p
        ->where('blocks', fn ($blocks) => collect($blocks)->firstWhere('key', 'documents.dossier')['props']['can_manage'] === false));

    $this->actingAs(admin(twoFactor: true))->post("/dossiers/{$mine->id}/documents", ['type' => 'inconnu'])->assertSessionHasErrors('type');
});
