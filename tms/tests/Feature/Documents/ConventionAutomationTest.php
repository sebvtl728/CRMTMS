<?php

use App\Modules\Core\Automation\AutomationSettings;
use App\Modules\Core\Signatures\SignatureRequest;
use App\Modules\Documents\Document;
use App\Modules\Documents\DocumentStatus;
use App\Modules\Sessions\Public\DossierHooks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/Support/documents.php';

beforeEach(function () {
    fakeImportDisk();
    plSetUp();
    docGotenberg();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00', 'Europe/Paris'));
});

it('issues the convention and asks its signature when a dossier is created from a signed quote', function () {
    AutomationSettings::save(['convention_on_quote' => true]);
    $dossier = docCompanyDossier();

    DossierHooks::createdFromQuote($dossier->id, admin());

    $convention = Document::query()->where('dossier_id', $dossier->id)->sole();
    expect($convention->type)->toBe('convention')
        ->and($convention->status)->toBe(DocumentStatus::SignaturePending)
        ->and(SignatureRequest::query()->sole()->signer_email)->toBe('claire.martin@hotel-parc.test');
});

it('does nothing while the automatism is off (the default)', function () {
    DossierHooks::createdFromQuote(docCompanyDossier()->id, admin());

    expect(Document::query()->count())->toBe(0)
        ->and(AutomationSettings::all()['convention_on_quote'])->toBeFalse();
});

it('never issues a second convention', function () {
    AutomationSettings::save(['convention_on_quote' => true]);
    $dossier = docCompanyDossier();

    DossierHooks::createdFromQuote($dossier->id, admin());
    DossierHooks::createdFromQuote($dossier->id, admin());

    expect(Document::query()->where('type', 'convention')->count())->toBe(1);
});

it('issues the convention without signature request when the contact has no e-mail', function () {
    AutomationSettings::save(['convention_on_quote' => true]);
    $dossier = l2Dossier();
    l2Enrol($dossier, l2Session(null, ['planned_hours' => '21']), makePerson('Durand', ['first_name' => 'Léa']));

    DossierHooks::createdFromQuote($dossier->id, admin());

    expect(Document::query()->where('type', 'convention')->sole()->status)->toBe(DocumentStatus::Issued)
        ->and(SignatureRequest::query()->count())->toBe(0);
});

it('leaves the dossier of a particulier alone (a contract, issued by hand)', function () {
    AutomationSettings::save(['convention_on_quote' => true]);
    $dossier = l2Dossier(makePerson('Roux', ['first_name' => 'Ana', 'is_individual' => true, 'email' => 'ana@example.test']));

    DossierHooks::createdFromQuote($dossier->id, admin());

    expect(Document::query()->count())->toBe(0);
});
