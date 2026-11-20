<?php

use App\Modules\Connectors\Calendar\CalendarLinkEvent;
use App\Modules\Connectors\Calendar\OutlookSources;
use App\Modules\Connectors\Calendar\PushSourceToOutlook;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Core\HostPolicy;
use App\Modules\Core\Access\Role;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Modules\SessionModules;
use App\Modules\Sessions\Permissions as SessionsPermissions;
use App\Modules\Sessions\Planning\GenerationRequest;
use App\Modules\Sessions\Planning\SeanceBatch;
use App\Modules\Sessions\Planning\SeanceGenerator;
use App\Modules\Sessions\Planning\Seances;
use App\Modules\Sessions\Resources\Rooms;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Trainers\Trainer;
use App\Modules\Sessions\Trainers\Trainers;
use App\Modules\Sessions\Trainers\TrainerStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/Support/outlook.php';
require_once __DIR__.'/../Sessions/Support/lot2a.php';
require_once __DIR__.'/../Sessions/Support/planning.php';
require_once __DIR__.'/../Sessions/Support/attendance.php';

beforeEach(function () {
    plSetUp();
    HostPolicy::resolveUsing(fn (string $host): array => ['203.0.113.20']);
    m365Http();
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00', 'Europe/Paris'));
});
afterEach(fn () => HostPolicy::resolveUsing(null));

/**
 * A user of the Formateur role and his trainer record.
 *
 * @return array{0: User, 1: Trainer}
 */
function osFormateur(): array
{
    $user = userWithRole(Role::Formateur);

    return [$user, Trainers::forUser($user) ?? Trainers::ensureForUser($user)];
}

/** Graph answers each creation with the next id. */
function osCreates(string ...$ids): void
{
    $sequence = Http::sequence();
    foreach ($ids as $id) {
        $sequence->push(['id' => $id], 201);
    }
    m365Http([GRAPH_EVENTS => $sequence, GRAPH_EVENTS.'/*' => Http::response(null, 204)]);
}

/** @return list<string> « METHOD url » of the Graph calls */
function osCalls(): array
{
    return array_map(fn (Request $r) => $r->method().' '.$r->url(), graphRequests());
}

it('copies a séance into the calendar of its trainer, without trainee nor note', function () {
    m365Config();
    [$user, $trainer] = osFormateur();
    $link = m365Link($user);
    $session = plSession('2026-09-01', '2026-11-30', ['title' => 'Commis de cuisine']);
    $module = plModule($session);
    $room = plRoom('Salle A');
    $room->update(['address' => '12 rue des Lilas, Annecy']);
    atEnrol($session, 'Confidentiel');
    osCreates('AAMkSeance1');

    $seance = plSeance($module, '2026-09-02', '09:00', '12:30', ['trainer_id' => $trainer->id, 'room_id' => $room->id, 'note' => 'Note interne']);

    [$post] = graphRequests();
    $body = $post->data();
    expect($post->method())->toBe('POST')
        ->and($post->header('Authorization')[0])->toBe('Bearer '.$link->access_token)
        ->and($body['subject'])->toBe('Commis de cuisine ('.$session->number.') - '.$module->title)
        ->and($body['start'])->toBe(['dateTime' => '2026-09-02T09:00:00', 'timeZone' => 'Europe/Paris'])
        ->and($body['end'])->toBe(['dateTime' => '2026-09-02T12:30:00', 'timeZone' => 'Europe/Paris'])
        ->and($body['location'])->toBe(['displayName' => 'Salle A, 12 rue des Lilas, Annecy'])
        ->and($body['body']['content'])->toContain(route('my-planning.show', ['date' => '2026-09-02']))
        ->and($body['transactionId'])->toBeString();
    expect(json_encode($body))->not->toContain('Confidentiel')->not->toContain('Note interne');
    $copy = CalendarLinkEvent::query()->sole();
    expect($copy->source)->toBe('sessions.seance')->and($copy->source_id)->toBe($seance->id)
        ->and($copy->outlook_event_id)->toBe('AAMkSeance1');

    // Changed: PATCH of the same event, at the new hours.
    m365Http([GRAPH_EVENTS.'/AAMkSeance1' => Http::response(['id' => 'AAMkSeance1'])]);
    app(Seances::class)->reschedule($seance, ['starts_at' => '13:30', 'ends_at' => '17:00', 'room_id' => null, 'place_text' => 'Restaurant Le Lac']);
    [$patch] = graphRequests();
    expect($patch->method())->toBe('PATCH')
        ->and($patch->data()['start']['dateTime'])->toBe('2026-09-02T13:30:00')
        ->and($patch->data()['location'])->toBe(['displayName' => 'Restaurant Le Lac']);

    // Journal: ids and outcome only.
    $runs = ConnectorRun::query()->get();
    expect($runs)->toHaveCount(2)->and($runs->every(fn (ConnectorRun $r) => $r->status === 'success'))->toBeTrue()
        ->and($runs->first()->payload_ref)->toBe('sessions.seance:'.$seance->id);
    expect($runs->toJson())->not->toContain('Commis')->not->toContain('Salle A')->not->toContain('Lac')
        ->not->toContain($user->email)->not->toContain($user->name)->not->toContain('Confidentiel');
});

