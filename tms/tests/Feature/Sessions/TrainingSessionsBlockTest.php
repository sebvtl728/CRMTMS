<?php

use App\Modules\Catalogue\Permissions as CataloguePermissions;
use App\Modules\Catalogue\TrainingBlocks;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionWriter;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
});

/**
 * @return array<string, mixed>|null
 */
function tsbData(User $user, ?string $trainingId = null): ?array
{
    $block = collect(TrainingBlocks::for($trainingId ?? l2Training()->id, $user))->firstWhere('key', 'sessions');

    return $block === null ? null : $block['data'];
}

it('counts the sessions of the training and lists them, running and upcoming first', function () {
    $upcoming = plSession('2026-12-07', '2026-12-09');
    $running = plSession('2026-11-16', '2026-11-18');
    $recent = plSession('2026-10-01', '2026-10-03');
    plSession('2025-06-01', '2025-06-03'); // older than 12 months
    $cancelled = plSession('2026-12-14', '2026-12-15');
    app(SessionWriter::class)->cancel($cancelled, crmItem(ReferenceList::CancelReason, 'Effectif insuffisant')->id);
    plSession('2026-12-07', '2026-12-09', ['training_id' => l2Training('Autre formation')->id]);

    $data = tsbData(userWithPermissions([Permissions::SESSIONS_VIEW]));

    expect($data['indicators'])->toBe(['upcoming' => 1, 'running' => 1, 'ended' => 1, 'trainees' => 0])
        ->and(array_column($data['sessions'], 'number'))->toBe([$running->number, $upcoming->number, $recent->number])
        ->and($data['can_create'])->toBeFalse()
        ->and($data['create_href'])->toBe('/sessions/create?training='.l2Training()->id);
});

it('counts the trainees of the sessions started in the last 12 months, not the cancelled nor waitlisted', function () {
    $session = plSession('2026-10-01', '2026-10-03');
    $dossier = l2Dossier();
    l2Enrol($dossier, $session, makePerson('Durand'));
    l2Enrol($dossier, $session, makePerson('Martin'));
    $cancelled = l2Enrol($dossier, $session, makePerson('Petit'));
    $cancelled->forceFill(['status' => 'cancelled'])->save();
    $old = plSession('2025-06-01', '2025-06-03');
    l2Enrol(l2Dossier(), $old, makePerson('Ancien'));

    expect(tsbData(userWithPermissions([Permissions::SESSIONS_VIEW]))['indicators']['trainees'])->toBe(2);
});

it('follows the scope of sessions.sessions.view', function () {
    $mine = l2User([Permissions::SESSIONS_VIEW => AccessScope::Own]);
    plSession('2026-12-07', '2026-12-09', ['responsible_user_id' => $mine->id]);
    plSession('2026-12-14', '2026-12-16');

    expect(tsbData($mine)['indicators']['upcoming'])->toBe(1)
        ->and(tsbData(l2User([Permissions::SESSIONS_VIEW => AccessScope::All]))['indicators']['upcoming'])->toBe(2);
});

it('has no block without the right to view sessions', function () {
    plSession();

    expect(tsbData(userWithPermissions([CataloguePermissions::VIEW])))->toBeNull();
});

it('offers « Créer une session » only with the right to create one', function () {
    $creator = userWithPermissions([Permissions::SESSIONS_VIEW, Permissions::SESSIONS_UPDATE]);

    expect(tsbData($creator)['can_create'])->toBeTrue();
});

it('preselects an offered training of the user on the session form', function () {
    $training = l2Training();
    $this->actingAs(userWithPermissions([Permissions::SESSIONS_VIEW, Permissions::SESSIONS_UPDATE]));

    $this->get("/sessions/create?training={$training->id}")
        ->assertInertia(fn (Assert $page) => $page->where('defaults.training_id', $training->id));
});

it('ignores an unknown, inactive or out-of-DAS training', function () {
    $inactive = l2Training('Désactivée');
    $inactive->update(['is_active' => false]);
    $outside = l2Training('Hors DAS', 'Métiers supports');
    $user = l2User([Permissions::SESSIONS_VIEW => null, Permissions::SESSIONS_UPDATE => AccessScope::Das]);
    $this->actingAs($user);

    foreach (['11111111-1111-7111-8111-111111111111', 'pas-un-id', $inactive->id, $outside->id] as $id) {
        $this->get('/sessions/create?training='.$id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('defaults.training_id', null));
    }
});
