<?php

use App\Exceptions\UserFacingHttpException;
use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Alternance\AlternanceWriter;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Core\Companies\Company;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Documents\AlternanceConventions;
use App\Modules\Documents\Document;
use App\Modules\Documents\DocumentIssuer;
use App\Modules\Documents\Texts\DocumentType;
use App\Modules\Sessions\Public\Paperwork;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/booklet.php';

beforeEach(function () {
    adSetUp();
    Company::factory()->default()->create(['name' => 'CAP FORMATION']);
});

it('exposes the alternance contract of a dossier to the paperwork', function () {
    $c = bookletApprentice();

    $alternance = Paperwork::dossier($c->dossier_id)['alternance'];
    expect($alternance['contract_type'])->toBe('apprenticeship')
        ->and($alternance['starts_on'])->toBe('2027-01-04')
        ->and($alternance['ends_on'])->toBe('2027-03-31')
        ->and($alternance['apprentice_person_id'])->toBe($c->person_id)
        ->and($alternance['tutor_person_id'])->not->toBeNull()
        ->and($alternance['opco_organisation_id'])->not->toBeNull();
});

function alternanceGotenberg($test): void
{
    config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    $test->html = [];
    Http::fake(['gotenberg:3000/*' => function (Request $request) use ($test) {
        foreach ($request->data() as $part) {
            if (($part['filename'] ?? null) === 'index.html') {
                $test->html[] = $part['contents'];
            }
        }

        return Http::response('%PDF-1.7 convention', 200, ['Content-Type' => 'application/pdf']);
    }]);
    Storage::fake('local');
}

it('issues the apprenticeship convention of an alternance dossier with its contract', function () {
    alternanceGotenberg($this);
    $c = bookletApprentice();

    // Issued at the creation of the dossier.
    $document = Document::query()->where('dossier_id', $c->dossier_id)->sole();

    expect($document->type)->toBe('apprenticeship_convention')
        ->and($document->recipient_snapshot['name'])->toBe('Hôtel du Parc');
    $html = end($this->html);
    expect($html)->toContain('Convention de formation – apprentissage')
        ->toContain('Léa Durand')->toContain('Paul Martin')->toContain('AKTO')
        ->toContain('04/01/2027')->toContain('31/03/2027')->toContain('contrat d&#039;apprentissage');
});

it('refuses the classic convention to an alternance dossier, and the other alternance type', function () {
    alternanceGotenberg($this);
    $c = bookletApprentice();
    $issuer = app(DocumentIssuer::class);

    expect(fn () => $issuer->issue(admin(), DocumentType::Convention, $c->dossier_id))
        ->toThrow(UserFacingHttpException::class, 'Ce dossier est en alternance')
        ->and(fn () => $issuer->issue(admin(), DocumentType::ProfessionalisationConvention, $c->dossier_id))
        ->toThrow(UserFacingHttpException::class, 'contrat d\'apprentissage');
});

it('offers the professionalisation text for a professionalisation contract', function () {
    alternanceGotenberg($this);
    $c = bookletApprentice();
    AlternanceContract::query()->where('candidature_id', $c->id)->update(['contract_type' => 'professionalisation']);
    $issuer = app(DocumentIssuer::class);
    $first = Document::query()->where('dossier_id', $c->dossier_id)->sole();
    expect(fn () => $issuer->issue(admin(), DocumentType::ProfessionalisationConvention, $c->dossier_id))
        ->toThrow(UserFacingHttpException::class, 'Une convention est déjà émise');
    $issuer->cancel(admin(), $first, 'Contrat de professionnalisation');

    app(DocumentIssuer::class)->issue(admin(), DocumentType::ProfessionalisationConvention, $c->dossier_id);
    expect(end($this->html))->toContain('Convention de formation – professionnalisation')->toContain('contrat de professionnalisation')
        ->not->toContain('contrat d&#039;apprentissage');
});

it('lists the two alternance conventions in the document templates', function () {
    $this->actingAs(admin(twoFactor: true))->get('/admin/document-texts?type=apprenticeship_convention')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('type', 'apprenticeship_convention')
            ->where('types', fn ($types) => collect($types)->pluck('value')->contains('professionalisation_convention')));
});

it('issues the alternance convention when the dossier of the candidature is created', function () {
    alternanceGotenberg($this);
    $c = bookletApprentice(); // its dossier is created at the end

    $document = Document::query()->where('dossier_id', $c->dossier_id)->sole();
    expect($document->type)->toBe('apprenticeship_convention')
        ->and($document->issued_by)->not->toBeNull();
});

it('issues nothing for a dossier without alternance contract', function () {
    alternanceGotenberg($this);
    $c = bookletApprentice();
    AlternanceContract::query()->where('candidature_id', $c->id)->delete();

    expect(app(AlternanceConventions::class)->issueForDossier($c->dossier_id, admin()))->toBeNull();
});

it('previews the alternance convention of the contract before the dossier, never stored', function () {
    alternanceGotenberg($this);
    $c = adCandidature();
    app(CandidatureTransitions::class)->move($c, CandidatureStatus::Interview, admin());
    $employer = Organisation::query()->create(['name' => 'Hôtel du Parc', 'city' => 'Nantes']);
    app(AlternanceWriter::class)->save($c->fresh(), [
        'contract_type' => 'professionalisation', 'employer_organisation_id' => $employer->id,
        'tutor_person_id' => adPerson('Martin', ['first_name' => 'Paul', 'birth_date' => null])->id,
        'starts_on' => '2027-01-04', 'ends_on' => '2027-12-31', 'opco_organisation_id' => null,
    ], admin());
    $this->html = [];

    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}/convention-alternance.pdf")
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $html = end($this->html);
    expect($html)->toContain('Convention de formation – professionnalisation')->toContain('Aperçu - non émis')
        ->toContain('Hôtel du Parc')->toContain('Paul Martin')->toContain('à compléter')
        ->toContain('<strong>à compléter</strong>');
    expect(Document::query()->count())->toBe(0);
});

it('needs a contract and the right to see the candidature for the preview', function () {
    $c = adCandidature();
    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}/convention-alternance.pdf")->assertStatus(409);
    $this->actingAs(adUser(['crm.opportunities.view' => null]))->get("/admissions/candidatures/{$c->id}/convention-alternance.pdf")->assertNotFound();
});
