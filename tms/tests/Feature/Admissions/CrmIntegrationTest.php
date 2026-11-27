<?php

use App\Modules\Admissions\Alternance\AlternanceContract;
use App\Modules\Admissions\Alternance\AlternanceWriter;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureFile;
use App\Modules\Admissions\Candidatures\CandidatureFiles;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Admissions\Candidatures\DossierCreation;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\CustomFields\CustomFieldDefinitions;
use App\Modules\Core\Tenancy\TenantStorage;
use App\Modules\Crm\Duplicates\Merger;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Gdpr\PersonDataExport;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Support\LinkedData;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/admissions.php';

beforeEach(function () {
    adSetUp();
    fakeImportDisk();
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00', 'Europe/Paris'));
});

it('shows the candidatures of a person on her CRM fiche, with the action to open one', function () {
    $lea = adPerson();
    $c = adCandidature(['person_id' => $lea->id]);

    $this->actingAs(commercial())->get("/crm/people/{$lea->id}")->assertInertia(fn (Assert $p) => $p
        ->where('blocks', fn ($blocks) => ($block = collect($blocks)->firstWhere('key', 'admissions.candidatures')) !== null
            && $block['title'] === 'Candidatures'
            && $block['rows'][0]['title'] === $c->number.' · CAP Cuisine'
            && $block['rows'][0]['subtitle'] === 'Identifié'
            && $block['rows'][0]['href'] === "/admissions/candidatures/{$c->id}"
            && $block['action'] === ['label' => 'Ouvrir une candidature', 'href' => "/admissions/candidatures/create?person={$lea->id}"]));

    // Who may only read gets no action; who may not read gets no block.
    $reader = adUser([Permissions::CANDIDATURES_VIEW => null, 'crm.fiches.view' => null]);
    $this->actingAs($reader)->get("/crm/people/{$lea->id}")->assertInertia(fn (Assert $p) => $p
        ->where('blocks', fn ($blocks) => (collect($blocks)->firstWhere('key', 'admissions.candidatures')['action'] ?? null) === null));
    $this->actingAs(adUser(['crm.fiches.view' => null]))->get("/crm/people/{$lea->id}")->assertInertia(fn (Assert $p) => $p
        ->where('blocks', fn ($blocks) => collect($blocks)->firstWhere('key', 'admissions.candidatures') === null));

    // The block of a person without candidature still offers the action.
    $paul = adPerson('Martin');
    $this->actingAs(commercial())->get("/crm/people/{$paul->id}")->assertInertia(fn (Assert $p) => $p
        ->where('blocks', fn ($blocks) => ($block = collect($blocks)->firstWhere('key', 'admissions.candidatures')) !== null && $block['rows'] === []));
});

it('badges the apprenant', function () {
    Company::factory()->default()->create();
    $lea = adPerson('Durand', ['is_individual' => true]);
    $c = adCandidature(['person_id' => $lea->id]);
    app(CandidatureTransitions::class)->move($c, CandidatureStatus::Admitted, admin());
    $creation = app(DossierCreation::class);
    $creation->create($c->fresh(), $creation->defaults($c->fresh()), admin());

    $this->actingAs(commercial())->get("/crm/people/{$lea->id}")->assertInertia(fn (Assert $p) => $p
        ->where('blocks', fn ($blocks) => collect($blocks)->firstWhere('key', 'admissions.candidatures')['rows'][0]['badge'] === 'Stagiaire'));
});

it('lists the changes of stage in the history of the person', function () {
    $lea = adPerson();
    $c = adCandidature(['person_id' => $lea->id]);
    app(CandidatureTransitions::class)->move($c, CandidatureStatus::PositioningSent, admin());

    $this->actingAs(commercial());
    $titles = collect($this->getJson("/crm/people/{$lea->id}/timeline")->assertOk()->json('entries'))
        ->where('type', 'candidature')->pluck('title')->all();
    expect($titles)->toBe(["Candidature {$c->number} : Test envoyé", "Candidature {$c->number} ouverte"]);
});

it('keeps an imported person that has a candidature, or is the tutor of a contract', function () {
    $lea = adPerson();
    $tutor = adPerson('Martin');
    $nothing = adPerson('Petit');
    $c = adCandidature(['person_id' => $lea->id]);
    app(CandidatureTransitions::class)->move($c, CandidatureStatus::Interview, admin());
    $employer = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    app(AlternanceWriter::class)->save($c->fresh(), ['contract_type' => 'apprenticeship', 'employer_organisation_id' => $employer->id,
        'tutor_person_id' => $tutor->id, 'starts_on' => '2027-01-04', 'ends_on' => '2028-06-30'], admin());

    expect(LinkedData::any($lea))->toBeTrue()
        ->and(LinkedData::any($tutor))->toBeTrue()
        ->and(LinkedData::any($employer))->toBeTrue()
        ->and(LinkedData::any($nothing))->toBeFalse();
});

