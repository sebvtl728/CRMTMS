<?php

use App\Modules\Admissions\Alternance\AlternanceWriter;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\CandidatureTransitions;
use App\Modules\Admissions\Candidatures\DossierCreation;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Enrolments\Enrolment;
use App\Modules\Sessions\Fundings\Funding;
use App\Modules\Sessions\Permissions as SessionsPermissions;
use App\Modules\Sessions\Public\Admission;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/Support/admissions.php';

beforeEach(function () {
    adSetUp();
    Company::factory()->default()->create();
});

function adSession(): TrainingSession
{
    return app(SessionWriter::class)->create([
        'training_id' => adTraining()->id, 'kind' => 'inter', 'organisation_id' => null,
        'starts_on' => '2027-01-04', 'ends_on' => '2027-06-30', 'planned_hours' => '', 'location_kind' => 'remote',
        'location_text' => null, 'capacity' => 10, 'responsible_user_id' => null, 'status' => 'planned', 'notes' => null,
    ]);
}

function adAdmitted(array $data = []): Candidature
{
    $c = adCandidature(['person_id' => adPerson('Durand', ['is_individual' => true])->id, ...$data]);
    app(CandidatureTransitions::class)->move($c, CandidatureStatus::Admitted, admin());

    return $c->fresh();
}

/** The dialog defaults, completed as a user would. */
function adDossierData(Candidature $c, array $overrides = []): array
{
    return [...app(DossierCreation::class)->defaults($c), ...$overrides];
}

it('makes a particulier dossier, enrols the candidate and links it', function () {
    $session = adSession();
    $c = adAdmitted(['target_session_id' => $session->id]);
    $defaults = app(DossierCreation::class)->defaults($c);
    expect($defaults['account'])->toBe(['type' => 'person', 'id' => $c->person_id])
        ->and($defaults['unit_price'])->toBe('4500.00')
        ->and($defaults['session_id'])->toBe($session->id)
        ->and($defaults['funding'])->toBeNull();

    $dossierId = app(DossierCreation::class)->create($c, adDossierData($c), admin());

    $dossier = Dossier::query()->findOrFail($dossierId);
    expect($dossier->number)->toStartWith('D-')
        ->and($dossier->account_person_id)->toBe($c->person_id)
        ->and($c->fresh()->dossier_id)->toBe($dossierId)
        ->and(Enrolment::query()->where('person_id', $c->person_id)->where('training_session_id', $session->id)->exists())->toBeTrue();

    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->where('candidature.dossier.number', $dossier->number)->where('candidature.dossier.href', "/dossiers/{$dossierId}")
        ->where('can.create_dossier', false));
    $this->get("/dossiers/{$dossierId}")->assertInertia(fn (Assert $p) => $p
        ->where('blocks', fn ($blocks) => collect($blocks)->contains(fn ($b) => $b['title'] === 'Candidature' && $b['rows'][0]['title'] === $c->number)));
});

it('makes the dossier of an alternant with the employer as contractant and the OPCO funding', function () {
    $c = adCandidature(['person_id' => adPerson()->id]);
    app(CandidatureTransitions::class)->move($c, CandidatureStatus::Interview, admin());
    $employer = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $opco = Organisation::query()->create(['name' => 'OPCO EP']);
    app(AlternanceWriter::class)->save($c->fresh(), ['contract_type' => 'apprenticeship', 'employer_organisation_id' => $employer->id,
        'starts_on' => '2027-01-04', 'ends_on' => '2028-06-30', 'opco_organisation_id' => $opco->id], admin());
    app(CandidatureTransitions::class)->move($c->fresh(), CandidatureStatus::Admitted, admin());
    $c = $c->fresh();

    $defaults = app(DossierCreation::class)->defaults($c);
    expect($defaults['account'])->toBe(['type' => 'organisation', 'id' => $employer->id])
        ->and($defaults['unit_price'])->toBe('6000.00')
        ->and($defaults['funding']['funder_organisation_id'])->toBe($opco->id);

    $dossierId = app(DossierCreation::class)->create($c, adDossierData($c, ['funding' => [
        'funder_organisation_id' => $opco->id, 'funding_type_id' => crmItem(ReferenceList::FundingType, 'OPCO')->id, 'amount' => '6000.00',
    ]]), admin());

    $funding = Funding::query()->where('dossier_id', $dossierId)->sole();
    expect(Dossier::query()->find($dossierId)->account_organisation_id)->toBe($employer->id)
        ->and($funding->funder_organisation_id)->toBe($opco->id)
        ->and($funding->pays_directly)->toBeTrue()
        ->and((string) $funding->amount)->toBe('6000.00');

    // The contract stays while the dossier lives.
    expect(fn () => app(AlternanceWriter::class)->delete($c->fresh()))->toThrow(HttpException::class);
});

it('makes a dossier without enrolment when no session is chosen', function () {
    $c = adAdmitted();
    $dossierId = app(DossierCreation::class)->create($c, adDossierData($c), admin());

    expect(Dossier::query()->find($dossierId)->lines()->count())->toBe(1)
        ->and(Enrolment::query()->where('person_id', $c->person_id)->exists())->toBeFalse();
});

