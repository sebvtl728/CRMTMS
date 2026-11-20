<?php

use App\Modules\Crm\Public\FicheBlocks;
use App\Modules\Sessions\Permissions;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00', 'Europe/Paris'));
});

it('shows the end and an empty satisfaction on the trainings followed, once over', function () {
    $lea = makePerson('Durand', ['first_name' => 'Léa']);
    $dossier = l2Dossier();
    l2Enrol($dossier, plSession('2026-06-01', '2026-06-05'), $lea);
    l2Enrol($dossier, plSession('2026-10-05', '2026-10-09'), $lea);
    $viewer = userWithPermissions([Permissions::SESSIONS_VIEW, 'crm.fiches.view']);

    $rows = collect(FicheBlocks::for('person', $lea->id, $viewer))->firstWhere('key', 'sessions.enrolments')['rows'];

    expect($rows[0]['meta'])->toBeNull()
        ->and($rows[1]['meta'])->toBe('Terminée le 05/06/2026 · Satisfaction : -');
});
