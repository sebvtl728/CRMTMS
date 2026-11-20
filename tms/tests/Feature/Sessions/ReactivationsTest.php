<?php

use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Opportunities\Opportunity;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\People\Person;
use App\Modules\Sessions\Dossiers\Dossier;
use App\Modules\Sessions\Dossiers\DossierTransitions;
use App\Modules\Sessions\FollowUps\FollowUpReminder;
use App\Modules\Sessions\FollowUps\Reactivations;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-01-05 10:00', 'Europe/Paris'));
});

/** A dossier of $account whose single session runs from 12 to 16/01/2026, Léa enrolled. */
function reDossier(Organisation|Person $account, ?string $dossierResponsible = null): Dossier
{
    $dossier = l2Dossier($account, null, ['responsible_user_id' => $dossierResponsible]);
    $session = plSession('2026-01-12', '2026-01-16');
    // A particulier's dossier enrols the particulier only.
    l2Enrol($dossier, $session, $account instanceof Organisation ? makePerson('Durand', ['first_name' => 'Léa']) : $account);

    return $dossier->refresh();
}

function reRun(string $day): int
{
    test()->travelTo(CarbonImmutable::parse($day.' 06:30', 'Europe/Paris'));

    return app(Reactivations::class)->run(CarbonImmutable::parse($day, 'Europe/Paris'));
}

it('plans a reactivation call on the dossier account at 6 and 12 months, to the fiche responsible', function () {
    $owner = commercial();
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc', 'responsible_user_id' => $owner->id]);
    $dossier = reDossier($hotel);

    expect(reRun('2026-07-15'))->toBe(0)
        ->and(reRun('2026-07-16'))->toBe(1);
    $call = Activity::query()->sole();
    expect($call->organisation_id)->toBe($hotel->id)
        ->and($call->person_id)->toBeNull()
        ->and($call->responsible_user_id)->toBe($owner->id)
        ->and($call->subject)->toBe("Réactivation : HACCP 3 jours terminée il y a 6 mois ({$dossier->number})")
        ->and($call->due_at->setTimezone('Europe/Paris')->format('Y-m-d H:i'))->toBe('2026-07-16 09:00');

    expect(reRun('2026-07-17'))->toBe(0)
        ->and(reRun('2027-01-16'))->toBe(1)
        ->and(Activity::query()->count())->toBe(2)
        ->and(FollowUpReminder::query()->where('kind', 'reactivation')->pluck('months')->sort()->values()->all())->toBe([6, 12]);
});

it('targets the particulier of an individual dossier', function () {
    $owner = commercial();
    $ana = makePerson('Roux', ['first_name' => 'Ana', 'is_individual' => true, 'responsible_user_id' => $owner->id]);
    reDossier($ana);

    reRun('2026-07-16');
    expect(Activity::query()->sole()->person_id)->toBe($ana->id);
});

it('falls back on the dossier responsible, else plans nothing', function () {
    $responsible = commercial();
    reDossier(Organisation::query()->create(['name' => 'Hôtel du Parc']), $responsible->id);
    reDossier(Organisation::query()->create(['name' => 'Hôtel du Lac']));

    expect(reRun('2026-07-16'))->toBe(1)
        ->and(Activity::query()->sole()->responsible_user_id)->toBe($responsible->id)
        ->and(FollowUpReminder::query()->where('outcome', 'skipped')->value('reason'))->toBe('aucun_destinataire');
});

it('skips a client with an open opportunity or a newer live dossier, and never plans it later', function () {
    $owner = commercial();
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc', 'responsible_user_id' => $owner->id]);
    $first = reDossier($hotel);
    Opportunity::query()->create(['organisation_id' => $hotel->id, 'title' => 'Formation hygiène 2026', 'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'stage_id' => crmItem(ReferenceList::PipelineStage, 'Étude')->id, 'probability' => 40]);

    expect(reRun('2026-07-16'))->toBe(0)
        ->and(FollowUpReminder::query()->where('subject_id', $first->id)->value('reason'))->toBe('rachat_en_cours');

    // The opportunity closed since: still never planned at 6 months.
    Opportunity::query()->delete();
    expect(reRun('2026-07-20'))->toBe(0);

    // A newer dossier of the client: no reactivation at 12 months either.
    $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00', 'Europe/Paris'));
    l2Dossier($hotel);
    expect(reRun('2027-01-16'))->toBe(0)->and(Activity::query()->count())->toBe(0);
});

it('skips cancelled dossiers and trashed or anonymised fiches', function () {
    $owner = commercial();
    $cancelled = reDossier(Organisation::query()->create(['name' => 'Hôtel A', 'responsible_user_id' => $owner->id]));
    app(DossierTransitions::class)->cancel($cancelled, crmItem(ReferenceList::CancelReason, crmItem(ReferenceList::CancelReason, 'Autre')->label)->id);
    $trashed = Organisation::query()->create(['name' => 'Hôtel B', 'responsible_user_id' => $owner->id]);
    reDossier($trashed);
    $trashed->delete();
    $ana = makePerson('Roux', ['first_name' => 'Ana', 'is_individual' => true, 'responsible_user_id' => $owner->id]);
    reDossier($ana);
    app(Anonymiser::class)->anonymise($ana, 'request');

    expect(reRun('2026-07-16'))->toBe(0)->and(Activity::query()->count())->toBe(0);
});

it('uses the last session of the dossier as its end', function () {
    $owner = commercial();
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc', 'responsible_user_id' => $owner->id]);
    $dossier = reDossier($hotel);
    l2Enrol($dossier, plSession('2026-02-02', '2026-02-06'), makePerson('Petit', ['first_name' => 'Tom']));

    expect(reRun('2026-07-16'))->toBe(0)
        ->and(reRun('2026-08-06'))->toBe(1);
});
