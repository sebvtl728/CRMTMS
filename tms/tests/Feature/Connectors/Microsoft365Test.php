<?php

use App\Modules\Connectors\Calendar\CalendarLink;
use App\Modules\Connectors\Calendar\CalendarLinkEvent;
use App\Modules\Connectors\Calendar\GraphClient;
use App\Modules\Connectors\Calendar\Microsoft365Connector;
use App\Modules\Connectors\Calendar\PushActivityToOutlook;
use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorKind;
use App\Modules\Connectors\Core\ConnectorRegistry;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Core\HostPolicy;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Tenancy\TenantDomain;
use App\Modules\Core\Users\User;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Organisations\Organisation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const M365_TENANT = '72f988bf-86f1-41af-91ab-2d7cd011db47';
const M365_CLIENT = '6731de76-14a6-49ae-97bc-6eba6914391e';
const GRAPH_EVENTS = 'https://graph.microsoft.com/v1.0/me/events';
const M365_TOKEN_URL = 'https://login.microsoftonline.com/'.M365_TENANT.'/oauth2/v2.0/token';

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    HostPolicy::resolveUsing(fn (string $host): array => ['203.0.113.20']);
    m365Http();
});
afterEach(fn () => HostPolicy::resolveUsing(null));

/** A fresh fake (Http::fake() alone keeps the first matching stub), no stray request. */
function m365Http(array $stubs = []): void
{
    Http::swap((new Factory)->preventStrayRequests());
    Http::fake($stubs);
}

/** A test secret generated per run: never a value written in the repository. */
function m365Config(bool $enabled = true): ConnectorConfig
{
    $config = ConnectorConfig::query()->firstOrNew(['connector_key' => 'microsoft365', 'company_id' => null]);
    $config->fill([
        'enabled' => $enabled,
        'settings' => ['tenant' => M365_TENANT, 'client_id' => M365_CLIENT],
        'secrets' => ['client_secret' => 'test-'.Str::random(32)],
    ])->save();

    return $config;
}

function m365Link(User $user, array $attributes = []): CalendarLink
{
    $link = new CalendarLink(['user_id' => $user->id]);
    $link->forceFill([
        'status' => CalendarLink::ACTIVE,
        'access_token' => 'access-'.Str::random(20),
        'refresh_token' => 'refresh-'.Str::random(20),
        'expires_at' => now()->addHour(),
        'connected_at' => now(),
        ...$attributes,
    ])->save();

    return $link;
}

function m365At(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Europe/Paris');
}

/**
 * @param  array<string, mixed>  $attributes
 */
function m365Meeting(User $responsible, array $attributes = []): Activity
{
    return Activity::query()->create([
        'type_id' => crmItem(ReferenceList::ActionType, 'Rendez-vous physique')->id,
        'subject' => 'Sujet confidentiel',
        'report' => 'Compte rendu confidentiel',
        'due_at' => m365At('2026-11-19 09:00:00'),
        'duration_minutes' => 60,
        'location' => 'Annecy',
        'organisation_id' => Organisation::query()->firstOrCreate(['name' => 'Hôtel du Lac'])->id,
        'responsible_user_id' => $responsible->id,
        ...$attributes,
    ]);
}

/** @return list<Request> */
function graphRequests(): array
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://graph.microsoft.com/'))->map(fn (array $pair) => $pair[0])->values()->all();
}

function graphCreated(string $id = 'AAMkEvent1'): array
{
    return [GRAPH_EVENTS => Http::response(['id' => $id], 201)];
}

it('registers the microsoft365 sync connector with its settings and secret', function () {
    $connector = app(ConnectorRegistry::class)->make('microsoft365');

    expect($connector)->toBeInstanceOf(Microsoft365Connector::class)
        ->and($connector::kind())->toBe(ConnectorKind::Sync)
        ->and(array_keys($connector->settingsRules()))->toBe(['tenant', 'client_id'])
        ->and($connector->secretKeys())->toBe(['client_secret']);

    $validator = validator(['tenant' => 'https://evil.example/x', 'client_id' => 'pas-un-guid'], $connector->settingsRules());
    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->keys())->toContain('tenant', 'client_id');
    expect(validator(['tenant' => 'common', 'client_id' => M365_CLIENT], $connector->settingsRules())->passes())->toBeTrue()
        ->and(validator(['tenant' => 'organisme.onmicrosoft.com', 'client_id' => M365_CLIENT], $connector->settingsRules())->passes())->toBeTrue();
});

