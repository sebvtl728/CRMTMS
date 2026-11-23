<?php

use App\Modules\Core\Automation\AutomationSettings;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
});

it('tells the « Étapes » card what moves the steps by itself', function () {
    $dossier = l2Dossier();

    $this->actingAs(admin(twoFactor: true))->get("/dossiers/{$dossier->id}")->assertInertia(fn ($p) => $p
        ->where('automation.convocation_days', 7)
        // Not created from a quote: no automatic convention, whatever the setting.
        ->where('automation.convention_on_quote', false));
});

it('announces the automatic convention only for a dossier from a quote, when it is on', function () {
    AutomationSettings::save(['convention_on_quote' => true]);
    $dossier = l2Dossier();
    $dossier->forceFill(['quote_id' => null])->save();

    $this->actingAs(admin(twoFactor: true))->get("/dossiers/{$dossier->id}")->assertInertia(fn ($p) => $p
        ->where('automation.convention_on_quote', false));
});
