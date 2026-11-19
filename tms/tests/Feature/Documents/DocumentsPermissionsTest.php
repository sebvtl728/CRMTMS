<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Role;
use App\Modules\Documents\Permissions;

it('declares the document rights with their defaults', function () {
    expect(Permissions::all())->toBe(['documents.view', 'documents.manage'])
        ->and(Permissions::scoped())->toBe(['documents.view', 'documents.manage']);

    foreach ([Role::AdminOrganisme, Role::Direction, Role::Assistant, Role::ResponsableDas] as $role) {
        expect(Permissions::defaultsFor($role))->toBe(['documents.view', 'documents.manage']);
    }
    expect(Permissions::defaultsFor(Role::Commercial))->toBe(['documents.view'])
        ->and(Permissions::defaultsFor(Role::ChargeRelations))->toBe(['documents.view'])
        ->and(Permissions::defaultsFor(Role::Formateur))->toBe([]);

    expect(Permissions::defaultScopesFor(Role::ResponsableDas))->toBe(['documents.view' => AccessScope::Das, 'documents.manage' => AccessScope::Das])
        ->and(Permissions::defaultScopesFor(Role::Commercial))->toBe(['documents.view' => AccessScope::Own])
        ->and(Permissions::defaultScopesFor(Role::ChargeRelations))->toBe(['documents.view' => AccessScope::Own])
        ->and(Permissions::defaultScopesFor(Role::Direction))->toBe([]);
});

it('registers the document rights in the catalogue under their theme', function () {
    expect(App\Modules\Core\Access\Permissions::all())->toContain('documents.view', 'documents.manage')
        ->and(App\Modules\Core\Access\Permissions::isScoped('documents.manage'))->toBeTrue();
});