it('moves the copy when the trainer of the séance changes, one by one or in a batch', function () {
    m365Config();
    [$first, $a] = osFormateur();
    [$second, $b] = osFormateur();
    $linkA = m365Link($first);
    $linkB = m365Link($second);
    $session = plSession();
    $module = plModule($session);
    osCreates('AAMkA1', 'AAMkA2');
    $one = plSeance($module, '2026-09-02', '09:00', '12:30', ['trainer_id' => $a->id]);
    $two = plSeance($module, '2026-09-03', '09:00', '12:30', ['trainer_id' => $a->id]);

    osCreates('AAMkB1');
    app(Seances::class)->reschedule($one, ['trainer_id' => $b->id]);
    expect(osCalls())->toBe(['DELETE '.GRAPH_EVENTS.'/AAMkA1', 'POST '.GRAPH_EVENTS])
        ->and(graphRequests()[1]->header('Authorization')[0])->toBe('Bearer '.$linkB->access_token)
        ->and(CalendarLinkEvent::query()->where('source_id', $one->id)->sole()->calendar_link_id)->toBe($linkB->id);

    osCreates('AAMkB2');
    app(SeanceBatch::class)->update($session, ['seance_ids' => [$two->id], 'apply_trainer' => true, 'trainer_id' => $b->id]);
    expect(osCalls())->toBe(['DELETE '.GRAPH_EVENTS.'/AAMkA2', 'POST '.GRAPH_EVENTS])
        ->and(CalendarLinkEvent::query()->where('calendar_link_id', $linkA->id)->count())->toBe(0)
        ->and(CalendarLinkEvent::query()->where('calendar_link_id', $linkB->id)->count())->toBe(2);
});

it('copies each generated séance', function () {
    m365Config();
    [$user, $trainer] = osFormateur();
    m365Link($user);
    $session = plSession('2026-09-01', '2026-09-30');
    $module = plModule($session);
    osCreates('AAMk1', 'AAMk2');

    $created = app(SeanceGenerator::class)->generate($module, GenerationRequest::fromInput([
        'from' => '2026-09-07', 'to' => '2026-09-13', 'weekdays' => [1, 2], 'slots' => [['start' => '09:00', 'end' => '12:00']], 'trainer_id' => $trainer->id,
    ], $module));

    expect($created)->toHaveCount(2)
        ->and(collect(graphRequests())->map->method()->all())->toBe(['POST', 'POST'])
        ->and(CalendarLinkEvent::query()->orderBy('outlook_event_id')->pluck('source_id')->sort()->values()->all())
        ->toBe(collect($created)->pluck('id')->sort()->values()->all());
});