it('tests the azure application with the client credentials, without relaying microsoft text', function () {
    $config = m365Config();
    m365Http([M365_TOKEN_URL => Http::sequence()
        ->push(['access_token' => 'x', 'expires_in' => 3599], 200)
        ->push(['error' => 'invalid_client', 'error_description' => 'AADSTS7000215: Invalid client secret '.$config->secret('client_secret')], 401)]);

    $ok = app(Microsoft365Connector::class)->test($config);
    $ko = app(Microsoft365Connector::class)->test($config);

    expect($ok->ok)->toBeTrue()
        ->and($ko->ok)->toBeFalse()
        ->and($ko->message)->not->toContain('AADSTS')
        ->and($ko->message)->not->toContain((string) $config->secret('client_secret'));
});

it('connects outlook with an authorization code and pkce, state signed and single use', function () {
    $config = m365Config();
    $user = commercial();

    $response = $this->actingAs($user)->post('/settings/calendar/outlook/connect');
    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');
    $parts = parse_url($location);
    parse_str($parts['query'] ?? '', $query);

    expect($parts['scheme'].'://'.$parts['host'].$parts['path'])->toBe('https://login.microsoftonline.com/'.M365_TENANT.'/oauth2/v2.0/authorize')
        ->and($query['client_id'])->toBe(M365_CLIENT)
        ->and($query['response_type'])->toBe('code')
        ->and($query['redirect_uri'])->toBe(url('/settings/calendar/outlook/callback'))
        ->and($query['scope'])->toContain('offline_access')->toContain('Calendars.ReadWrite')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['state'])->toBeString()
        ->and($query)->not->toHaveKey('client_secret');

    m365Http([M365_TOKEN_URL => Http::response([
        'token_type' => 'Bearer', 'scope' => 'https://graph.microsoft.com/Calendars.ReadWrite', 'expires_in' => 3599,
        'access_token' => 'jeton-acces-oauth', 'refresh_token' => 'jeton-rafraichissement-oauth',
    ])]);

    $this->actingAs($user)->get('/settings/calendar/outlook/callback?code=code-123&state='.urlencode($query['state']))
        ->assertRedirect('/settings/calendar')
        ->assertSessionHas('status');

    Http::assertSent(function (Request $r) use ($query, $config) {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', (string) $r['code_verifier'], true)), '+/', '-_'), '=');

        return $r->url() === M365_TOKEN_URL && $r['grant_type'] === 'authorization_code' && $r['code'] === 'code-123'
            && $r['redirect_uri'] === url('/settings/calendar/outlook/callback') && $r['client_secret'] === $config->secret('client_secret')
            && $challenge === $query['code_challenge'];
    });

    $link = CalendarLink::query()->where('user_id', $user->id)->sole();
    expect($link->status)->toBe(CalendarLink::ACTIVE)
        ->and($link->access_token)->toBe('jeton-acces-oauth')
        ->and($link->refresh_token)->toBe('jeton-rafraichissement-oauth');
    // Encrypted at rest.
    $raw = DB::table('calendar_links')->where('id', $link->id)->first();
    expect((string) $raw->access_token)->not->toContain('jeton-acces-oauth')
        ->and((string) $raw->refresh_token)->not->toContain('jeton-rafraichissement-oauth');
    // Journaled without any token.
    $audit = AuditLog::query()->where('event', 'calendar.outlook_connected')->sole();
    expect(json_encode($audit->getAttributes()))->not->toContain('jeton');

    // Single use: the same state is refused, nothing is asked to Microsoft.
    m365Http();
    $this->actingAs($user)->get('/settings/calendar/outlook/callback?code=code-456&state='.urlencode($query['state']))
        ->assertRedirect('/settings/calendar')->assertSessionHas('outlook_error');
    Http::assertNothingSent();

    $this->actingAs($user)->get('/settings/calendar')->assertInertia(fn ($page) => $page
        ->where('outlook.available', true)->where('outlook.status', 'active'));
});

