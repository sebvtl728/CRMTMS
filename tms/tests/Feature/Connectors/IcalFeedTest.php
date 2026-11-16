<?php

use App\Modules\Connectors\Calendar\CalendarToken;
use App\Modules\Connectors\Calendar\IcalFeed;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Organisations\Organisation;
use App\Providers\RateLimitServiceProvider;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
});

function icalAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Europe/Paris');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function icalAction(User $responsible, string $type, string $due, array $attributes = []): Activity
{
    return Activity::query()->create([
        'type_id' => crmItem(ReferenceList::ActionType, $type)->id,
        'subject' => $type.' '.$due,
        'due_at' => icalAt($due),
        'duration_minutes' => 60,
        'organisation_id' => $attributes['organisation_id'] ?? Organisation::query()->firstOrCreate(['name' => 'Hôtel du Lac'])->id,
        'responsible_user_id' => $responsible->id,
        ...$attributes,
    ]);
}

/** Path of the feed of a plain token. */
function feedPath(string $token): string
{
    return '/calendar/'.$token.'.ics';
}

/**
 * Logical lines of a feed (folded lines joined back, RFC 5545 §3.1).
 *
 * @return list<string>
 */
function unfoldedLines(string $ics): array
{
    return explode("\r\n", rtrim(str_replace("\r\n ", '', $ics), "\r\n"));
}

/**
 * The VEVENT blocks of a feed, as « NAME;PARAMS » => value (first one wins).
 *
 * @return list<array<string, string>>
 */
