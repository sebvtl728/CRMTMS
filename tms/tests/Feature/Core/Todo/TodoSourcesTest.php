<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Todo\TodoSources;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Attendance\Signing\AttendanceSignature;
use App\Modules\Sessions\Trainers\Trainers;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/../../Sessions/Support/lot2a.php';
require_once __DIR__.'/../../Sessions/Support/planning.php';
require_once __DIR__.'/../../Sessions/Support/attendance.php';
require_once __DIR__.'/../../Sessions/Support/signing.php';

beforeEach(fn () => tenant());

afterEach(fn () => CarbonImmutable::setTestNow());

it('lists the sources the user may see with their counts and links', function () {
    TodoSources::register('test.one', 'Commercial', 'Devis de test', 'settings.manage', fn (User $u) => 2, fn (User $u) => '/crm/quotes');
    TodoSources::register('test.hidden', 'Finance', 'Invisible', 'finance.payments.manage', fn (User $u) => 5, fn (User $u) => '/finance');
    $user = userWithRole(Role::Commercial);
    $user->givePermissionTo('settings.manage');

    $rows = collect(TodoSources::for($user));
    expect($rows->firstWhere('key', 'test.one'))->toMatchArray(['group' => 'Commercial', 'label' => 'Devis de test', 'count' => 2, 'url' => '/crm/quotes'])
        ->and($rows->firstWhere('key', 'test.hidden'))->toBeNull();
});

it('shows Indisponible for a failing source without breaking the page', function () {
    TodoSources::register('test.broken', 'Sessions', 'Cassée', 'settings.manage', fn (User $u) => throw new RuntimeException('boom'), fn (User $u) => '/sessions');
    TodoSources::register('test.fine', 'Sessions', 'Juste', 'settings.manage', fn (User $u) => 1, fn (User $u) => '/sessions');
    $admin = admin(twoFactor: true);

    $this->actingAs($admin)->get('/a-traiter')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('todo/index')
        ->where('rows', fn ($rows) => collect($rows)->firstWhere('key', 'test.broken')['count'] === null
            && collect($rows)->firstWhere('key', 'test.fine')['count'] === 1));
    expect(TodoSources::total($admin))->toBeGreaterThanOrEqual(1);
});

it('counts the catch-ups to confirm within the user scope, never for a trainer', function () {
    plSetUp();
    sgNow();
    $formateur = userWithRole(Role::Formateur);
    $trainer = Trainers::forUser($formateur) ?? Trainers::ensureForUser($formateur);
    $session = plSession();
    plSeance(plModule($session), '2026-09-10', '09:00', '12:00', ['trainer_id' => $trainer->id]);
    $lea = atEnrol($session, 'Durand');
    $slot = sgOpen($session)['slot'];
    AttendanceSignature::query()->create(['slot_id' => $slot->id, 'enrolment_id' => $lea->id, 'kind' => AttendanceSignature::KIND_REMOTE, 'signed_at' => now()]);

    expect(collect(TodoSources::for(atManager()))->firstWhere('key', 'sessions.catch_ups')['count'])->toBe(1)
        ->and(collect(TodoSources::for($formateur))->firstWhere('key', 'sessions.catch_ups'))->toBeNull();
});

it('answers every declared module source for an administrator', function () {
    $rows = collect(TodoSources::for(admin()))->reject(fn (array $r) => str_starts_with($r['key'], 'test.'));

    expect($rows->pluck('key')->all())->toContain('sessions.catch_ups', 'crm.silent_quotes', 'finance.reminders', 'admissions.identified')
        ->and($rows->whereNull('count')->pluck('key')->all())->toBe([]);
});

it('sends the menu counter with the page: a deferred reload would wipe the validation errors the page shows', function () {
    $this->actingAs(admin(twoFactor: true))->get('/dashboard')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('todo_total')->where('todo_menu', true));
});