it('refuses an invalid, expired or foreign state', function () {
    m365Config();
    $user = commercial();
    $other = commercial();
    $stateOf = function (User $who): string {
        $location = (string) $this->actingAs($who)->post('/settings/calendar/outlook/connect')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query['state'];
    };

    // Tampered.
    $state = $stateOf($user);
    [$payload, $signature] = explode('.', $state);
    $this->actingAs($user)->get('/settings/calendar/outlook/callback?code=c&state='.urlencode($payload.'.'.strrev($signature)))
        ->assertRedirect('/settings/calendar')->assertSessionHas('outlook_error');

    // Missing.
    $stateOf($user);
    $this->actingAs($user)->get('/settings/calendar/outlook/callback?code=c')->assertSessionHas('outlook_error');

    // Expired (10 minutes).
    $state = $stateOf($user);
    $this->travel(11)->minutes();
    $this->actingAs($user)->get('/settings/calendar/outlook/callback?code=c&state='.urlencode($state))->assertSessionHas('outlook_error');

    // Issued to another user.
    $state = $stateOf($other);
    $this->actingAs($user)->get('/settings/calendar/outlook/callback?code=c&state='.urlencode($state))->assertSessionHas('outlook_error');

    // Cancelled on Microsoft's side.
    $state = $stateOf($user);
    $this->actingAs($user)->get('/settings/calendar/outlook/callback?error=access_denied&error_description=AADSTS65004&state='.urlencode($state))
        ->assertSessionHas('outlook_error', 'Connexion à Outlook annulée.');

    Http::assertNothingSent();
    expect(CalendarLink::query()->count())->toBe(0);
});

it('refuses to connect when the connector is not configured or disabled', function () {
    $user = commercial();

    $this->actingAs($user)->post('/settings/calendar/outlook/connect')
        ->assertRedirect('/settings/calendar')->assertSessionHas('outlook_error');

    m365Config(enabled: false);
    $this->actingAs($user)->post('/settings/calendar/outlook/connect')->assertSessionHas('outlook_error');
    $this->actingAs($user)->get('/settings/calendar')->assertInertia(fn ($page) => $page->where('outlook.available', false));
});

it('creates, updates and deletes the outlook event of a meeting, never with its report', function () {
    $this->travelTo(m365At('2026-11-18 10:00:00'));
    m365Config();
    $user = commercial();
    $link = m365Link($user);
    $opportunity = makeOpportunity(Organisation::query()->firstOrCreate(['name' => 'Hôtel du Lac']), ['title' => 'Formation HACCP']);
    m365Http(graphCreated());

    $meeting = m365Meeting($user, ['opportunity_id' => $opportunity->id]);

    [$post] = graphRequests();
    $body = $post->data();
    expect($post->method())->toBe('POST')
        ->and($post->url())->toBe(GRAPH_EVENTS)
        ->and($post->header('Authorization')[0])->toBe('Bearer '.$link->access_token)
        ->and($post->header('Prefer')[0])->toBe('outlook.timezone="Europe/Paris"')
        ->and($body['subject'])->toBe('Rendez-vous physique – Hôtel du Lac')
        ->and($body['start'])->toBe(['dateTime' => '2026-11-19T09:00:00', 'timeZone' => 'Europe/Paris'])
        ->and($body['end'])->toBe(['dateTime' => '2026-11-19T10:00:00', 'timeZone' => 'Europe/Paris'])
        ->and($body['location'])->toBe(['displayName' => 'Annecy'])
        ->and($body['body']['content'])->toContain('Formation HACCP')->toContain(url('/crm/opportunities/'.$opportunity->id))
        ->and($body['transactionId'])->toBeString();
    expect(json_encode($body))->not->toContain('confidentiel');
    expect(CalendarLinkEvent::query()->sole()->outlook_event_id)->toBe('AAMkEvent1');

    // Modified: PATCH of the same event.
    m365Http([GRAPH_EVENTS.'/AAMkEvent1' => Http::response(['id' => 'AAMkEvent1'])]);
    $meeting->update(['due_at' => m365At('2026-11-20 14:30:00'), 'duration_minutes' => 90, 'report' => 'Autre compte rendu confidentiel']);
    [$patch] = graphRequests();
    expect($patch->method())->toBe('PATCH')
        ->and($patch->data()['start']['dateTime'])->toBe('2026-11-20T14:30:00')
        ->and($patch->data()['end']['dateTime'])->toBe('2026-11-20T16:00:00')
        ->and(json_encode($patch->data()))->not->toContain('confidentiel');

    // Deleted: DELETE, nothing remembered.
    m365Http([GRAPH_EVENTS.'/AAMkEvent1' => Http::response(null, 204)]);
    $meeting->delete();
    [$delete] = graphRequests();
    expect($delete->method())->toBe('DELETE')->and($delete->url())->toBe(GRAPH_EVENTS.'/AAMkEvent1')
        ->and(CalendarLinkEvent::query()->count())->toBe(0);

    // Journal: what happened, no personal data.
    $runs = ConnectorRun::query()->get();
    expect($runs)->toHaveCount(3)->and($runs->every(fn (ConnectorRun $r) => $r->status === 'success'))->toBeTrue();
    expect($runs->toJson())->not->toContain('Hôtel du Lac')->not->toContain('Annecy')->not->toContain('HACCP')
        ->not->toContain('confidentiel')->not->toContain($user->email);
});