function icalEvents(string $ics): array
{
    $events = [];
    $current = null;
    foreach (unfoldedLines($ics) as $line) {
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

it('serves the meetings and calls of its owner as an RFC 5545 calendar', function () {
    $this->travelTo(icalAt('2026-11-18 10:00:00'));
    $user = commercial();
    $opportunity = makeOpportunity(Organisation::query()->create(['name' => 'Hôtel du Lac']), ['title' => 'Formation HACCP']);
    $meeting = icalAction($user, 'Rendez-vous physique', '2026-11-19 09:00:00', [
        'location' => 'Annecy', 'opportunity_id' => $opportunity->id, 'organisation_id' => null,
        'report' => 'Compte rendu confidentiel', 'subject' => 'Sujet du rendez-vous',
    ]);
    $call = icalAction($user, 'Appel', '2026-11-20 11:00:00', ['duration_minutes' => null, 'done_at' => icalAt('2026-11-20 11:05:00'), 'result_id' => crmItem(ReferenceList::ActionResult, 'Réussi')->id, 'report' => 'Appel confidentiel']);
    $token = CalendarToken::issue($user);

    $response = $this->get(feedPath($token))->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertHeader('Cache-Control', 'max-age=900, private');
    $ics = $response->getContent();

    expect($ics)->toStartWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n")
        ->and($ics)->toEndWith("END:VCALENDAR\r\n")
        ->and(unfoldedLines($ics))->toContain('BEGIN:VTIMEZONE', 'TZID:Europe/Paris', 'BEGIN:DAYLIGHT', 'BEGIN:STANDARD', 'METHOD:PUBLISH')
        ->and($ics)->not->toContain('confidentiel')
        ->and($ics)->not->toContain('Sujet du rendez-vous');

    $events = icalEvents($ics);
    expect($events)->toHaveCount(2);
    [$first, $second] = $events;
    expect($first)->toMatchArray([
        'UID' => $meeting->id,
        'SUMMARY' => 'Rendez-vous physique – Hôtel du Lac',
        'DTSTART;TZID=Europe/Paris' => '20261119T090000',
        'DURATION' => 'PT60M',
        'LOCATION' => 'Annecy',
        'URL' => url('/crm/opportunities/'.$opportunity->id),
    ])->and($first['DESCRIPTION'])->toContain('Fiche : Hôtel du Lac')->toContain('Opportunité : Formation HACCP')
        ->and($first['DTSTAMP'])->toMatch('/^\d{8}T\d{6}Z$/');

    // A call without a duration lasts 15 minutes; its result is in the description.
    $organisation = Organisation::query()->where('name', 'Hôtel du Lac')->first();
    expect($second)->toMatchArray([
        'UID' => $call->id,
        'SUMMARY' => 'Appel – Hôtel du Lac',
        'DTSTART;TZID=Europe/Paris' => '20261120T110000',
        'DURATION' => 'PT15M',
        'URL' => url('/crm/organisations/'.$organisation->id),
    ])->and($second['DESCRIPTION'])->toContain('Résultat : Réussi')
        ->and($second)->not->toHaveKey('LOCATION');
});

it('ical event across dst keeps local time', function () {
    $this->travelTo(icalAt('2026-10-20 10:00:00'));
    $user = commercial();
    // Summer time ends on Sunday 25/10/2026 at 3:00, starts again on 28/03/2027.
    icalAction($user, 'Rendez-vous physique', '2026-10-24 09:00:00', ['subject' => 'Été']);
    icalAction($user, 'Rendez-vous physique', '2026-10-26 09:00:00', ['subject' => 'Hiver', 'duration_minutes' => null]);
    icalAction($user, 'Prise de rendez-vous', '2027-03-29 09:00:00', ['subject' => 'Été suivant', 'duration_minutes' => 90]);
    icalAction($user, 'Appel', '2026-10-25 23:30:00', ['subject' => 'Tard', 'duration_minutes' => 45]);
    // Across the changes themselves: 01:30 + 2 h of exact time.
    icalAction($user, 'Rendez-vous physique', '2026-10-25 01:30:00', ['subject' => 'Recul', 'duration_minutes' => 120]);
    icalAction($user, 'Rendez-vous physique', '2027-03-28 01:30:00', ['subject' => 'Avance', 'duration_minutes' => 120]);

    $ics = $this->get(feedPath(CalendarToken::issue($user)))->assertOk()->getContent();
    $events = icalEvents($ics);

    expect($ics)->not->toContain('DTEND')
        ->and(array_map(fn (array $e) => [$e['DTSTART;TZID=Europe/Paris'], $e['DURATION']], $events))->toBe([
            ['20261024T090000', 'PT60M'],
            ['20261025T013000', 'PT120M'], // ends at 02:30 CET (3:30 on a summer clock)
            ['20261025T233000', 'PT45M'],
            ['20261026T090000', 'PT60M'], // meeting without duration: 1 hour
            ['20270328T013000', 'PT120M'], // ends at 04:30 CEST
            ['20270329T090000', 'PT90M'],
        ]);
});

it('only lists the dated meetings and calls of its owner from 30 days back to 180 days ahead', function () {
    $this->travelTo(icalAt('2026-11-18 10:00:00'));
    $user = commercial();
    icalAction($user, 'Rendez-vous physique', '2026-10-19 08:00:00', ['subject' => 'J-30']);
    icalAction($user, 'Rendez-vous physique', '2026-10-18 23:00:00', ['subject' => 'J-31']);
    icalAction($user, 'Appel', '2027-05-17 18:00:00', ['subject' => 'J+180']);
    icalAction($user, 'Appel', '2027-05-18 08:00:00', ['subject' => 'J+181']);
    icalAction($user, 'E-mail', '2026-11-19 10:00:00', ['subject' => 'E-mail']);
    icalAction($user, 'Appel', '2026-11-17 10:00:00', ['subject' => 'Sans échéance', 'due_at' => null, 'done_at' => icalAt('2026-11-17 10:00:00')]);
    icalAction(commercial(), 'Rendez-vous physique', '2026-11-19 10:00:00', ['subject' => 'D\'un collègue']);
    icalAction($user, 'Rendez-vous physique', '2026-11-19 11:00:00', ['subject' => 'Supprimé'])->delete();
    $gone = Organisation::query()->create(['name' => 'À la corbeille']);
    icalAction($user, 'Rendez-vous physique', '2026-11-19 12:00:00', ['subject' => 'Fiche supprimée', 'organisation_id' => $gone->id]);
    $gone->delete();
    $kept = icalAction($user, 'Rendez-vous physique', '2026-11-19 14:00:00', ['subject' => 'Gardé']);

    $events = icalEvents($this->get(feedPath(CalendarToken::issue($user)))->assertOk()->getContent());

    expect(array_column($events, 'DTSTART;TZID=Europe/Paris'))->toBe(['20261019T080000', '20261119T140000', '20270517T180000'])
        ->and($events[1]['UID'])->toBe($kept->id);
});

// The responsible always sees his own actions, even out of his DAS (« Confier à… », 0.7.1).
it('lists the own meetings of a responsable das, even out of his das', function () {
    $this->travelTo(icalAt('2026-11-18 10:00:00'));
    $mine = crmItem(ReferenceList::Das, 'Hôtellerie-restauration');
    $other = crmItem(ReferenceList::Das, 'Métiers supports');
    $visible = Organisation::query()->create(['name' => 'Visible']);
    $visible->das()->sync([$mine->id => ['tenant_id' => tenant()->id]]);
    $hidden = Organisation::query()->create(['name' => 'Cachée']);
    $hidden->das()->sync([$other->id => ['tenant_id' => tenant()->id]]);
    $responsable = userWithRole(Role::ResponsableDas);
    followDas($responsable, $mine);
    icalAction($responsable, 'Rendez-vous physique', '2026-11-19 09:00:00', ['organisation_id' => $visible->id]);
    icalAction($responsable, 'Rendez-vous physique', '2026-11-19 10:00:00', ['organisation_id' => $hidden->id]);

    $events = icalEvents($this->get(feedPath(CalendarToken::issue($responsable)))->assertOk()->getContent());

    expect(array_column($events, 'SUMMARY'))->toBe(['Rendez-vous physique – Visible', 'Rendez-vous physique – Cachée']);
});

it('escapes special characters and line breaks (RFC 5545)', function () {
    $this->travelTo(icalAt('2026-11-18 10:00:00'));
    $user = commercial();
    $organisation = Organisation::query()->create(['name' => 'Dupont, Fils; & Cie \\ Lyon']);
    icalAction($user, 'Rendez-vous physique', '2026-11-19 09:00:00', ['organisation_id' => $organisation->id, 'location' => "Salle A; bât. B, 2e\r\nporte\ngauche"]);

    $event = icalEvents($this->get(feedPath(CalendarToken::issue($user)))->getContent())[0];

    expect($event['SUMMARY'])->toBe('Rendez-vous physique – Dupont\, Fils\; & Cie \\\\ Lyon')
        ->and($event['LOCATION'])->toBe('Salle A\; bât. B\, 2e\nporte\ngauche')
        ->and($event['DESCRIPTION'])->toBe('Fiche : Dupont\, Fils\; & Cie \\\\ Lyon');
});

it('folds lines at 75 octets without splitting a character', function () {
    $this->travelTo(icalAt('2026-11-18 10:00:00'));
    $user = commercial();
    $location = str_repeat('Château éé ', 20);
    icalAction($user, 'Rendez-vous physique', '2026-11-19 09:00:00', ['location' => $location]);

    $ics = $this->get(feedPath(CalendarToken::issue($user)))->getContent();

    foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75)
            ->and(mb_check_encoding($line, 'UTF-8'))->toBeTrue();
    }
    expect($ics)->toContain("\r\n ")
        ->and(icalEvents($ics)[0]['LOCATION'])->toBe($location);

    // The folding helper on its own: 75 octets per line, continuation lines start with a space.
    $folded = IcalFeed::fold('DESCRIPTION:'.str_repeat('é', 100));
    expect(strlen(explode("\r\n", $folded)[0]))->toBe(74) // 12 + 31 × 2: one more « é » would cross 75
        ->and(str_replace("\r\n ", '', $folded))->toBe('DESCRIPTION:'.str_repeat('é', 100));
});

