<?php

use App\Modules\Core\Access\AccessScope;
use App\Modules\Core\Access\Permissions as CorePermissions;
use App\Modules\Core\Access\Role;
use App\Modules\Core\Access\Scopes;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Core\References\ReferenceUsage;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Planning\SeanceRows;
use App\Modules\Sessions\Trainers\Trainer;
use App\Modules\Sessions\Trainers\TrainerDas;
use App\Modules\Sessions\Trainers\TrainerRows;
use App\Modules\Sessions\Trainers\Trainers;
use App\Modules\Sessions\Trainers\TrainerStatus;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/lot2a.php';
require_once __DIR__.'/Support/planning.php';

beforeEach(function () {
    plSetUp();
});

function tdDas(string $label): ReferenceItem
{
    return crmItem(ReferenceList::Das, $label);
}

/**
 * A trainer named $name attached to the DAS labelled $das.
 *
 * @param  list<string>  $das
 */
function tdTrainer(string $name, array $das = []): Trainer
{
    $trainer = Trainers::declarePerson($name, 'Paul', null, TrainerStatus::Freelance);
    if ($das !== []) {
        Trainers::setDas($trainer, array_map(fn (string $l) => tdDas($l)->id, $das));
    }

    return $trainer;
}

/**
 * A user of a custom role holding trainers.manage with $scope (null: no row, « all »).
 */
function tdUser(?AccessScope $scope, array $followed = []): User
{
    $user = userWithPermissions([Permissions::TRAINERS_MANAGE]);
    if ($scope !== null) {
        DB::table(Scopes::TABLE)->insert([
            'tenant_id' => tenant()->id, 'role_id' => $user->roles()->firstOrFail()->id,
            'permission' => Permissions::TRAINERS_MANAGE, 'scope' => $scope->value,
        ]);
        Scopes::forget();
    }
    foreach ($followed as $label) {
        followDas($user, tdDas($label));
    }

    return $user;
}

const TD_HOTEL = 'Hôtellerie-restauration';
const TD_SUPPORT = 'Métiers supports';

// ---------------------------------------------------------------- attach

it('attaches and detaches DAS on the trainer page, once each, journaled without personal data', function () {
    $trainer = tdTrainer('Martin');
    $manager = tdUser(null);

    $this->actingAs($manager)->put("/trainers/{$trainer->id}/das", ['das_ids' => [tdDas(TD_HOTEL)->id, tdDas(TD_SUPPORT)->id, tdDas(TD_HOTEL)->id]])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(TrainerDas::query()->where('trainer_id', $trainer->id)->count())->toBe(2);

    $this->actingAs($manager)->get("/trainers/{$trainer->id}")->assertInertia(fn (Assert $page) => $page
        ->where('trainer.das_ids', fn ($ids) => collect($ids)->sort()->values()->all() === collect([tdDas(TD_HOTEL)->id, tdDas(TD_SUPPORT)->id])->sort()->values()->all())
        ->has('dasOptions', 4));

    $this->actingAs($manager)->put("/trainers/{$trainer->id}/das", ['das_ids' => [tdDas(TD_SUPPORT)->id]])->assertRedirect();
    expect(TrainerDas::query()->where('trainer_id', $trainer->id)->pluck('das_id')->all())->toBe([tdDas(TD_SUPPORT)->id]);

    $this->actingAs($manager)->put("/trainers/{$trainer->id}/das", ['das_ids' => []])->assertRedirect();
    expect(TrainerDas::query()->count())->toBe(0);
    expect(json_encode(DB::table('audit_logs')->where('auditable_type', TrainerDas::MORPH_ALIAS)->get()))->not->toContain('Martin');
});

it('refuses an unknown, inactive or foreign DAS value in French', function () {
    $trainer = tdTrainer('Martin');
    $manager = tdUser(null);
    $inactive = tdDas(TD_SUPPORT);
    $inactive->update(['is_active' => false]);
    $other = crmItem(ReferenceList::Priority, 'Urgent');

    foreach ([[(string) Str::uuid7()], [$inactive->id], [$other->id], ['x']] as $bad) {
        $this->actingAs($manager)->put("/trainers/{$trainer->id}/das", ['das_ids' => $bad])->assertSessionHasErrors('das_ids.0');
    }
    expect(TrainerDas::query()->count())->toBe(0);
});

