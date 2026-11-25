<?php

use App\Modules\Admissions\Permissions;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\References\ReferenceList;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/admissions.php';

beforeEach(fn () => adSetUp());

it('shows the age (Paris days) and the professional situation of the candidate', function () {
    $lea = adPerson('Durand', ['birth_date' => '2004-05-12', 'professional_situation_id' => crmItem(ReferenceList::ProfessionalSituation, 'Salarié')->id]);

    // 11 May, 23:30 UTC is already 12 May in Paris: the birthday counts.
    $this->travelTo(CarbonImmutable::parse('2026-05-11 23:30:00', 'UTC'));
    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/create?person={$lea->id}")->assertInertia(fn (Assert $p) => $p
        ->where('person.id', $lea->id)
        ->where('person.age', 22)
        ->where('person.situation', 'Salarié'));

    $this->travelTo(CarbonImmutable::parse('2026-05-11 12:00:00', 'Europe/Paris'));
    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/create?person={$lea->id}")->assertInertia(fn (Assert $p) => $p
        ->where('person.age', 21));
});

it('leaves age and situation empty when the person has none', function () {
    $paul = makePerson('Martin');

    $this->actingAs(admin(twoFactor: true))->get("/admissions/candidatures/create?person={$paul->id}")->assertInertia(fn (Assert $p) => $p
        ->where('person.age', null)
        ->where('person.situation', null));
});

it('names the DAS responsibles who will receive the candidature, by DAS', function () {
    $hotel = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $support = crmItem(ReferenceList::Das, 'Métiers supports');

    $claire = adUser([Permissions::CANDIDATURES_VIEW => AccessScope::Das]);
    $claire->forceFill(['name' => 'Claire Responsable'])->save();
    followDas($claire, $hotel);

    $marc = adUser([Permissions::CANDIDATURES_VIEW => AccessScope::Das]);
    $marc->forceFill(['name' => 'Marc Support'])->save();
    followDas($marc, $support);

    // Follows the DAS but sees every candidature: not a DAS responsible.
    $all = adUser([Permissions::CANDIDATURES_VIEW => null]);
    followDas($all, $hotel);

    // Deactivated: receives nothing.
    $gone = adUser([Permissions::CANDIDATURES_VIEW => AccessScope::Das]);
    followDas($gone, $hotel);
    $gone->deactivate();

    $this->actingAs(admin(twoFactor: true))->get('/admissions/candidatures/create')->assertInertia(fn (Assert $p) => $p
        ->where('das_recipients', [$hotel->id => ['Claire Responsable'], $support->id => ['Marc Support']]));
});