it('repoints the candidatures and the contracts when two fiches are merged', function () {
    $keep = adPerson();
    $drop = adPerson('Durand', ['first_name' => 'Lea']);
    $mine = adCandidature(['person_id' => $keep->id]);
    $theirs = adCandidature(['person_id' => $drop->id]);
    app(CandidatureTransitions::class)->move($theirs, CandidatureStatus::Interview, admin());
    $employerKeep = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $employerDrop = Organisation::query()->create(['name' => 'Hotel du Parc']);
    app(AlternanceWriter::class)->save($theirs->fresh(), ['contract_type' => 'apprenticeship', 'employer_organisation_id' => $employerDrop->id,
        'tutor_person_id' => $drop->id, 'starts_on' => '2027-01-04', 'ends_on' => '2028-06-30'], admin());

    $this->actingAs(admin(twoFactor: true));
    app(Merger::class)->people($keep, $drop, []);
    app(Merger::class)->organisations($employerKeep, $employerDrop, []);

    expect(Candidature::query()->whereKey([$mine->id, $theirs->id])->pluck('person_id')->unique()->all())->toBe([$keep->id])
        ->and(AlternanceContract::query()->sole()->tutor_person_id)->toBe($keep->id)
        ->and(AlternanceContract::query()->sole()->employer_organisation_id)->toBe($employerKeep->id);
});

it('anonymises the candidatures of an anonymised person, files included', function () {
    $lea = adPerson();
    $field = app(CustomFieldDefinitions::class)->create('candidature', ['label' => 'Taille', 'type' => 'text']);
    $c = adCandidature(['person_id' => $lea->id, 'rqth' => true, 'adaptation_needs' => 'Fauteuil', 'interview_report' => 'RAS',
        'last_diploma' => 'Brevet', 'france_travail_id' => '1234567A', 'positioning_result' => '14/20',
        'legal_representative' => ['last_name' => 'Durand'], 'custom_values' => [$field->id => 'M']], direction());
    $file = app(CandidatureFiles::class)->store($c, 'positioning', UploadedFile::fake()->createWithContent('t.pdf', "%PDF-1.4\n%%EOF\n"), admin());

    $this->actingAs(admin(twoFactor: true));
    app(Anonymiser::class)->anonymise($lea, 'request');

    $c = Candidature::query()->findOrFail($c->id);
    expect($c->rqth)->toBeFalse()
        ->and([$c->adaptation_needs, $c->interview_report, $c->last_diploma, $c->france_travail_id, $c->positioning_result, $c->legal_representative])->toBe([null, null, null, null, null, null])
        ->and($c->custom_values)->toBe([])
        ->and($c->anonymised_at)->not->toBeNull()
        ->and($c->number)->toStartWith('C-')
        ->and(CandidatureFile::query()->count())->toBe(0)
        ->and(Storage::disk(TenantStorage::DISK)->exists($file->path))->toBeFalse();
});

it('adds the candidatures to the RGPD export of the person', function () {
    $lea = adPerson();
    $field = app(CustomFieldDefinitions::class)->create('candidature', ['label' => 'Taille', 'type' => 'text']);
    $c = adCandidature(['person_id' => $lea->id, 'last_diploma' => 'Brevet', 'custom_values' => [$field->id => 'M']]);
    app(CustomFieldDefinitions::class)->hide($field);

    $this->actingAs(admin(twoFactor: true));
    $export = PersonDataExport::for($lea->refresh());

    expect($export['candidatures'][0])->toMatchArray([
        'numero' => $c->number, 'etape' => 'Identifié', 'formation' => 'CAP Cuisine', 'code_rncp' => 'RNCP36004',
        'dernier_diplome' => 'Brevet', 'champs_personnalises' => ['Taille' => 'M'],
    ]);
});

it('keeps the visibility of the block within the candidature scope', function () {
    $lea = adPerson();
    adCandidature(['person_id' => $lea->id]);
    $own = adUser([Permissions::CANDIDATURES_VIEW => AccessScope::Own, 'crm.fiches.view' => null]);

    $this->actingAs($own)->get("/crm/people/{$lea->id}")->assertInertia(fn (Assert $p) => $p
        ->where('blocks', fn ($blocks) => collect($blocks)->firstWhere('key', 'admissions.candidatures')['rows'] === []));
});