it('does not delete a DAS value a trainer is attached to (ReferenceUsage)', function () {
    $trainer = tdTrainer('Martin');
    $das = tdDas(TD_HOTEL);
    Trainers::setDas($trainer, [tdDas(TD_SUPPORT)->id]);
    $used = tdDas(TD_SUPPORT);
    $free = tdDas('Compétences transversales');

    expect(ReferenceUsage::isInUse($used))->toBeTrue()->and(ReferenceUsage::isInUse($free))->toBeFalse();
});

// ---------------------------------------------------------------- order of options

it('proposes the trainers of the DAS of the session first, then the others, by name', function () {
    $session = plSession('2026-09-01', '2026-09-30');
    $module = plModule($session);
    $fits = tdTrainer('Zola', [TD_HOTEL]);
    $both = tdTrainer('Bernard', [TD_SUPPORT, TD_HOTEL]);
    $none = tdTrainer('Adam');
    $other = tdTrainer('Aubert', [TD_SUPPORT]);

    $options = SeanceRows::options($session)['trainers'];
    expect(array_column($options, 'value'))->toBe([$both->id, $fits->id, $none->id, $other->id])
        ->and(array_column($options, 'das_match'))->toBe([true, true, false, false]);

    $this->actingAs(plManager())->get("/sessions/{$session->id}")->assertInertia(fn (Assert $page) => $page
        ->where('planning.options.trainers.0.value', $both->id)
        ->where('planning.options.trainers.1.das_match', true)
        ->where('planning.options.trainers.3.das_match', false));
});

it('lists every active trainer in the options even outside the manager scope of trainers', function () {
    $session = plSession();
    plModule($session);
    tdTrainer('Aubert', [TD_SUPPORT]);

    // The planner does not even hold sessions.trainers.manage.
    expect(SeanceRows::options($session)['trainers'])->toHaveCount(1);
});

// ---------------------------------------------------------------- filter

it('filters the trainers by DAS (Trainers::ofDas and ?das=)', function () {
    $hotel = tdTrainer('Martin', [TD_HOTEL]);
    tdTrainer('Aubert', [TD_SUPPORT]);
    tdTrainer('Sans');

    expect(Trainers::ofDas(tdDas(TD_HOTEL)->id)->pluck('id')->all())->toBe([$hotel->id])
        ->and(Trainers::ofDas(null)->count())->toBe(3);

    $this->actingAs(tdUser(null))->get('/trainers?das='.tdDas(TD_HOTEL)->id)->assertInertia(fn (Assert $page) => $page
        ->has('trainers', 1)->where('trainers.0.id', $hotel->id)->where('das', tdDas(TD_HOTEL)->id)->has('dasOptions', 4));
    $this->actingAs(tdUser(null))->get('/trainers')->assertInertia(fn (Assert $page) => $page->has('trainers', 3)->where('das', null));
    $this->actingAs(tdUser(null))->get('/trainers?das=pas-un-uuid')->assertInertia(fn (Assert $page) => $page->has('trainers', 3)->where('das', null));
});

// ---------------------------------------------------------------- scope

it('shows a DAS-scoped manager the trainers of his DAS and those without DAS, 404 on the others', function () {
    $mine = tdTrainer('Martin', [TD_HOTEL]);
    $both = tdTrainer('Mixte', [TD_HOTEL, TD_SUPPORT]);
    $free = tdTrainer('Sans');
    $foreign = tdTrainer('Aubert', [TD_SUPPORT]);
    $user = tdUser(AccessScope::Das, [TD_HOTEL]);

    $this->actingAs($user)->get('/trainers')->assertInertia(fn (Assert $page) => $page
        ->has('trainers', 3)
        ->where('trainers', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$mine->id, $both->id, $free->id])->sort()->values()->all()));
    foreach ([$mine, $both, $free] as $t) {
        $this->actingAs($user)->get("/trainers/{$t->id}")->assertOk();
    }
    $this->actingAs($user)->get("/trainers/{$foreign->id}")->assertNotFound();
    $this->actingAs($user)->put("/trainers/{$foreign->id}", ['status' => 'employee', 'active' => true])->assertNotFound();
    $this->actingAs($user)->delete("/trainers/{$foreign->id}")->assertNotFound();
    $this->actingAs($user)->put("/trainers/{$foreign->id}/das", ['das_ids' => []])->assertNotFound();
    expect(TrainerDas::query()->where('trainer_id', $foreign->id)->count())->toBe(1);
});

