<?php

use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Dashboard\DashboardFilters;
use App\Modules\Crm\Dashboard\Indicators;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierTransitions;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-01-05 10:00', 'Europe/Paris'));
});

/** A dossier of $account with one trainee in a session starting on $starts. */
function rtDossier(Organisation|Person $account, string $starts): Dossier
{
    $dossier = l2Dossier($account);
    $end = CarbonImmutable::parse($starts)->addDays(2)->toDateString();
    l2Enrol($dossier, plSession($starts, $end), $account instanceof Organisation ? makePerson('Durand', ['first_name' => 'Léa']) : $account);

    return $dossier;
}

function rtValue(User $user, array $input = []): array
{
    return Indicators::get('sessions.retention')->value(DashboardFilters::from($input, CarbonImmutable::parse('2026-11-18 10:00', 'Europe/Paris'), 'month'), $user)->toArray();
}

it('rates the clients with two dossiers or more in the sliding window, and counts the repurchases of the period', function () {
    $owner = commercial();
    $parc = Organisation::query()->create(['name' => 'Hôtel du Parc', 'responsible_user_id' => $owner->id]);
    $lac = Organisation::query()->create(['name' => 'Hôtel du Lac']);
    $mer = Organisation::query()->create(['name' => 'Hôtel de la Mer']);
    rtDossier($parc, '2026-02-02');
    rtDossier($parc, '2026-11-09'); // second dossier, in the period: a repurchase
    rtDossier($lac, '2026-03-02');
    rtDossier($mer, '2023-03-06'); // out of the 24-month window
    rtDossier($mer, '2026-04-06');
    $cancelled = rtDossier($lac, '2026-05-04');
    app(DossierTransitions::class)->cancel($cancelled, crmItem(ReferenceList::CancelReason, 'Autre')->id);

    $all = rtValue(admin());
    expect($all['value'])->toBe(33.3)->and($all['note'])->toBe('1 client(s) sur 3 ; réachats dans la période : 1');

    // The commercial filter: clients whose fiche he is responsible for.
    expect(rtValue(admin(), ['responsible' => $owner->id])['value'])->toBe(100.0);
});

it('shows nothing without any client', function () {
    expect(rtValue(admin())['value'])->toBeNull();
});

it('is a card of the month tab, needing sessions.dossiers.view', function () {
    expect(Indicators::get('sessions.retention')->tab())->toBe('month')
        ->and(Indicators::get('sessions.retention')->permission())->toBe('sessions.dossiers.view');
});