it('keeps dst-correct local times and default durations', function () {
    $this->travelTo(m365At('2026-10-20 10:00:00'));
    m365Config();
    $user = commercial();
    m365Link($user);
    m365Http(graphCreated());

    // 25/10/2026 01:30 CEST + 120 min of exact time = 02:30 CET.
    m365Meeting($user, ['due_at' => m365At('2026-10-25 01:30:00'), 'duration_minutes' => 120]);
    // A call without a duration lasts 15 minutes.
    m365Meeting($user, ['type_id' => crmItem(ReferenceList::ActionType, 'Appel')->id, 'due_at' => m365At('2026-11-02 11:00:00'), 'duration_minutes' => null, 'location' => null]);

    [$first, $second] = graphRequests();
    expect($first->data()['start']['dateTime'])->toBe('2026-10-25T01:30:00')
        ->and($first->data()['end']['dateTime'])->toBe('2026-10-25T02:30:00')
        ->and($second->data()['subject'])->toBe('Appel – Hôtel du Lac')
        ->and($second->data()['end']['dateTime'])->toBe('2026-11-02T11:15:00')
        ->and($second->data())->not->toHaveKey('location');
});

it('sends nothing for other action types, undated actions, a user without link or a disabled connector', function () {
    m365Config();
    $user = commercial();
    m365Link($user);
    $unlinked = commercial();

    m365Meeting($user, ['type_id' => crmItem(ReferenceList::ActionType, 'E-mail')->id]);
    m365Meeting($user, ['due_at' => null, 'done_at' => m365At('2026-11-10 09:00:00')]);
    m365Meeting($unlinked);
    m365Config(enabled: false);
    m365Meeting($user);

    expect(graphRequests())->toBe([])
        ->and(ConnectorRun::query()->count())->toBe(0);
});

it('deletes the event when the action no longer qualifies', function () {
    m365Config();
    $user = commercial();
    m365Link($user);
    m365Http(graphCreated());
    $meeting = m365Meeting($user);

    m365Http([GRAPH_EVENTS.'/AAMkEvent1' => Http::response(null, 204)]);
    $meeting->update(['type_id' => crmItem(ReferenceList::ActionType, 'Note')->id]);

    [$delete] = graphRequests();
    expect($delete->method())->toBe('DELETE')->and(CalendarLinkEvent::query()->count())->toBe(0);
});

it('moves the event to the new responsible calendar on reassignment', function () {
    m365Config();
    $alice = commercial();
    $bob = commercial();
    $aliceLink = m365Link($alice);
    $bobLink = m365Link($bob);
    m365Http(graphCreated('AAMkAlice'));
    $meeting = m365Meeting($alice);

    m365Http([
        GRAPH_EVENTS.'/AAMkAlice' => Http::response(null, 204),
        GRAPH_EVENTS => Http::response(['id' => 'AAMkBob'], 201),
    ]);
    $meeting->update(['responsible_user_id' => $bob->id]);

    [$delete, $post] = graphRequests();
    expect($delete->method())->toBe('DELETE')->and($delete->header('Authorization')[0])->toBe('Bearer '.$aliceLink->access_token)
        ->and($post->method())->toBe('POST')->and($post->header('Authorization')[0])->toBe('Bearer '.$bobLink->access_token);
    $row = CalendarLinkEvent::query()->sole();
    expect($row->calendar_link_id)->toBe($bobLink->id)->and($row->outlook_event_id)->toBe('AAMkBob');
});

it('refreshes the access token on a 401, then retries once', function () {
    $config = m365Config();
    $user = commercial();
    $link = m365Link($user, ['access_token' => 'jeton-perime']);
    m365Http([
        M365_TOKEN_URL => Http::response(['access_token' => 'jeton-neuf', 'refresh_token' => 'rafraichissement-neuf', 'expires_in' => 3599]),
        GRAPH_EVENTS => Http::sequence()->push(['error' => ['code' => 'InvalidAuthenticationToken']], 401)->push(['id' => 'AAMkEvent1'], 201),
    ]);

    m365Meeting($user);

    [$first, $second] = graphRequests();
    expect($first->header('Authorization')[0])->toBe('Bearer jeton-perime')
        ->and($second->header('Authorization')[0])->toBe('Bearer jeton-neuf');
    Http::assertSent(fn (Request $r) => $r->url() === M365_TOKEN_URL && $r['grant_type'] === 'refresh_token'
        && str_starts_with((string) $r['refresh_token'], 'refresh-') && $r['client_secret'] === $config->secret('client_secret'));
    $link->refresh();
    expect($link->access_token)->toBe('jeton-neuf')->and($link->refresh_token)->toBe('rafraichissement-neuf')
        ->and(CalendarLinkEvent::query()->sole()->outlook_event_id)->toBe('AAMkEvent1');
});