it('shows an own-scoped manager the trainers he created or is linked to', function () {
    $user = tdUser(AccessScope::Own);
    $created = tdTrainer('Martin');
    DB::table('trainers')->where('id', $created->id)->update(['created_by' => $user->id]);
    $linked = tdTrainer('Lié');
    DB::table('trainers')->where('id', $linked->id)->update(['user_id' => $user->id]);
    $stranger = tdTrainer('Autre');

    $this->actingAs($user)->get('/trainers')->assertInertia(fn (Assert $page) => $page->has('trainers', 2));
    $this->actingAs($user)->get("/trainers/{$stranger->id}")->assertNotFound();
    $this->actingAs($user)->get("/trainers/{$linked->id}")->assertOk();
});

it('shows everything to the whole-organisme scope, explicit or default (no row)', function () {
    tdTrainer('Martin', [TD_HOTEL]);
    tdTrainer('Aubert', [TD_SUPPORT]);

    $this->actingAs(tdUser(AccessScope::All))->get('/trainers')->assertInertia(fn (Assert $page) => $page->has('trainers', 2));
    $this->actingAs(tdUser(null))->get('/trainers')->assertInertia(fn (Assert $page) => $page->has('trainers', 2));
});

it('hides the Formateur block of a person fiche when the trainer is out of scope', function () {
    $foreign = tdTrainer('Aubert', [TD_SUPPORT]);
    $user = tdUser(AccessScope::Das, [TD_HOTEL]);

    expect(TrainerRows::personBlock($foreign->person_id, $user))->toBeNull();
    Trainers::setDas($foreign, [tdDas(TD_HOTEL)->id]);
    expect(TrainerRows::personBlock($foreign->person_id, $user))->not->toBeNull();
});

it('makes trainers.manage a scoped right: Responsable de DAS on his DAS, other roles on everything', function () {
    expect(CorePermissions::isScoped(Permissions::TRAINERS_MANAGE))->toBeTrue()
        ->and(CorePermissions::isScoped(Permissions::ROOMS_MANAGE))->toBeFalse()
        ->and(Permissions::defaultScopesFor(Role::ResponsableDas)[Permissions::TRAINERS_MANAGE] ?? null)->toBe(AccessScope::Das)
        ->and(Permissions::defaultScopesFor(Role::Direction)[Permissions::TRAINERS_MANAGE] ?? null)->toBeNull()
        ->and(Permissions::defaultScopesFor(Role::Assistant)[Permissions::TRAINERS_MANAGE] ?? null)->toBeNull();
});

// ---------------------------------------------------------------- RLS

it('keeps the DAS of the trainers of each organisme apart (RLS)', function () {
    $other = Tenant::factory()->create();
    app(TenantContext::class)->run($other, function () {
        $trainer = Trainer::query()->create(['person_id' => makePerson('Ailleurs')->id, 'status' => 'employee']);
        $das = ReferenceItem::query()->create(['list' => ReferenceList::Das, 'code' => 'x', 'label' => 'Ailleurs DAS', 'position' => 1]);
        TrainerDas::query()->create(['trainer_id' => $trainer->id, 'das_id' => $das->id]);
    });
    inTenant();

    expect(TrainerDas::query()->count())->toBe(0);
    $trainer = tdTrainer('Martin');
    $foreignDas = app(TenantContext::class)->run($other, fn () => ReferenceItem::query()->where('label', 'Ailleurs DAS')->firstOrFail());
    $this->actingAs(tdUser(null))->put("/trainers/{$trainer->id}/das", ['das_ids' => [$foreignDas->id]])->assertSessionHasErrors('das_ids.0');
});
