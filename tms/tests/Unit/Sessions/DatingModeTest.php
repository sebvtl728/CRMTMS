<?php

use App\Modules\Sessions\Modules\DatingMode;

it('names the two ways of following a module in plain words, each with what it implies', function () {
    expect(DatingMode::options())->toBe([
        ['value' => 'interval', 'label' => 'Période, heures saisies chaque mois',
            'description' => DatingMode::Interval->description()],
        ['value' => 'seances', 'label' => 'Séances planifiées',
            'description' => DatingMode::Seances->description()],
    ])->and(DatingMode::Interval->description())->toContain('heures réalisées', 'chaque mois')
        ->and(DatingMode::Seances->description())->toContain('émargement');
});