it('admits only once and only an admitted candidate', function () {
    $c = adCandidature(['person_id' => adPerson('Durand', ['is_individual' => true])->id]);
    try {
        app(DossierCreation::class)->create($c, adDossierData($c), admin());
        $this->fail('expected a 409');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409)->and($e->getMessage())->toBe('Seul un candidat admis devient apprenant.');
    }

    $c = adAdmitted();
    app(DossierCreation::class)->create($c, adDossierData($c), admin());
    try {
        app(DossierCreation::class)->create($c->fresh(), adDossierData($c), admin());
        $this->fail('expected a 409');
    } catch (HttpException $e) {
        expect($e->getMessage())->toBe('Le dossier de cette candidature existe déjà.');
    }
    expect(Dossier::query()->count())->toBe(1);
});

it('locks the stage while the dossier lives, frees it once the dossier is in the trash', function () {
    $c = adAdmitted();
    $dossierId = app(DossierCreation::class)->create($c, adDossierData($c), admin());
    expect(fn () => app(CandidatureTransitions::class)->move($c->fresh(), CandidatureStatus::Interview, admin()))->toThrow(HttpException::class)
        ->and(Admission::dossierState($dossierId))->toBe('live');

    Dossier::query()->findOrFail($dossierId)->delete();
    expect(Admission::dossierState($dossierId))->toBe('trashed');

    $again = app(DossierCreation::class)->create($c->fresh(), adDossierData($c), admin());
    expect($again)->not->toBe($dossierId)->and($c->fresh()->dossier_id)->toBe($again);

    Dossier::query()->findOrFail($again)->delete();
    app(CandidatureTransitions::class)->move($c->fresh(), CandidatureStatus::Interview, admin());
    expect($c->fresh()->status)->toBe(CandidatureStatus::Interview);
});

it('needs both the candidature and the dossier rights through the route', function () {
    $c = adAdmitted();
    $this->actingAs(adUser([Permissions::CANDIDATURES_VIEW => null, Permissions::CANDIDATURES_UPDATE => null]))
        ->post("/admissions/candidatures/{$c->id}/dossier", adDossierData($c))->assertForbidden();

    $this->actingAs(commercial())->get("/admissions/candidatures/{$c->id}/dossier/defaults")->assertOk()->assertJsonPath('account.type', 'person');
    $this->post("/admissions/candidatures/{$c->id}/dossier", adDossierData($c))->assertRedirect()
        ->assertSessionHas('status', 'Dossier de formation créé : '.$c->number.' est stagiaire.');
    expect($c->fresh()->dossier_id)->not->toBeNull();

    expect(commercial()->can(SessionsPermissions::DOSSIERS_UPDATE))->toBeTrue();
});

it('answers on the session field when the enrolment is refused (intra session of another entreprise)', function () {
    $entreprise = Organisation::query()->create(['name' => 'Brasserie du Port']);
    $intra = app(SessionWriter::class)->create([
        'training_id' => adTraining()->id, 'kind' => 'intra', 'organisation_id' => $entreprise->id,
        'starts_on' => '2027-01-04', 'ends_on' => '2027-06-30', 'planned_hours' => '', 'location_kind' => 'remote',
        'location_text' => null, 'capacity' => null, 'responsible_user_id' => null, 'status' => 'planned', 'notes' => null,
    ]);
    $c = adAdmitted(['target_session_id' => $intra->id]);

    try {
        // The user switched the contractant to the candidate as particulier.
        app(DossierCreation::class)->create($c, adDossierData($c, ['account' => ['type' => 'person', 'id' => $c->person_id]]), admin());
        test()->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toBe(['session_id'])
            ->and($e->errors()['session_id'][0])->toBe('Cette session intra est réservée à son entreprise : le contractant de ce dossier est différent.');
    }
    expect(Dossier::query()->count())->toBe(0);
});

it('proposes the entreprise of an intra targeted session as contractant, and lets the user choose either', function () {
    $entreprise = Organisation::query()->create(['name' => 'Brasserie du Port']);
    $intra = app(SessionWriter::class)->create([
        'training_id' => adTraining()->id, 'kind' => 'intra', 'organisation_id' => $entreprise->id,
        'starts_on' => '2027-01-04', 'ends_on' => '2027-06-30', 'planned_hours' => '', 'location_kind' => 'remote',
        'location_text' => null, 'capacity' => null, 'responsible_user_id' => null, 'status' => 'planned', 'notes' => null,
    ]);
    $c = adAdmitted(['target_session_id' => $intra->id]);

    $defaults = app(DossierCreation::class)->defaults($c);
    expect($defaults['account'])->toBe(['type' => 'organisation', 'id' => $entreprise->id])
        ->and($defaults['account_label'])->toBe('Brasserie du Port')
        ->and($defaults['unit_price'])->toBe('6000.00')
        ->and($defaults['person_account'])->toBe(['type' => 'person', 'id' => $c->person_id])
        ->and($defaults['prices'])->toBe(['organisation' => '6000.00', 'person' => '4500.00']);

    $dossierId = app(DossierCreation::class)->create($c, adDossierData($c), admin());
    expect(Dossier::query()->findOrFail($dossierId)->account_organisation_id)->toBe($entreprise->id)
        ->and(Enrolment::query()->where('person_id', $c->person_id)->where('training_session_id', $intra->id)->exists())->toBeTrue();
});