it('marks the link « À reconnecter » when microsoft refuses the refresh', function () {
    m365Config();
    $user = commercial();
    $link = m365Link($user, ['expires_at' => now()->subMinute()]);
    m365Http([M365_TOKEN_URL => Http::response(['error' => 'invalid_grant', 'error_description' => 'AADSTS70008: '.$user->email], 400)]);

    m365Meeting($user);

    expect(graphRequests())->toBe([]);
    $link->refresh();
    expect($link->status)->toBe(CalendarLink::RECONNECT)->and($link->access_token)->toBeNull()->and($link->refresh_token)->toBeNull();
    $run = ConnectorRun::query()->sole();
    expect($run->status)->toBe('failed')
        ->and((string) $run->error)->toContain('reconnecter')
        ->and((string) $run->error)->not->toContain('AADSTS')->not->toContain($user->email);

    $this->actingAs($user)->get('/settings/calendar')->assertInertia(fn ($page) => $page
        ->where('outlook.status', 'reconnect')
        ->where('outlook.message', fn (string $m) => str_contains($m, 'reconnecter')));

    // No more pushes until reconnected.
    m365Meeting($user, ['due_at' => m365At('2026-12-01 09:00:00')]);
    expect(graphRequests())->toBe([])->and(ConnectorRun::query()->count())->toBe(1);
});

it('does not duplicate the event when the job is replayed', function () {
    m365Config();
    $user = commercial();
    m365Link($user);
    m365Http(graphCreated());
    $meeting = m365Meeting($user);

    m365Http([GRAPH_EVENTS.'/AAMkEvent1' => Http::response(['id' => 'AAMkEvent1'])]);
    PushActivityToOutlook::dispatchSync($meeting->id, url('/'));
    PushActivityToOutlook::dispatchSync($meeting->id, url('/'));

    expect(collect(graphRequests())->map->method()->all())->toBe(['PATCH', 'PATCH'])
        ->and(CalendarLinkEvent::query()->count())->toBe(1);

    // Crash between Graph's answer and the save: the replay reuses the same transactionId.
    $row = CalendarLinkEvent::query()->sole();
    $transaction = $row->transaction_id;
    $row->forceFill(['outlook_event_id' => null])->save();
    m365Http(graphCreated());
    PushActivityToOutlook::dispatchSync($meeting->id, url('/'));
    [$post] = graphRequests();
    expect($post->data()['transactionId'])->toBe($transaction);
});

it('recreates an event deleted in outlook and forgets one already gone', function () {
    m365Config();
    $user = commercial();
    m365Link($user);
    m365Http(graphCreated());
    $meeting = m365Meeting($user);

    m365Http([
        GRAPH_EVENTS.'/AAMkEvent1' => Http::response(['error' => ['code' => 'ErrorItemNotFound']], 404),
        GRAPH_EVENTS => Http::response(['id' => 'AAMkEvent2'], 201),
    ]);
    $meeting->update(['location' => 'Chambéry']);
    expect(collect(graphRequests())->map->method()->all())->toBe(['PATCH', 'POST'])
        ->and(CalendarLinkEvent::query()->sole()->outlook_event_id)->toBe('AAMkEvent2');

    m365Http([GRAPH_EVENTS.'/AAMkEvent2' => Http::response(['error' => ['code' => 'ErrorItemNotFound']], 404)]);
    $meeting->delete();
    expect(CalendarLinkEvent::query()->count())->toBe(0)
        ->and(ConnectorRun::query()->latest('started_at')->latest('id')->first()->status)->toBe('success');
});

