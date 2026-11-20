<?php

use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Candidatures\IdentifiedBadge;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\References\ReferenceList;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/admissions.php';

beforeEach(fn () => adSetUp());

it('counts the candidatures still « Identifié » in the DAS a DAS-scoped user follows', function () {
    $das = adUser([Permissions::CANDIDATURES_VIEW => AccessScope::Das]);
    followDas($das, crmItem(ReferenceList::Das, 'Hôtellerie-restauration'));
    adCandidature();
    adCandidature();
    adCandidature(['training_id' => null, 'das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]); // not his DAS
    adCandidature()->forceFill(['status' => CandidatureStatus::Interview])->save(); // past « Identifié »

    expect(IdentifiedBadge::count($das))->toBe(2);

    $this->actingAs($das)->get('/admissions/candidatures')->assertInertia(fn (Assert $p) => $p->where('candidates_badge', 2));
});

it('shows no badge to a user who sees every candidature or none', function () {
    adCandidature();

    expect(IdentifiedBadge::count(adUser([Permissions::CANDIDATURES_VIEW => null])))->toBe(0)
        ->and(IdentifiedBadge::count(adUser([Permissions::CANDIDATURES_VIEW => AccessScope::Own])))->toBe(0)
        ->and(IdentifiedBadge::count(commercial()))->toBe(0);
});

it('caches the count 60 seconds under the tenant', function () {
    $das = adUser([Permissions::CANDIDATURES_VIEW => AccessScope::Das]);
    followDas($das, crmItem(ReferenceList::Das, 'Hôtellerie-restauration'));
    adCandidature();
    expect(IdentifiedBadge::count($das))->toBe(1);

    adCandidature();
    expect(IdentifiedBadge::count($das))->toBe(1);
    $this->travel(61)->seconds();
    expect(IdentifiedBadge::count($das))->toBe(2);
});
