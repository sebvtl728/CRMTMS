<?php

use App\Modules\Core\Users\User;
use App\Modules\Sessions\Public\DossierBlocks;

it('orders the blocks of a dossier by their position, then by registration', function () {
    $block = fn (string $title) => fn (string $id, User $viewer) => ['title' => $title, 'empty' => '', 'rows' => []];
    DossierBlocks::register('test.billing', $block('Facturation'), 30);
    DossierBlocks::register('test.later', $block('Autre'));
    DossierBlocks::register('test.documents', $block('Documents'), 20);

    $titles = array_column(DossierBlocks::for('00000000-0000-0000-0000-000000000000', admin()), 'title');

    expect(array_values(array_intersect($titles, ['Documents', 'Facturation', 'Autre'])))->toBe(['Documents', 'Facturation', 'Autre']);
});

it('declares Documents before Facturation', function () {
    expect(DossierBlocks::positionOf('documents.dossier'))->toBeLessThan(DossierBlocks::positionOf('finance.billing'));
});