it('retries later on throttling, honoring Retry-After', function () {
    m365Config();
    $user = commercial();
    $meeting = m365Meeting($user); // before the link: nothing queued
    m365Link($user);
    m365Http([GRAPH_EVENTS => Http::response(['error' => ['code' => 'TooManyRequests']], 429, ['Retry-After' => '120'])]);

    $job = (new PushActivityToOutlook($meeting->id, url('/')))->withFakeQueueInteractions();
    $job->handle(app(GraphClient::class));
    $job->assertReleased(delay: 120);

    m365Http([GRAPH_EVENTS => Http::response(null, 503)]);
    $job = (new PushActivityToOutlook($meeting->id, url('/')))->withFakeQueueInteractions();
    $job->handle(app(GraphClient::class));
    $job->assertReleased();

    expect(CalendarLinkEvent::query()->whereNotNull('outlook_event_id')->count())->toBe(0)
        ->and(ConnectorRun::query()->where('status', 'failed')->count())->toBe(2);
});

it('never calls a host the policy refuses', function () {
    m365Config();
    $user = commercial();
    m365Link($user);
    HostPolicy::resolveUsing(fn (string $host): array => ['127.0.0.1']);

    m365Meeting($user);

    expect(graphRequests())->toBe([])
        ->and(ConnectorRun::query()->sole()->status)->toBe('failed');
});

it('connects to the addresses it checked, never resolving the names again (dns rebinding)', function () {
    $config = m365Config();
    $user = commercial();
    m365Link($user, ['expires_at' => now()->subMinute()]);
    HostPolicy::resolveUsing(fn (string $host): array => $host === 'graph.microsoft.com' ? ['203.0.113.21', '2001:db8::21'] : ['203.0.113.20']);
    $pinned = [];
    m365Http();
    Http::fake(function (Request $request, array $options) use (&$pinned) {
        $pinned[parse_url($request->url(), PHP_URL_HOST)] = $options['curl'][CURLOPT_RESOLVE] ?? null;

        return str_contains($request->url(), '/token')
            ? Http::response(['access_token' => 'new-'.Str::random(8), 'refresh_token' => 'r-'.Str::random(8), 'expires_in' => 3599])
            : Http::response(['id' => 'AAMkEvent1'], 201);
    });

    m365Meeting($user);

    expect($pinned)->toBe([
        'login.microsoftonline.com' => ['login.microsoftonline.com:443:203.0.113.20'],
        'graph.microsoft.com' => ['graph.microsoft.com:443:203.0.113.21,[2001:db8::21]'],
    ]);
});

it('disconnects outlook: events removed from the calendar, tokens deleted, pushes stopped', function () {
    $this->travelTo(m365At('2026-11-18 10:00:00'));
    m365Config();
    $user = commercial();
    $link = m365Link($user);
    m365Http(graphCreated());
    m365Meeting($user);

    m365Http([GRAPH_EVENTS.'/AAMkEvent1' => Http::response(null, 204)]);
    $this->actingAs($user)->delete('/settings/calendar/outlook')->assertRedirect('/settings/calendar')->assertSessionHas('status');

    [$delete] = graphRequests();
    expect($delete->method())->toBe('DELETE')->and($delete->url())->toBe(GRAPH_EVENTS.'/AAMkEvent1')
        ->and(CalendarLinkEvent::query()->count())->toBe(0);
    $link->refresh();
    expect($link->status)->toBe(CalendarLink::DISCONNECTED)
        ->and($link->access_token)->toBeNull()->and($link->refresh_token)->toBeNull()
        ->and(AuditLog::query()->where('event', 'calendar.outlook_disconnected')->count())->toBe(1);
    $this->actingAs($user)->get('/settings/calendar')->assertInertia(fn ($page) => $page->where('outlook.status', 'none'));

    m365Http();
    m365Meeting($user, ['due_at' => m365At('2026-11-21 09:00:00')]);
    expect(graphRequests())->toBe([]);
});

/** Connects $user through the OAuth flow (authorization code + PKCE). */
function m365Reconnect(TestCase $test, User $user, array $graph = []): void
{
    $location = (string) $test->actingAs($user)->post('/settings/calendar/outlook/connect')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    m365Http([M365_TOKEN_URL => Http::response([
        'token_type' => 'Bearer', 'scope' => 'https://graph.microsoft.com/Calendars.ReadWrite', 'expires_in' => 3599,
        'access_token' => 'access-'.Str::random(20), 'refresh_token' => 'refresh-'.Str::random(20),
    ]), ...$graph]);
    $test->actingAs($user)->get('/settings/calendar/outlook/callback?code=code-123&state='.urlencode($query['state']))
        ->assertRedirect('/settings/calendar')->assertSessionHas('status');
}

