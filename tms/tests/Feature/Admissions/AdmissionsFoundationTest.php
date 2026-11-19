<?php

use App\Modules\Admissions\Candidatures\Candidature;
use App\Modules\Admissions\Candidatures\CandidatureNumbering;
use App\Modules\Admissions\Candidatures\CandidatureStatus;
use App\Modules\Admissions\Permissions;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('numbers candidatures per Paris year', function () {
    tenant();
    $numbers = DB::transaction(fn () => [
        app(CandidatureNumbering::class)->next(CarbonImmutable::parse('2026-03-01 10:00', 'Europe/Paris')),
        app(CandidatureNumbering::class)->next(CarbonImmutable::parse('2026-06-01 10:00', 'Europe/Paris')),
        app(CandidatureNumbering::class)->next(CarbonImmutable::parse('2026-12-31 23:30', 'UTC')),
    ]);

    expect($numbers)->toBe(['C-2026-0001', 'C-2026-0002', 'C-2027-0001']);
});

it('isolates candidatures by tenant', function () {
    $a = inTenant();
    CapFormationReferenceSeeder::run($a);
    $person = makePerson('Durand', ['first_name' => 'Léa']);
    Candidature::query()->create(['person_id' => $person->id, 'number' => 'C-2026-0001', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id]);
    expect(Candidature::query()->count())->toBe(1)
        ->and(Candidature::query()->sole()->status)->toBe(CandidatureStatus::Identified)
        ->and(Candidature::query()->sole()->custom_values)->toBe([]);

    $b = inTenant(tenantOnHost('autre.localhost'));
    expect(app(TenantContext::class)->id())->toBe($b->id)
        ->and(Candidature::query()->count())->toBe(0);
});

it('declares the admissions rights, scoped but for the sensitive data', function () {
    expect(Permissions::all())->toBe([
        'admissions.candidatures.view', 'admissions.candidatures.update', 'admissions.candidatures.delete',
        'admissions.sensitive.view',
    ])->and(CorePermissions::all())->toContain(...Permissions::all());

    foreach (Permissions::all() as $permission) {
        expect(CorePermissions::isScoped($permission), $permission)->toBe($permission !== Permissions::SENSITIVE_VIEW)
            ->and(CorePermissions::label($permission)['label'] ?? '', $permission)->not->toBe('');
    }
});

it('grants the default rights and scopes per role', function () {
    expect(Permissions::defaultsFor(Role::AdminOrganisme))->toEqualCanonicalizing(Permissions::all())
        ->and(Permissions::defaultsFor(Role::Direction))->toEqualCanonicalizing([Permissions::CANDIDATURES_VIEW, Permissions::SENSITIVE_VIEW])
        ->and(Permissions::defaultsFor(Role::ResponsableDas))->toEqualCanonicalizing([Permissions::CANDIDATURES_VIEW, Permissions::CANDIDATURES_UPDATE])
        ->and(Permissions::defaultsFor(Role::Commercial))->toEqualCanonicalizing([Permissions::CANDIDATURES_VIEW, Permissions::CANDIDATURES_UPDATE])
        ->and(Permissions::defaultsFor(Role::Formateur))->toBe([]);

    $commercial = commercial();
    expect($commercial->can(Permissions::CANDIDATURES_UPDATE))->toBeTrue()
        ->and($commercial->can(Permissions::SENSITIVE_VIEW))->toBeFalse()
        ->and(direction()->can(Permissions::SENSITIVE_VIEW))->toBeTrue()
        ->and(Scopes::of(userWithRole(Role::ResponsableDas), Permissions::CANDIDATURES_VIEW))->toBe(AccessScope::Das);
});

it('seeds the refusal reasons of a candidature', function () {
    CapFormationReferenceSeeder::run(tenant());

    expect(ReferenceItem::query()->where('list', ReferenceList::CandidatureRefusalReason->value)->orderBy('position')->pluck('label')->all())
        ->toBe(['Niveau insuffisant', 'Projet non adapté', 'Pas d\'entreprise d\'accueil', 'Places complètes', 'Autre']);
});