it('answers 404 to an unknown, malformed or revoked token and to a deactivated user', function () {
    $user = commercial();
    $old = CalendarToken::issue($user);
    $new = CalendarToken::issue($user); // regenerating revokes the previous link

    $this->get(feedPath($old))->assertNotFound();
    $this->get(feedPath($new))->assertOk();
    $this->get(feedPath(str_repeat('a', 64)))->assertNotFound();
    $this->get(feedPath('abc'))->assertNotFound();
    $this->get('/calendar/'.$new)->assertNotFound();
    expect(CalendarToken::query()->where('user_id', $user->id)->whereNull('revoked_at')->count())->toBe(1);

    CalendarToken::revoke($user);
    $this->get(feedPath($new))->assertNotFound();

    $other = commercial();
    $token = CalendarToken::issue($other);
    $other->deactivate();
    $this->get(feedPath($token))->assertNotFound();
});

it('never serves the token of another organisme', function () {
    $foreign = tenantOnHost('autre.test');
    $token = app(TenantContext::class)->run($foreign, fn () => CalendarToken::issue(User::factory()->withRole(Role::Commercial)->create()));

    // Single mode: the request belongs to the default organisme.
    $this->get(feedPath($token))->assertNotFound();

    config(['tms.mode' => 'multi']);
    tenantOnHost('cap.test');
    $this->get('http://cap.test'.feedPath($token))->assertNotFound();
    $this->get('http://autre.test'.feedPath($token))->assertOk();
});

