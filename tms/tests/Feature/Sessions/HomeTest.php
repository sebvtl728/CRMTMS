<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\SessionsServiceProvider;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/lot2a.php';

beforeEach(function () {
    inTenant();
    CrmServiceProvider::registerReferenceUsage();
    SessionsServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    // Late on Monday 16 in Paris: the 7 days are counted in Paris days.
    $this->travelTo(CarbonImmutable::parse('2026-11-16 23:30', 'Europe/Paris'));
    $this->actingAs(admin(twoFactor: true));
});

it('lists on the home page the sessions that start within 7 days (Paris days), with their places', function () {
    $today = l2Session(null, ['starts_on' => '2026-11-16', 'ends_on' => '2026-11-18', 'capacity' => 8]);
    $inSeven = l2Session(null, ['starts_on' => '2026-11-23', 'ends_on' => '2026-11-23', 'kind' => 'intra', 'organisation_id' => Organisation::query()->create(['name' => 'Hôtel du Parc'])->id, 'capacity' => null]);
    $tomorrow = l2Session(null, ['starts_on' => '2026-11-17', 'ends_on' => '2026-11-19']);
    l2Session(null, ['starts_on' => '2026-11-24', 'ends_on' => '2026-11-25']); // in 8 days
    l2Session(null, ['starts_on' => '2026-11-15', 'ends_on' => '2026-11-20']); // started yesterday
    $cancelled = l2Session(null, ['starts_on' => '2026-11-18', 'ends_on' => '2026-11-18']);
    app(SessionWriter::class)->cancel($cancelled, crmItem(ReferenceList::CancelReason, 'Demande du client')->id);
    l2Session(null, ['starts_on' => '2026-11-19', 'ends_on' => '2026-11-19'])->delete();
    l2Enrol(l2Dossier(), $today, makePerson('Martin'));
    l2Enrol(l2Dossier(), $today, makePerson('Durand'));

    $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('sessions_starting.total', 3)
        ->where('sessions_starting.rows', fn ($rows) => collect($rows)->pluck('number')->all() === [$today->number, $tomorrow->number, $inSeven->number])
        ->where('sessions_starting.rows.0.training', 'HACCP 3 jours')
        ->where('sessions_starting.rows.0.places', '2 / 8 places')
        ->where('sessions_starting.rows.2.places', null)
        ->where('sessions_starting.rows.0.href', "/sessions/{$today->id}"));
});

it('shows the card within the scope of sessions.sessions.view only, and not at all without it', function () {
    $hotel = l2Session(null, ['starts_on' => '2026-11-18', 'ends_on' => '2026-11-18']);
    $health = l2Session(l2Training('Gestes de premiers secours', 'Métiers supports'), ['starts_on' => '2026-11-18', 'ends_on' => '2026-11-18']);

    $das = l2User([Permissions::SESSIONS_VIEW => AccessScope::Das]);
    followDas($das, crmItem(ReferenceList::Das, 'Métiers supports'));
    $this->actingAs($das)->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('sessions_starting.total', 1)
        ->where('sessions_starting.rows.0.number', $health->number));

    $this->actingAs(l2User([Permissions::DOSSIERS_VIEW => null]))->get('/dashboard')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sessions_starting', null));

    expect($hotel->number)->not->toBe($health->number);
});
