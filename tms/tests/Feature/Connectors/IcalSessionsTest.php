<?php

use App\Modules\Connectors\Calendar\CalendarToken;
use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\Seance;
use App\Modules\Sessions\Planning\SeanceCalendar;
use App\Modules\Sessions\Planning\Seances;
use App\Modules\Sessions\Trainers\Trainer;
use App\Modules\Sessions\Trainers\Trainers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';
require_once __DIR__.'/../Sessions/Support/attendance.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00', 'Europe/Paris'));
});

/**
 * The VEVENT blocks of a feed, as « NAME;PARAMS » => value (folded lines joined).
 *
 * @return list<array<string, string>>
 */
function icsSessionEvents(string $ics): array
{
    $events = [];
    $current = null;
    foreach (explode("\r\n", rtrim(str_replace("\r\n ", '', $ics), "\r\n")) as $line) {
        if ($line === 'BEGIN:VEVENT') {
            $current = [];
        } elseif ($line === 'END:VEVENT') {
            $events[] = $current;
            $current = null;
        } elseif ($current !== null) {
            [$name, $value] = explode(':', $line, 2);
            $current[$name] ??= $value;
        }
    }

    return $events;
}

/**
 * A user of the Formateur role and his trainer record.
 *
 * @return array{0: User, 1: Trainer}
 */
function icsFormateur(): array
{
    $user = userWithRole(Role::Formateur);

    return [$user, Trainers::forUser($user) ?? Trainers::ensureForUser($user)];
}

it('publishes the séances of a trainer with their place, never a trainee', function () {
    [$user, $trainer] = icsFormateur();
    $session = plSession('2026-09-01', '2026-11-30', ['title' => 'Commis de cuisine']);
    $module = plModule($session);
    $room = plRoom('Salle A');
    $room->update(['address' => '12 rue des Lilas, Annecy']);
    $inRoom = plSeance($module, '2026-09-02', '09:00', '12:30', ['trainer_id' => $trainer->id, 'room_id' => $room->id]);
    $outside = plSeance($module, '2026-09-03', '13:30', '17:00', ['trainer_id' => $trainer->id, 'place_text' => 'Restaurant Le Lac']);
    plSeance($module, '2026-09-04'); // another trainer's (none): not his
    atEnrol($session, 'Confidentiel');
    $token = CalendarToken::issue($user);

    $ics = $this->get('/calendar/'.$token.'.ics')->assertOk()->getContent();
    $events = icsSessionEvents($ics);

    expect($events)->toHaveCount(2)
        ->and($ics)->not->toContain('Confidentiel')
        ->and($events[0])->toMatchArray([
            'UID' => 'seance-'.$inRoom->id,
            'SUMMARY' => 'Commis de cuisine ('.$session->number.') — '.$module->title,
            'DTSTART;TZID=Europe/Paris' => '20260902T090000',
            'DURATION' => 'PT210M',
            'LOCATION' => 'Salle A\\, 12 rue des Lilas\\, Annecy',
            'STATUS' => 'CONFIRMED',
        ])
        ->and($events[1])->toMatchArray([
            'UID' => 'seance-'.$outside->id,
            'DTSTART;TZID=Europe/Paris' => '20260903T133000',
            'LOCATION' => 'Restaurant Le Lac',
        ]);
});

it('keeps 9 h–17 h in Paris time across the change of hour', function () {
    [$user, $trainer] = icsFormateur();
    $session = plSession('2026-10-01', '2026-11-30');
    $module = plModule($session);
    // Summer time ends on Sunday 25 October 2026; spring 2027 starts on 28 March.
    plSeance($module, '2026-10-23', '09:00', '17:00', ['trainer_id' => $trainer->id, 'break_minutes' => 60]);
    plSeance($module, '2026-10-26', '09:00', '17:00', ['trainer_id' => $trainer->id, 'break_minutes' => 60]);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'Europe/Paris'));

    $events = icsSessionEvents($this->get('/calendar/'.CalendarToken::issue($user).'.ics')->assertOk()->getContent());

    expect(array_column($events, 'DTSTART;TZID=Europe/Paris'))->toBe(['20261023T090000', '20261026T090000'])
        // The event spans 9 h–17 h (8 h of real time on both sides of the change).
        ->and(array_column($events, 'DURATION'))->toBe(['PT480M', 'PT480M'])
        ->and(Seance::query()->pluck('duration_minutes')->unique()->values()->all())->toBe([420]);
});

