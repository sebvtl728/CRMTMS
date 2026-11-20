<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Sessions\SessionBoard;
use App\Modules\Sessions\Sessions\SessionListQuery;
use App\Modules\Sessions\Sessions\SessionStatus;
use App\Modules\Sessions\Sessions\SessionWriter;
use App\Modules\Sessions\Sessions\TrainingSession;
use App\Modules\Sessions\Summary\SessionChecklist;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';
require_once __DIR__.'/Support/attendance.php';

beforeEach(function () {
    plSetUp();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Europe/Paris'));
});

/**
 * Board columns keyed by state: [total, list of session numbers].
 *
 * @param  list<array{status: string, label: string, total: int, cards: list<array<string, mixed>>}>  $board
 * @return array<string, array{0: int, 1: list<string>}>
 */
function sbColumns(array $board): array
{
    return collect($board)->mapWithKeys(fn (array $c) => [$c['status'] => [$c['total'], array_column($c['cards'], 'number')]])->all();
}

function sbCancel(TrainingSession $session): TrainingSession
{
    return app(SessionWriter::class)->cancel($session, crmItem(ReferenceList::CancelReason, 'Effectif insuffisant')->id);
}

it('puts the sessions in columns by their shown state, with the list filters and scope', function () {
    $planned = plSession('2026-12-07', '2026-12-09');
    $confirmed = plSession('2026-12-14', '2026-12-16', ['status' => 'confirmed']);
    $running = plSession('2026-11-10', '2026-11-20');
    $recent = plSession('2026-10-01', '2026-10-03', ['status' => 'confirmed']);
    $old = plSession('2026-06-01', '2026-06-03');
    $cancelled = sbCancel(plSession('2026-12-01', '2026-12-02'));

    $this->actingAs(admin(twoFactor: true))->get('/sessions?vue=colonnes')->assertInertia(fn (Assert $page) => $page
        ->component('sessions/sessions/index')
        ->where('view', 'colonnes')
        ->where('board', fn ($board) => sbColumns($board->all()) === [
            'planned' => [1, [$planned->number]],
            'confirmed' => [1, [$confirmed->number]],
            'running' => [1, [$running->number]],
            // Ended within the last 90 days only: older ones through the list.
            'done' => [1, [$recent->number]],
        ] && array_column($board->all(), 'label') === ['Planifiée', 'Confirmée', 'En cours', 'Terminée']));

    // « Afficher les annulées ».
    $this->get('/sessions?vue=colonnes&annulees=1')->assertInertia(fn (Assert $page) => $page
        ->where('board.4.status', 'cancelled')->where('board.4.label', 'Annulée')->where('board.4.total', 1)
        ->where('board.4.cards.0.number', $cancelled->number));

    // The list stays the default view, without a board.
    $this->get('/sessions')->assertInertia(fn (Assert $page) => $page->where('view', 'liste')->where('board', null)->has('sessions.data', 6));

    // The list filters apply (here: the responsible) and so does the scope.
    $owner = l2User([Permissions::SESSIONS_VIEW => AccessScope::Own]);
    $planned->update(['responsible_user_id' => $owner->id]);
    $recent->update(['responsible_user_id' => $owner->id]);
    $board = fn (array $filters, User $user) => sbColumns(SessionBoard::build(SessionListQuery::filters($filters), $user, CarbonImmutable::parse('2026-11-17', 'Europe/Paris'), false));

    expect($board(['responsible' => $owner->id], admin()))->toBe([
        'planned' => [1, [$planned->number]], 'confirmed' => [0, []], 'running' => [0, []], 'done' => [1, [$recent->number]],
    ])->and($board([], $owner))->toBe([
        'planned' => [1, [$planned->number]], 'confirmed' => [0, []], 'running' => [0, []], 'done' => [1, [$recent->number]],
    ])->and($old->refresh()->effectiveStatus(CarbonImmutable::parse('2026-11-17')))->toBe(SessionStatus::Done);
});

it('shows at most 50 cards per column, ordered by start (most recent end first when done), with the full count', function () {
    $sessions = collect(range(1, 51))->map(fn (int $i) => plSession(
        CarbonImmutable::parse('2027-01-01')->addDays($i)->toDateString(),
        CarbonImmutable::parse('2027-01-01')->addDays($i + 1)->toDateString(),
    ));
    plSession('2026-10-01', '2026-10-02');
    $later = plSession('2026-09-28', '2026-11-05');

    $board = SessionBoard::build(SessionListQuery::filters([]), admin(), CarbonImmutable::parse('2026-11-17', 'Europe/Paris'), false);

    expect($board[0]['total'])->toBe(51)->and($board[0]['cards'])->toHaveCount(SessionBoard::LIMIT)
        ->and($board[0]['cards'][0]['number'])->toBe($sessions[0]->number)
        ->and($board[0]['cards'][49]['number'])->toBe($sessions[49]->number)
        ->and($board[3]['cards'][0]['number'])->toBe($later->number);
});