it('deletes the copy of a cancelled or deleted séance, and every copy of a cancelled session', function () {
    m365Config();
    [$user, $trainer] = osFormateur();
    m365Link($user);
    $session = plSession();
    $module = plModule($session);
    osCreates('AAMk1', 'AAMk2', 'AAMk3', 'AAMk4');
    $cancelled = plSeance($module, '2026-09-02', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    $deleted = plSeance($module, '2026-09-03', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    plSeance($module, '2026-09-04', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    plSeance($module, '2026-09-07', '09:00', '12:30', ['trainer_id' => $trainer->id]);

    m365Http([GRAPH_EVENTS.'/*' => Http::response(null, 204)]);
    app(Seances::class)->cancel($cancelled, 'Formateur malade');
    expect(osCalls())->toBe(['DELETE '.GRAPH_EVENTS.'/AAMk1']);

    m365Http([GRAPH_EVENTS.'/*' => Http::response(null, 204)]);
    app(Seances::class)->delete($deleted);
    expect(osCalls())->toBe(['DELETE '.GRAPH_EVENTS.'/AAMk2']);

    m365Http([GRAPH_EVENTS.'/*' => Http::response(null, 204)]);
    app(SessionWriter::class)->cancel($session, crmItem(ReferenceList::CancelReason, 'Effectif insuffisant')->id);
    expect(collect(osCalls())->sort()->values()->all())->toBe(['DELETE '.GRAPH_EVENTS.'/AAMk3', 'DELETE '.GRAPH_EVENTS.'/AAMk4'])
        ->and(CalendarLinkEvent::query()->count())->toBe(0);
});

it('follows the account of the trainer: unlinked, deactivated, linked again', function () {
    m365Config();
    [$user, $trainer] = osFormateur();
    m365Link($user);
    $session = plSession('2026-08-01', '2026-09-30');
    $module = plModule($session);
    osCreates('AAMkPast', 'AAMk1');
    $this->travelTo(CarbonImmutable::parse('2026-08-01 08:00', 'Europe/Paris'));
    plSeance($module, '2026-08-20', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00', 'Europe/Paris'));
    plSeance($module, '2026-09-02', '09:00', '12:30', ['trainer_id' => $trainer->id]);

    // Unlinked: the future copies leave his calendar (the past ones stay).
    m365Http([GRAPH_EVENTS.'/*' => Http::response(null, 204)]);
    Trainers::link($trainer, null);
    expect(osCalls())->toBe(['DELETE '.GRAPH_EVENTS.'/AAMk1']);

    // Linked again: back in his calendar.
    osCreates('AAMk2');
    Trainers::link($trainer->refresh(), $user);
    expect(osCalls())->toBe(['POST '.GRAPH_EVENTS]);

    // Deactivated: removed; active again: back.
    m365Http([GRAPH_EVENTS.'/*' => Http::response(null, 204)]);
    Trainers::update($trainer->refresh(), TrainerStatus::from($trainer->status), false);
    expect(osCalls())->toBe(['DELETE '.GRAPH_EVENTS.'/AAMk2']);
    osCreates('AAMk3');
    Trainers::update($trainer->refresh(), TrainerStatus::from($trainer->status), true);
    expect(osCalls())->toBe(['POST '.GRAPH_EVENTS]);
});

it('sends nothing for a trainer without account or without Outlook, nor when the session is in the trash', function () {
    m365Config();
    [$user, $linked] = osFormateur();
    m365Link($user);
    [, $withoutLink] = osFormateur();
    $session = plSession();
    $module = plModule($session);

    plSeance($module, '2026-09-02', '09:00', '12:30', ['trainer_id' => plTrainer('Sans compte')->id]);
    plSeance($module, '2026-09-03', '09:00', '12:30', ['trainer_id' => $withoutLink->id]);
    plSeance($module, '2026-09-04', '09:00', '12:30');

    expect(graphRequests())->toBe([])->and(CalendarLinkEvent::query()->count())->toBe(0);

    // A trashed session: its séances have no copy (removed when it goes to the trash).
    osCreates('AAMk1');
    $seance = plSeance($module, '2026-09-07', '09:00', '12:30', ['trainer_id' => $linked->id]);
    m365Http([GRAPH_EVENTS.'/*' => Http::response(null, 204)]);
    app(SessionWriter::class)->trash($session);
    expect(osCalls())->toBe(['DELETE '.GRAPH_EVENTS.'/AAMk1'])
        ->and(OutlookSources::find('sessions.seance', $seance->id))->toBeNull();
});

it('queues nothing without Microsoft 365, nor for a write rolled back', function () {
    [$user, $trainer] = osFormateur();
    m365Link($user);
    $session = plSession();
    $module = plModule($session);
    Queue::fake();

    plSeance($module, '2026-09-02', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    Queue::assertNotPushed(PushSourceToOutlook::class);

    m365Config();
    try {
        DB::transaction(function () use ($module, $trainer): void {
            plSeance($module, '2026-09-03', '09:00', '12:30', ['trainer_id' => $trainer->id]);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    Queue::assertNotPushed(PushSourceToOutlook::class);

    plSeance($module, '2026-09-04', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    Queue::assertPushed(PushSourceToOutlook::class, 1);
});

it('does not duplicate the event when the job is replayed', function () {
    m365Config();
    [$user, $trainer] = osFormateur();
    m365Link($user);
    $module = plModule(plSession());
    osCreates('AAMk1');
    $seance = plSeance($module, '2026-09-02', '09:00', '12:30', ['trainer_id' => $trainer->id]);

    m365Http([GRAPH_EVENTS.'/AAMk1' => Http::response(['id' => 'AAMk1'])]);
    PushSourceToOutlook::dispatchSync('sessions.seance', $seance->id);
    PushSourceToOutlook::dispatchSync('sessions.seance', $seance->id);
    expect(collect(graphRequests())->map->method()->all())->toBe(['PATCH', 'PATCH'])
        ->and(CalendarLinkEvent::query()->count())->toBe(1);

    // Crash between Graph's answer and the save: the replay reuses the same transactionId.
    $row = CalendarLinkEvent::query()->sole();
    $row->forceFill(['outlook_event_id' => null])->save();
    osCreates('AAMk1');
    PushSourceToOutlook::dispatchSync('sessions.seance', $seance->id);
    [$post] = graphRequests();
    expect($post->data()['transactionId'])->toBe($row->transaction_id)
        ->and(CalendarLinkEvent::query()->count())->toBe(1);
});

it('updates the copies when the session, its module or the room is renamed', function () {
    m365Config();
    [$user, $trainer] = osFormateur();
    m365Link($user);
    $session = plSession('2026-09-01', '2026-09-30', ['title' => 'Commis de cuisine']);
    $module = plModule($session);
    $room = plRoom('Salle A');
    osCreates('AAMk1');
    plSeance($module, '2026-09-02', '09:00', '12:30', ['trainer_id' => $trainer->id, 'room_id' => $room->id]);
    $patched = fn (): array => array_map(fn (Request $r) => $r->method().' '.$r->data()['subject'].' @ '.$r->data()['location']['displayName'], graphRequests());

    // The session renamed: the subject follows.
    m365Http([GRAPH_EVENTS.'/AAMk1' => Http::response(['id' => 'AAMk1'])]);
    $session->refresh();
    app(SessionWriter::class)->update($session, [
        ...$session->only(['training_id', 'kind', 'organisation_id', 'modality_id', 'location_kind', 'location_text', 'capacity', 'responsible_user_id', 'notes']),
        'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => $session->status->value, 'title' => 'Cuisinier confirmé',
    ]);
    expect($patched())->toBe(['PATCH Cuisinier confirmé ('.$session->number.') - '.$module->title.' @ Salle A']);

    // A change that does not show (capacity): nothing sent.
    m365Http();
    app(SessionWriter::class)->update($session->refresh(), [
        ...$session->only(['training_id', 'kind', 'organisation_id', 'modality_id', 'location_kind', 'location_text', 'responsible_user_id', 'notes', 'title']),
        'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'status' => $session->status->value, 'capacity' => 12,
    ]);
    expect(graphRequests())->toBe([]);

    // The module renamed.
    m365Http([GRAPH_EVENTS.'/AAMk1' => Http::response(['id' => 'AAMk1'])]);
    app(SessionModules::class)->save($session->refresh(), ['title' => 'Cuisine chaude', 'planned_hours' => '21', 'modality_id' => '', 'dating_mode' => 'seances', 'starts_on' => '', 'ends_on' => ''], $module);
    expect($patched())->toBe(['PATCH Cuisinier confirmé ('.$session->number.') - Cuisine chaude @ Salle A']);

    // The room renamed and given an address.
    m365Http([GRAPH_EVENTS.'/AAMk1' => Http::response(['id' => 'AAMk1'])]);
    Rooms::save($room, ['name' => 'Salle Mont-Blanc', 'capacity' => null, 'address' => '12 rue des Lilas, Annecy', 'active' => true]);
    expect($patched())->toBe(['PATCH Cuisinier confirmé ('.$session->number.') - Cuisine chaude @ Salle Mont-Blanc, 12 rue des Lilas, Annecy']);
});

it('keeps the name of the CRM actions to the CRM', function () {
    expect(fn () => OutlookSources::register('crm.activity', fn (string $id) => null))->toThrow(InvalidArgumentException::class);
});

/** Connects $user to Outlook through the OAuth flow; Graph answers with $graph. */
function osConnect(TestCase $test, User $user, array $graph = []): void
{
    $location = (string) $test->actingAs($user)->post('/settings/calendar/outlook/connect')->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith('https://login.microsoftonline.com/');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    m365Http([M365_TOKEN_URL => Http::response([
        'token_type' => 'Bearer', 'scope' => 'https://graph.microsoft.com/Calendars.ReadWrite', 'expires_in' => 3599,
        'access_token' => 'access-'.Str::random(20), 'refresh_token' => 'refresh-'.Str::random(20),
    ]), ...$graph]);
    $test->actingAs($user)->get('/settings/calendar/outlook/callback?code=code-123&state='.urlencode($query['state']))
        ->assertRedirect('/settings/calendar')->assertSessionHas('status');
}

it('lets a trainer without CRM connect Outlook; a user without actions nor séances gets 403', function () {
    m365Config();
    [$user] = osFormateur();

    $this->actingAs($user)->get('/settings/calendar')->assertOk()
        ->assertInertia(fn ($page) => $page->where('crm', false)
            ->where('outlook.available', true)->where('outlook.status', 'none')->where('outlook.sources', true));
    $location = (string) $this->actingAs($user)->post('/settings/calendar/outlook/connect')->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith('https://login.microsoftonline.com/'.M365_TENANT.'/oauth2/v2.0/authorize');

    // An inactive trainer: no Outlook source serves him any more.
    $trainer = Trainers::forUser($user);
    Trainers::update($trainer, TrainerStatus::from($trainer->status), false);
    $this->actingAs($user)->post('/settings/calendar/outlook/connect')->assertForbidden();

    $nobody = userWithPermissions([SessionsPermissions::SESSIONS_VIEW]);
    $this->actingAs($nobody)->post('/settings/calendar/outlook/connect')->assertForbidden();
    $this->actingAs($nobody)->get('/settings/calendar/outlook/callback?code=c')->assertForbidden();
    $this->actingAs($nobody)->delete('/settings/calendar/outlook')->assertForbidden();
});

it('sends the upcoming séances of the trainer when he connects Outlook, and again after a reconnection', function () {
    m365Config();
    [$user, $trainer] = osFormateur();
    $session = plSession('2026-08-01', '2027-04-30');
    $module = plModule($session);
    $this->travelTo(CarbonImmutable::parse('2026-08-01 08:00', 'Europe/Paris'));
    plSeance($module, '2026-08-20', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    $this->travelTo(CarbonImmutable::parse('2026-09-01 08:00', 'Europe/Paris'));
    $soon = plSeance($module, '2026-09-02', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    plSeance($module, '2027-03-15', '09:00', '12:30', ['trainer_id' => $trainer->id]);
    plSeance($module, '2026-09-03', '09:00', '12:30', ['trainer_id' => plTrainer('Autre')->id]);
    expect(graphRequests())->toBe([]);

    osConnect($this, $user, [GRAPH_EVENTS => Http::response(['id' => 'AAMk1'], 201)]);
    expect(osCalls())->toBe(['POST '.GRAPH_EVENTS])
        ->and(CalendarLinkEvent::query()->sole()->source_id)->toBe($soon->id);

    m365Http([GRAPH_EVENTS.'/*' => Http::response(null, 204)]);
    $this->actingAs($user)->delete('/settings/calendar/outlook')->assertRedirect('/settings/calendar');
    expect(osCalls())->toBe(['DELETE '.GRAPH_EVENTS.'/AAMk1'])
        ->and(CalendarLinkEvent::query()->count())->toBe(0);

    osConnect($this, $user, [GRAPH_EVENTS => Http::response(['id' => 'AAMk2'], 201)]);
    expect(osCalls())->toBe(['POST '.GRAPH_EVENTS])
        ->and(CalendarLinkEvent::query()->sole()->outlook_event_id)->toBe('AAMk2');
});
