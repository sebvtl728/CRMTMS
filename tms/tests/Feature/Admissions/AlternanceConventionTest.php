<?php

use App\Modules\Core\Companies\Company;
use App\Modules\Sessions\Public\Paperwork;

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
    Illuminate\Support\Facades\Http::fake(['gotenberg:3000/*' => function (Illuminate\Http\Client\Request $request) use ($test) {
        foreach ($request->data() as $part) {
            if (($part['filename'] ?? null) === 'index.html') {
                $test->html[] = $part['contents'];
            }
        }

        return Illuminate\Support\Facades\Http::response('%PDF-1.7 convention', 200, ['Content-Type' => 'application/pdf']);
    }]);
    Illuminate\Support\Facades\Storage::fake('local');
}

it('issues the apprenticeship convention of an alternance dossier with its contract', function () {
    alternanceGotenberg($this);
    $c = bookletApprentice();

    $document = app(App\Modules\Documents\DocumentIssuer::class)->issue(admin(), App\Modules\Documents\Texts\DocumentType::ApprenticeshipConvention, $c->dossier_id);

    expect($document->type)->toBe('apprenticeship_convention')
        ->and($document->recipient_snapshot['name'])->toBe('Hôtel du Parc');
    $html = end($this->html);
    expect($html)->toContain('Convention de formation – apprentissage')
        ->toContain('Léa Durand')->toContain('Paul Martin')->toContain('AKTO')
        ->toContain('04/01/2027')->toContain('31/03/2027')->toContain("contrat d&#039;apprentissage");
});

it('refuses the classic convention to an alternance dossier, and the other alternance type', function () {
    alternanceGotenberg($this);
    $c = bookletApprentice();
    $issuer = app(App\Modules\Documents\DocumentIssuer::class);

    expect(fn () => $issuer->issue(admin(), App\Modules\Documents\Texts\DocumentType::Convention, $c->dossier_id))
        ->toThrow(App\Exceptions\UserFacingHttpException::class, 'Ce dossier est en alternance')
        ->and(fn () => $issuer->issue(admin(), App\Modules\Documents\Texts\DocumentType::ProfessionalisationConvention, $c->dossier_id))
        ->toThrow(App\Exceptions\UserFacingHttpException::class, 'contrat d\'apprentissage');
});

it('offers the professionalisation text for a professionalisation contract', function () {
    alternanceGotenberg($this);
    $c = bookletApprentice();
    App\Modules\Admissions\Alternance\AlternanceContract::query()->where('candidature_id', $c->id)->update(['contract_type' => 'professionalisation']);

    app(App\Modules\Documents\DocumentIssuer::class)->issue(admin(), App\Modules\Documents\Texts\DocumentType::ProfessionalisationConvention, $c->dossier_id);
    expect(end($this->html))->toContain('Convention de formation – professionnalisation')->toContain('contrat de professionnalisation')
        ->not->toContain("contrat d&#039;apprentissage");
});

it('lists the two alternance conventions in the document templates', function () {
    $this->actingAs(admin(twoFactor: true))->get('/admin/document-texts?type=apprenticeship_convention')->assertOk()
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->where('type', 'apprenticeship_convention')
            ->where('types', fn ($types) => collect($types)->pluck('value')->contains('professionalisation_convention')));
});