it('gives each card its hours, its trainees and the pending cards of the « Synthèse » the user sees', function () {
    $session = plSession('2026-12-07', '2026-12-18', ['capacity' => 8]);
    $module = plModule($session);
    plSeance($module, '2026-12-07', '09:00', '12:30');
    atEnrol($session, 'Durand');
    $user = userWithPermissions([Permissions::SESSIONS_VIEW]);
    $today = CarbonImmutable::parse('2026-11-17', 'Europe/Paris');

    $card = SessionBoard::build(SessionListQuery::filters([]), $user, $today, false)[0]['cards'][0];

    $expected = SessionChecklist::pending(SessionChecklist::forMany(collect([$session->refresh()]), $user, $today)[$session->id]);
    expect($card)->toMatchArray([
        'id' => $session->id,
        'label' => $session->label('HACCP 3 jours'),
        'planned_minutes' => 1260,
        'scheduled_minutes' => 210,
        'hours_label' => '3 h 30 / 21 h',
        'enrolled' => 1,
        'capacity' => 8,
        'pending' => $expected,
        'can_move' => false,
    ])->and($expected)->toBeGreaterThan(0);

    // Who may update it may move it.
    expect(SessionBoard::build(SessionListQuery::filters([]), plManager(), $today, false)[0]['cards'][0]['can_move'])->toBeTrue();
});

it('moves a session between « Planifiée » and « Confirmée » only', function () {
    $session = plSession('2026-12-07', '2026-12-09');
    $this->actingAs($manager = plManager());

    $this->postJson("/sessions/{$session->id}/status", ['status' => 'confirmed'])->assertOk()
        ->assertJson(['status' => 'confirmed', 'status_label' => 'Confirmée']);
    expect($session->refresh()->status->value)->toBe('confirmed');

    $this->postJson("/sessions/{$session->id}/status", ['status' => 'planned'])->assertOk();
    expect($session->refresh()->status->value)->toBe('planned');

    $this->postJson("/sessions/{$session->id}/status", ['status' => 'done'])->assertUnprocessable()->assertJsonValidationErrors('status');

    $running = plSession('2026-11-10', '2026-11-20');
    $this->postJson("/sessions/{$running->id}/status", ['status' => 'confirmed'])->assertStatus(409)
        ->assertJson(['message' => 'Cette session ne peut plus changer d\'état ainsi.']);
    expect($running->refresh()->status->value)->toBe('planned');

    $cancelled = sbCancel(plSession('2026-12-01', '2026-12-02'));
    $this->postJson("/sessions/{$cancelled->id}/status", ['status' => 'planned'])->assertStatus(409);
});

it('refuses the move without the right to update the session, and hides a session out of scope', function () {
    $session = plSession('2026-12-07', '2026-12-09');

    $this->actingAs(userWithPermissions([Permissions::SESSIONS_VIEW]))
        ->postJson("/sessions/{$session->id}/status", ['status' => 'confirmed'])->assertForbidden();

    $own = l2User([Permissions::SESSIONS_VIEW => AccessScope::Own, Permissions::SESSIONS_UPDATE => AccessScope::Own]);
    $this->actingAs($own)->postJson("/sessions/{$session->id}/status", ['status' => 'confirmed'])->assertNotFound();

    // Seen (all) but updated only on his own sessions.
    $seer = l2User([Permissions::SESSIONS_VIEW => null, Permissions::SESSIONS_UPDATE => AccessScope::Own]);
    $this->actingAs($seer)->postJson("/sessions/{$session->id}/status", ['status' => 'confirmed'])->assertForbidden();
    expect($session->refresh()->status->value)->toBe('planned');
});

it('builds the board in a fixed number of queries', function () {
    $user = plManager();
    $today = CarbonImmutable::parse('2026-11-17', 'Europe/Paris');
    foreach (range(1, 30) as $i) {
        $status = ['planned', 'confirmed'][$i % 2];
        $session = $i % 3 === 0 ? plSession('2026-11-10', '2026-11-20') : plSession('2026-12-07', '2026-12-09', ['status' => $status]);
        plSeance(plModule($session), $i % 3 === 0 ? '2026-11-18' : '2026-12-07');
        atEnrol($session, "Durand{$i}");
    }
    plSession('2026-10-01', '2026-10-02');

    $count = function (int $limit) use ($user, $today): int {
        $ids = TrainingSession::query()->orderBy('number')->limit($limit)->pluck('id')->all();
        TrainingSession::query()->whereNotIn('id', $ids)->update(['das_id' => crmItem(ReferenceList::Das, 'Métiers supports')->id]);
        $filters = SessionListQuery::filters(['das' => l2Training()->das_id]);
        SessionBoard::build($filters, $user, $today, true); // memoised scopes
        DB::flushQueryLog();
        DB::enableQueryLog();
        $board = SessionBoard::build($filters, $user, $today, true);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        TrainingSession::query()->update(['das_id' => l2Training()->das_id]);
        expect(array_sum(array_column($board, 'total')))->toBe($limit);

        return $n;
    };

    expect($count(10))->toBe($count(30));
});

it('shows a session done on its last day once its last month is closed', function () {
    $session = plSession('2026-11-02', '2026-11-20');
    $today = CarbonImmutable::parse('2026-11-20', 'Europe/Paris');
    $user = l2User([Permissions::SESSIONS_VIEW => null]);
    $ids = fn (string $status) => SessionListQuery::build(SessionListQuery::filters(['status' => $status]), $user, $today)->pluck('training_sessions.id')->all();

    expect($session->refresh()->effectiveStatus($today))->toBe(SessionStatus::Running)
        ->and($ids('running'))->toContain($session->id)->and($ids('done'))->not->toContain($session->id);

    app(App\Modules\Sessions\Attendance\Closures::class)->close($session, '2026-11');

    expect($session->refresh()->effectiveStatus($today))->toBe(SessionStatus::Done)
        ->and($ids('done'))->toContain($session->id)->and($ids('running'))->not->toContain($session->id);
    // Before its last day a closed month changes nothing.
    expect($session->effectiveStatus(CarbonImmutable::parse('2026-11-19', 'Europe/Paris')))->toBe(SessionStatus::Running);
});
