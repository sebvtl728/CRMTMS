<?php

use App\Modules\Admissions\Booklet\BookletAccess;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\Companies\Company;

require_once __DIR__.'/Support/admissions.php';
require_once __DIR__.'/Support/booklet.php';

beforeEach(function () {
    adSetUp();
    Company::factory()->default()->create();
});

it('lets a trainer reach only the apprentices of his séances', function () {
    $c = bookletApprentice();
    $trainer = bookletTrainerUser('Cauret');
    $other = bookletTrainerUser('Autre');

    expect(BookletAccess::allows($trainer, $c))->toBeTrue()
        ->and(BookletAccess::allows($other, $c))->toBeFalse()
        ->and(BookletAccess::allows(userWithRole(Role::Commercial), $c))->toBeFalse()
        ->and(BookletAccess::allows(admin(), $c))->toBeTrue();
});

it('keeps the candidature itself out of reach of a trainer', function () {
    $c = bookletApprentice();
    $this->actingAs(bookletTrainerUser('Cauret'))->get("/admissions/candidatures/{$c->id}")->assertNotFound();
});

it('only reaches candidatures with an alternance contract', function () {
    $c = adCandidature();
    expect(BookletAccess::allows(admin(), $c))->toBeFalse();
});

it('grants the new right by default with « Ses séances » for the trainers', function () {
    expect(Scopes::of(userWithRole(Role::Formateur), Permissions::BOOKLET_MANAGE))->toBe(AccessScope::Trainer)
        ->and(Scopes::of(admin(), Permissions::BOOKLET_MANAGE))->toBe(AccessScope::All)
        ->and(userWithRole(Role::Commercial)->can(Permissions::BOOKLET_MANAGE))->toBeFalse();
});

it('closes the booklet of an anonymised apprentice', function () {
    $c = bookletApprentice();
    app(App\Modules\Admissions\Gdpr\CandidatureAnonymisation::class)->anonymise($c);

    expect(BookletAccess::allows(admin(), $c->fresh()))->toBeFalse();
    $this->actingAs(admin(twoFactor: true))->get("/admissions/livret/{$c->id}")->assertNotFound();
});