it('never duplicates an event when outlook is disconnected then connected again', function () {
    $this->travelTo(m365At('2026-11-18 10:00:00'));
    m365Config();
    $user = commercial();
    $link = m365Link($user);
    m365Http([GRAPH_EVENTS => Http::sequence()->push(['id' => 'AAMkEvent1'], 201)->push(['id' => 'AAMkEvent2'], 201)]);
    $kept = m365Meeting($user);
    m365Meeting($user, ['due_at' => m365At('2026-11-20 09:00:00')]);

    // Microsoft refuses one deletion: its copy is remembered, the other one is gone.
    m365Http([GRAPH_EVENTS.'/AAMkEvent1' => Http::response(null, 403), GRAPH_EVENTS.'/AAMkEvent2' => Http::response(null, 204)]);
    $this->actingAs($user)->delete('/settings/calendar/outlook');
    expect(CalendarLinkEvent::query()->sole()->activity_id)->toBe($kept->id)
        ->and($link->refresh()->refresh_token)->toBeNull();

    m365Reconnect($this, $user, [
        GRAPH_EVENTS.'/AAMkEvent1' => Http::response(['id' => 'AAMkEvent1']),
        GRAPH_EVENTS => Http::response(['id' => 'AAMkEvent3'], 201),
    ]);

    $requests = collect(graphRequests());
    expect($requests->filter(fn (Request $r) => $r->method() === 'PATCH')->map(fn (Request $r) => $r->url())->values()->all())->toBe([GRAPH_EVENTS.'/AAMkEvent1'])
        ->and($requests->filter(fn (Request $r) => $r->method() === 'POST')->count())->toBe(1)
        ->and(CalendarLink::query()->where('user_id', $user->id)->sole()->status)->toBe(CalendarLink::ACTIVE)
        ->and(CalendarLinkEvent::query()->orderBy('outlook_event_id')->pluck('outlook_event_id')->all())->toBe(['AAMkEvent1', 'AAMkEvent3']);
});

it('updates the outlook event when the person is anonymised', function () {
    m365Config();
    $user = commercial();
    m365Link($user);
    $person = makePerson('Dupont', ['first_name' => 'Jean']);
    m365Http(graphCreated());
    m365Meeting($user, ['person_id' => $person->id, 'organisation_id' => null]);
    [$post] = graphRequests();
    expect($post->data()['subject'])->toContain('Dupont');

    m365Http([GRAPH_EVENTS.'/AAMkEvent1' => Http::response(['id' => 'AAMkEvent1'])]);
    app(Anonymiser::class)->anonymise($person->fresh(), 'request');

    [$patch] = graphRequests();
    expect($patch->method())->toBe('PATCH')
        ->and(json_encode($patch->data()))->not->toContain('Dupont')->not->toContain('Annecy')
        ->and($patch->data()['location'])->toBe(['displayName' => '']);
});

it('deletes the outlook event when the fiche goes to the trash and recreates it on restore', function () {
    $this->travelTo(m365At('2026-11-18 10:00:00'));
    m365Config();
    $user = commercial();
    m365Link($user);
    m365Http(graphCreated());
    $meeting = m365Meeting($user);
    $organisation = Organisation::query()->findOrFail($meeting->organisation_id);

    m365Http([GRAPH_EVENTS.'/AAMkEvent1' => Http::response(null, 204)]);
    DB::transaction(fn () => $organisation->delete());
    [$delete] = graphRequests();
    expect($delete->method())->toBe('DELETE')->and($delete->url())->toBe(GRAPH_EVENTS.'/AAMkEvent1')
        ->and(CalendarLinkEvent::query()->count())->toBe(0);

    m365Http(graphCreated('AAMkEvent2'));
    DB::transaction(fn () => $organisation->restore());
    [$post] = graphRequests();
    expect($post->method())->toBe('POST')->and($post->data()['subject'])->toBe('Rendez-vous physique – Hôtel du Lac')
        ->and(CalendarLinkEvent::query()->sole()->outlook_event_id)->toBe('AAMkEvent2');
});

it('deletes the outlook event of a person fiche sent to the trash', function () {
    $this->travelTo(m365At('2026-11-18 10:00:00'));
    m365Config();
    $user = commercial();
    m365Link($user);
    $person = makePerson('Dupont', ['first_name' => 'Jean']);
    m365Http(graphCreated());
    m365Meeting($user, ['person_id' => $person->id, 'organisation_id' => null]);

    m365Http([GRAPH_EVENTS.'/AAMkEvent1' => Http::response(null, 204)]);
    DB::transaction(fn () => $person->delete());
    [$delete] = graphRequests();
    expect($delete->method())->toBe('DELETE');
});

