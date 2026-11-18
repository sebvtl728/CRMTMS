<?php

use App\Modules\Admissions\AdmissionsVisibility;
use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidaturePresenter;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\People\Person;
use App\Modules\Sessions\Sessions\SessionWriter;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/admissions.php';

beforeEach(fn () => adSetUp());

it('reaches the candidatures of the scope of the right', function () {
    $mine = adUser([Permissions::CANDIDATURES_VIEW => AccessScope::Own]);
    $byMe = adCandidature([], $mine);
    $responsible = adCandidature(['responsible_user_id' => $mine->id]);
    $other = adCandidature();

    $ids = AdmissionsVisibility::candidatures(Candidature::query(), $mine, Permissions::CANDIDATURES_VIEW)->pluck('id')->all();
    expect($ids)->toEqualCanonicalizing([$byMe->id, $responsible->id])
        ->and(AdmissionsVisibility::allows($mine, Permissions::CANDIDATURES_VIEW, $other))->toBeFalse();

    $das = adUser([Permissions::CANDIDATURES_VIEW => AccessScope::Das]);
    followDas($das, crmItem(ReferenceList::Das, 'Hôtellerie-restauration'));
    $support = adCandidature(['training_id' => null, 'das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]);
    expect(AdmissionsVisibility::allows($das, Permissions::CANDIDATURES_VIEW, $other))->toBeTrue()
        ->and(AdmissionsVisibility::allows($das, Permissions::CANDIDATURES_VIEW, $support))->toBeFalse();

    $this->actingAs($mine)->get("/admissions/candidatures/{$other->id}")->assertNotFound();
    $this->get("/admissions/candidatures/{$byMe->id}")->assertOk();
    $this->actingAs(adUser([]))->get("/admissions/candidatures/{$byMe->id}")->assertNotFound();
});

it('sends the sensitive data only to who may see them', function () {
    $c = adCandidature(['rqth' => true, 'adaptation_needs' => 'Fauteuil', 'interview_report' => 'Motivé'], direction());

    $this->actingAs(commercial())->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->component('admissions/candidatures/show')
        ->missing('candidature.rqth')->missing('candidature.adaptation_needs')->missing('candidature.interview_report')
        ->where('can.sensitive', false));

    $this->actingAs(direction(twoFactor: true))->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->where('candidature.rqth', true)->where('candidature.adaptation_needs', 'Fauteuil')->where('candidature.interview_report', 'Motivé')
        ->where('can.sensitive', true));
});

it('shows the identity of the person, the CRM link only when the CRM lets the viewer open it', function () {
    $c = adCandidature();

    $this->actingAs(commercial())->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->where('candidature.person.label', 'Léa Durand')
        ->where('candidature.person.crm_href', "/crm/people/{$c->person_id}"));

    $this->actingAs(adUser([Permissions::CANDIDATURES_VIEW => null]))->get("/admissions/candidatures/{$c->id}")->assertInertia(fn (Assert $p) => $p
        ->where('candidature.person.crm_href', null));
});

it('still opens the candidature of an anonymised person', function () {
    $c = adCandidature();
    Person::query()->whereKey($c->person_id)->update(['anonymised_at' => now()]);

    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/{$c->id}")->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('candidature.person.label', 'Personne anonymisée'));
});

it('flags a minor at the start of the targeted session', function () {
    $session = app(SessionWriter::class)->create([
        'training_id' => adTraining()->id, 'kind' => 'inter', 'organisation_id' => null,
        'starts_on' => '2027-01-05', 'ends_on' => '2027-06-30', 'planned_hours' => '', 'location_kind' => 'remote',
        'location_text' => null, 'capacity' => 10, 'responsible_user_id' => null, 'status' => 'planned', 'notes' => null,
    ]);
    $c = adCandidature(['person_id' => adPerson('Petit', ['birth_date' => '2010-01-01'])->id, 'target_session_id' => $session->id]);

    expect(CandidaturePresenter::show($c, admin())['is_minor'])->toBeTrue()
        ->and(CandidaturePresenter::show(adCandidature(), admin())['is_minor'])->toBeFalse();
});
