<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Public\FicheBlocks;
use App\Modules\Sessions\Enrolments\EnrolmentStatus;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Public\EnrolmentSatisfaction;
use App\Modules\Sessions\Public\Surveys;
use App\Modules\Sessions\Sessions\SessionWriter;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00', 'Europe/Paris'));
    app()->forgetInstance(EnrolmentSatisfaction::class);
});

it('lists the followed trainees of a session, never the waiting list, the cancelled nor those who left', function () {
    $session = plSession('2026-06-01', '2026-06-05');
    $dossier = l2Dossier();
    $lea = l2Enrol($dossier, $session, makePerson('Durand', ['first_name' => 'Léa']));
    $tom = l2Enrol($dossier, $session, makePerson('Petit', ['first_name' => 'Tom']));
    $gone = l2Enrol($dossier, $session, makePerson('Roux'));
    $gone->update(['left_on' => '2026-06-03']);
    $cancelled = l2Enrol($dossier, $session, makePerson('Blanc'));
    $cancelled->update(['status' => EnrolmentStatus::Cancelled]);

    expect(array_column(Surveys::trainees($session->id), 'enrolment_id'))->toEqualCanonicalizing([$lea->id, $tom->id])
        ->and(Surveys::trainees($session->id)[0])->toHaveKeys(['enrolment_id', 'person_id', 'dossier_id']);
});

it('describes a session and gives null for an unknown one', function () {
    $session = plSession('2026-06-01', '2026-06-05');

    expect(Surveys::session($session->id))->toMatchArray(['id' => $session->id, 'ends_on' => '2026-06-05', 'cancelled' => false])
        ->and(Surveys::session($session->id)['label'])->toContain($session->number)
        ->and(Surveys::session('01900000-0000-7000-8000-000000000000'))->toBeNull()
        ->and(Surveys::session('nope'))->toBeNull();
});

it('lists the sessions ended between two days, without the cancelled nor the trashed ones', function () {
    $in = plSession('2026-06-01', '2026-06-05');
    plSession('2026-06-01', '2026-06-10');
    $cancelled = plSession('2026-06-01', '2026-06-05');
    $cancelled->forceFill(['status' => 'cancelled', 'cancel_reason_id' => crmItem(ReferenceList::CancelReason, 'Autre')->id, 'cancelled_on' => '2026-05-20'])->save();
    $trashed = plSession('2026-06-01', '2026-06-04');
    $trashed->delete();

    expect(Surveys::endedOn('2026-06-04', '2026-06-06'))->toBe([$in->id]);
});

it('gives the contact person of the entreprise dossiers of a session, once', function () {
    $session = plSession('2026-06-01', '2026-06-05');
    $contact = makePerson('Martin', ['first_name' => 'Paul']);
    $a = l2Dossier(null, null, ['contact_person_id' => $contact->id]);
    $b = l2Dossier(null, null, ['contact_person_id' => $contact->id]);
    $lea = makePerson('Durand', ['is_individual' => true]);
    $particulier = l2Dossier($lea);
    l2Enrol($a, $session, makePerson('Petit'));
    l2Enrol($b, $session, makePerson('Roux'));
    l2Enrol($particulier, $session, $lea);

    expect(Surveys::clientContacts($session->id))->toBe([$contact->id]);
});

it('answers whether a user may see or update a session', function () {
    $session = plSession('2026-06-01', '2026-06-05');
    $viewer = l2User([Permissions::SESSIONS_VIEW => null]);
    $manager = l2User([Permissions::SESSIONS_VIEW => null, Permissions::SESSIONS_UPDATE => null]);
    $own = l2User([Permissions::SESSIONS_VIEW => AccessScope::Own]);

    expect(Surveys::allows($viewer, $session->id, false))->toBeTrue()
        ->and(Surveys::allows($viewer, $session->id, true))->toBeFalse()
        ->and(Surveys::allows($manager, $session->id, true))->toBeTrue()
        ->and(Surveys::allows($own, $session->id, false))->toBeFalse()
        ->and(Surveys::allows($manager, 'nope', false))->toBeFalse();
});

it('shows the satisfaction another module gives on the trainings followed', function () {
    $lea = makePerson('Durand', ['first_name' => 'Léa']);
    $enrolment = l2Enrol(l2Dossier(), plSession('2026-06-01', '2026-06-05'), $lea);
    $viewer = userWithPermissions([Permissions::SESSIONS_VIEW, 'crm.fiches.view']);
    EnrolmentSatisfaction::register(fn (array $ids) => in_array($enrolment->id, $ids, true) ? [$enrolment->id => '4,5/5'] : []);

    $rows = collect(FicheBlocks::for('person', $lea->id, $viewer))->firstWhere('key', 'sessions.enrolments')['rows'];

    expect($rows[0]['meta'])->toBe('Terminée le 05/06/2026 · Satisfaction : 4,5/5')
        ->and(EnrolmentSatisfaction::for([]))->toBe([]);
});