it('stores only the hash and records the use at most once a minute', function () {
    $this->travelTo(icalAt('2026-11-18 10:00:00'));
    $user = commercial();
    $token = CalendarToken::issue($user);
    $row = CalendarToken::query()->sole();
    expect($row->token_hash)->toBe(hash('sha256', $token))->and($row->last_used_at)->toBeNull()
        ->and(strlen($token))->toBe(64);

    $this->get(feedPath($token))->assertOk();
    $first = $row->fresh()->last_used_at;
    expect($first)->not->toBeNull();

    $this->travel(30)->seconds();
    $this->get(feedPath($token))->assertOk();
    expect($row->fresh()->last_used_at->equalTo($first))->toBeTrue();

    $this->travel(31)->seconds();
    $this->get(feedPath($token))->assertOk();
    expect($row->fresh()->last_used_at->greaterThan($first))->toBeTrue();
});

it('limits the feed to 30 requests a minute', function () {
    $token = CalendarToken::issue(commercial());

    for ($i = 0; $i < 30; $i++) {
        $this->get(feedPath($token))->assertOk();
    }
    $this->get(feedPath($token))->assertStatus(429);
});

it('limits the feed per address too, without starving a shared calendar server', function () {
    $token = CalendarToken::issue(commercial());

    // Links rotated by one client: each one is new, the address is not.
    for ($i = 0; $i < 40; $i++) {
        $this->get(feedPath(bin2hex(random_bytes(32))))->assertNotFound();
    }
    // A server fetching many links from the same address still gets the valid one.
    $this->get(feedPath($token))->assertOk();

    for ($i = 41; $i < RateLimitServiceProvider::FEED_PER_IP; $i++) {
        $this->get(feedPath(bin2hex(random_bytes(32))))->assertNotFound();
    }
    $this->get(feedPath(bin2hex(random_bytes(32))))->assertStatus(429);
    $this->get(feedPath($token))->assertStatus(429);

    // Another address is not affected.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get(feedPath($token))->assertOk();
});

it('lets a user generate, regenerate and revoke his link from « Mon agenda »', function () {
    $user = commercial();
    $this->actingAs($user);

    $this->get('/settings/calendar')->assertOk()
        ->assertInertia(fn ($p) => $p->component('settings/calendar')->where('calendar', null)->where('url', null));

    $this->post('/settings/calendar')->assertRedirect('/settings/calendar');
    $shown = $this->get('/settings/calendar')->assertOk()->viewData('page');
    $page = $shown['props'];
    // Encrypted history state, its key rotated on the next visit.
    expect($shown['encryptHistory'])->toBeTrue();
    expect($page['url'])->toMatch('#^'.preg_quote(url('/calendar'), '#').'/[0-9a-f]{64}\.ics$#')
        ->and($page['calendar']['created_at'])->not->toBeNull();
    $first = $page['url'];
    $this->get(parse_url($first, PHP_URL_PATH))->assertOk();

    // Shown once only.
    $next = $this->get('/settings/calendar')->assertInertia(fn ($p) => $p->where('url', null)->whereNot('calendar', null))->viewData('page');
    expect($next['clearHistory'])->toBeTrue();

    $this->post('/settings/calendar')->assertRedirect('/settings/calendar');
    $second = $this->get('/settings/calendar')->viewData('page')['props']['url'];
    expect($second)->not->toBe($first);
    $this->get(parse_url($first, PHP_URL_PATH))->assertNotFound();
    $this->get(parse_url($second, PHP_URL_PATH))->assertOk();

    $this->delete('/settings/calendar')->assertRedirect('/settings/calendar');
    $this->get(parse_url($second, PHP_URL_PATH))->assertNotFound();
    $this->get('/settings/calendar')->assertInertia(fn ($p) => $p->where('calendar', null));

    $events = AuditLog::query()->where('user_id', $user->id)->where('event', 'like', 'calendar.%')->orderBy('created_at')->orderBy('id')->get();
    expect($events->pluck('event')->all())->toBe(['calendar.token_created', 'calendar.token_created', 'calendar.token_revoked']);
    foreach ($events as $log) {
        $json = json_encode([$log->old_values, $log->new_values]);
        foreach ([$first, $second] as $url) {
            expect($json)->not->toContain(basename($url, '.ics'))
                ->and($json)->not->toContain(hash('sha256', basename($url, '.ics')));
        }
    }
});

it('keeps « Mon agenda » and the feed to the users of the CRM', function () {
    $this->get('/settings/calendar')->assertRedirect('/login');

    $user = commercial();
    $token = CalendarToken::issue($user);
    $user->syncRoles([]); // no crm.activities.view any more

    $this->get(feedPath($token))->assertNotFound();
    $this->actingAs($user->fresh())->get('/settings/calendar')->assertForbidden();
    $this->post('/settings/calendar')->assertForbidden();
});