it('marks a cancelled séance, leaves out trashed séances and sessions', function () {
    [$user, $trainer] = icsFormateur();
    $session = plSession('2026-09-01', '2026-09-30');
    $module = plModule($session);
    $cancelled = plSeance($module, '2026-09-02', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    app(Seances::class)->cancel($cancelled, 'Formateur malade');
    $deleted = plSeance($module, '2026-09-03', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    app(Seances::class)->delete($deleted);
    $trashed = plSession('2026-09-01', '2026-09-30', ['title' => 'Supprimée']);
    plSeance(plModule($trashed), '2026-09-04', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    $trashed->delete();

    $ics = $this->get('/calendar/'.CalendarToken::issue($user).'.ics')->assertOk()->getContent();
    $events = icsSessionEvents($ics);

    expect($events)->toHaveCount(1)
        ->and($events[0]['UID'])->toBe('seance-'.$cancelled->id)
        ->and($events[0]['STATUS'])->toBe('CANCELLED')
        ->and($ics)->not->toContain('Formateur malade');
});

it('publishes the séances of the sessions a user is responsible for, with his CRM actions', function () {
    $user = userWithPermissions([Permissions::SESSIONS_VIEW, Permissions::SEANCES_VIEW]);
    $mine = plSession('2026-09-01', '2026-09-30', ['responsible_user_id' => $user->id]);
    $other = plSession('2026-09-01', '2026-09-30');
    $seance = plSeance(plModule($mine), '2026-09-02');
    plSeance(plModule($other), '2026-09-02');

    // No CRM: « Mon agenda » opens for the séances, without Outlook.
    $this->actingAs($user)->get('/settings/calendar')->assertOk()
        ->assertInertia(fn ($page) => $page->where('outlook', null)->where('crm', false)->where('sources', true));
    $this->post('/settings/calendar/outlook/connect')->assertForbidden();

    $events = icsSessionEvents($this->get('/calendar/'.CalendarToken::issue($user).'.ics')->assertOk()->getContent());
    expect(array_column($events, 'UID'))->toBe(['seance-'.$seance->id])
        ->and($events[0]['URL'])->toBe(url('/sessions/'.$mine->id));
});

it('lets a trainer without CRM open « Mon agenda »; others without anything get 403 and 404', function () {
    [$user] = icsFormateur();
    $this->actingAs($user)->get('/settings/calendar')->assertOk();

    $nobody = userWithPermissions([Permissions::SESSIONS_VIEW]);
    $token = CalendarToken::issue($nobody);
    $this->actingAs($nobody)->get('/settings/calendar')->assertForbidden();
    $this->get('/calendar/'.$token.'.ics')->assertNotFound();
});

it('never publishes a séance of another organisme', function () {
    [$user, $trainer] = icsFormateur();
    $session = plSession('2026-09-01', '2026-09-30');
    $own = plSeance(plModule($session), '2026-09-02', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    $token = CalendarToken::issue($user);
    $home = tenant();

    $foreign = tenantOnHost('autre.tms.test');
    plSetUp($foreign);
    $theirs = plSeance(plModule(plSession('2026-09-01', '2026-09-30')), '2026-09-02', '09:00', '12:30');
    // Even pointing at the same trainer id, RLS keeps it out.
    DB::table('seances')->where('id', $theirs->id)->update(['trainer_id' => $trainer->id]);
    inTenant($home);

    $events = icsSessionEvents($this->get('/calendar/'.$token.'.ics')->assertOk()->getContent());
    expect(array_column($events, 'UID'))->toBe(['seance-'.$own->id]);
});

it('builds the feed of 100 séances in a bounded number of queries', function () {
    [$user, $trainer] = icsFormateur();
    $session = plSession('2026-09-01', '2026-09-30', ['responsible_user_id' => null]);
    $module = plModule($session);
    $room = plRoom('Salle A');
    $token = CalendarToken::issue($user);
    $count = function () use ($token) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/calendar/'.$token.'.ics')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    plSeance($module, '2026-09-01', '08:00', '08:30', ['trainer_id' => $trainer->id, 'room_id' => $room->id]);
    $few = $count();

    $created = 1;
    foreach (range(2, 30) as $day) {
        foreach ([['09:00', '10:00'], ['10:00', '11:00'], ['13:00', '14:00'], ['14:00', '15:00']] as [$start, $end]) {
            if ($created >= 100) {
                break 2;
            }
            plSeance($module, sprintf('2026-09-%02d', $day), $start, $end, ['trainer_id' => $trainer->id, 'room_id' => $room->id]);
            $created++;
        }
    }

    // (The first request also recorded the use of the token.)
    expect($count())->toBeLessThanOrEqual($few);
});

it('publishes the séances of a responsible only within the scope of sessions.seances.view', function () {
    // « Ses DAS » without the DAS of the session: responsible of it, yet out of his scope.
    $das = l2User([Permissions::SEANCES_VIEW => AccessScope::Das]);
    $session = plSession('2026-09-01', '2026-09-30', ['responsible_user_id' => $das->id]);
    $seance = plSeance(plModule($session), '2026-09-02');
    $token = CalendarToken::issue($das);

    $this->get('/calendar/'.$token.'.ics')->assertNotFound();
    expect(SeanceCalendar::serves($das))->toBeFalse();

    followDas($das, crmItem(ReferenceList::Das, 'Hôtellerie-restauration'));
    Scopes::forget();
    $events = icsSessionEvents($this->get('/calendar/'.$token.'.ics')->assertOk()->getContent());
    expect(array_column($events, 'UID'))->toBe(['seance-'.$seance->id]);

    // « Ses séances »: responsible of the session, only the séances he animates.
    [$formateur, $trainer] = icsFormateur();
    $other = plSession('2026-09-01', '2026-09-30', ['responsible_user_id' => $formateur->id]);
    $module = plModule($other);
    $his = plSeance($module, '2026-09-03', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    plSeance($module, '2026-09-04');
    $events = icsSessionEvents($this->get('/calendar/'.CalendarToken::issue($formateur).'.ics')->assertOk()->getContent());
    expect(array_column($events, 'UID'))->toBe(['seance-'.$his->id]);
});

it('never falls back to the address of an individual session (the trainee\'s home)', function () {
    [$user, $trainer] = icsFormateur();
    $group = plSession('2026-09-01', '2026-09-30', ['location_kind' => 'address', 'location_text' => '3 place du Marché, Annecy']);
    $individual = plSession('2026-09-01', '2026-09-30', ['kind' => 'individual', 'capacity' => null, 'location_kind' => 'address', 'location_text' => '8 rue Privée, Thônes']);
    plSeance(plModule($group), '2026-09-02', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    plSeance(plModule($individual), '2026-09-03', '09:00', '12:30', ['trainer_id' => $trainer->id]);

    $ics = $this->get('/calendar/'.CalendarToken::issue($user).'.ics')->assertOk()->getContent();
    $events = icsSessionEvents($ics);

    expect($events[0]['LOCATION'])->toBe('3 place du Marché\\, Annecy')
        ->and($events[1])->not->toHaveKey('LOCATION')
        ->and($ics)->not->toContain('Privée');
});