it('points the outlook event to the kept fiche after a merge', function () {
    $this->travelTo(m365At('2026-11-18 10:00:00'));
    m365Config();
    $user = commercial();
    m365Link($user);
    $keep = Organisation::query()->create(['name' => 'Hôtel du Lac SAS']);
    $drop = Organisation::query()->create(['name' => 'Hotel du lac']);
    m365Http(graphCreated());
    m365Meeting($user, ['organisation_id' => $drop->id]);

    m365Http([GRAPH_EVENTS.'/AAMkEvent1' => Http::response(['id' => 'AAMkEvent1'])]);
    $this->actingAs($user)->post('/crm/merge/organisation', ['keep' => $keep->id, 'drop' => $drop->id, 'fields' => []])
        ->assertRedirect(route('crm.organisations.show', $keep));

    $patches = collect(graphRequests())->filter(fn (Request $r) => $r->method() === 'PATCH')->values();
    expect($patches)->not->toBeEmpty();
    $last = $patches->last();
    expect($last->data()['subject'])->toBe('Rendez-vous physique – Hôtel du Lac SAS')
        ->and($last->data()['body']['content'])->toContain(url('/crm/organisations/'.$keep->id))
        ->and(collect(graphRequests())->contains(fn (Request $r) => $r->method() === 'POST'))->toBeFalse();
});

it('deletes the outlook event when its opportunity goes to the trash', function () {
    $this->travelTo(m365At('2026-11-18 10:00:00'));
    m365Config();
    $user = commercial();
    m365Link($user);
    $opportunity = makeOpportunity(Organisation::query()->firstOrCreate(['name' => 'Hôtel du Lac']), ['title' => 'Formation HACCP']);
    m365Http(graphCreated());
    m365Meeting($user, ['opportunity_id' => $opportunity->id, 'organisation_id' => null]);

    m365Http([GRAPH_EVENTS.'/AAMkEvent1' => Http::response(null, 204)]);
    DB::transaction(fn () => $opportunity->delete());
    [$delete] = graphRequests();
    expect($delete->method())->toBe('DELETE')->and(CalendarLinkEvent::query()->count())->toBe(0);

    m365Http(graphCreated('AAMkEvent2'));
    DB::transaction(fn () => $opportunity->restore());
    [$post] = graphRequests();
    expect($post->method())->toBe('POST');
});

it('links the outlook event to the organisme address, not APP_URL', function () {
    config(['tms.mode' => 'multi', 'app.url' => 'http://worker.invalid']);
    TenantDomain::factory()->for(tenant())->create(['host' => 'cap.example.test']);
    m365Config();
    $user = commercial();
    m365Link($user);
    m365Http(graphCreated());

    $meeting = m365Meeting($user);

    [$post] = graphRequests();
    expect($post->data()['body']['content'])->toContain('https://cap.example.test/crm/organisations/'.$meeting->organisation_id)
        ->not->toContain('worker.invalid');
});

it('puts the purpose of the action in the outlook event, never its next steps nor its report', function () {
    $this->travelTo(m365At('2026-11-18 10:00:00'));
    m365Config();
    $user = commercial();
    m365Link($user);
    m365Http(graphCreated());

    $meeting = m365Meeting($user, ['purpose' => 'Présenter la formation HACCP', 'next_steps' => 'Suite confidentielle']);

    [$post] = graphRequests();
    expect($post->data()['body']['content'])->toContain('Motif : Présenter la formation HACCP')
        ->and(json_encode($post->data(), JSON_UNESCAPED_UNICODE))->not->toContain('confidentiel');

    // A new purpose updates the event; new next steps alone send nothing.
    m365Http([GRAPH_EVENTS.'/AAMkEvent1' => Http::response(['id' => 'AAMkEvent1'])]);
    $meeting->update(['next_steps' => 'Autre suite confidentielle']);
    expect(graphRequests())->toBe([]);
    $meeting->update(['purpose' => 'Signer la convention']);
    [$patch] = graphRequests();
    expect($patch->method())->toBe('PATCH')
        ->and($patch->data()['body']['content'])->toContain('Motif : Signer la convention')
        ->and(json_encode($patch->data(), JSON_UNESCAPED_UNICODE))->not->toContain('confidentiel');
    expect(ConnectorRun::query()->get()->toJson())->not->toContain('Signer')->not->toContain('HACCP');
});
