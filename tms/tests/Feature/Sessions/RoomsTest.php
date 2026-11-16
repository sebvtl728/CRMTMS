<?php

use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Core\Tenancy\TenantContext;
use App\Modules\Core\Users\User;
use App\Modules\Sessions\Permissions;
use App\Modules\Sessions\Resources\ClosurePeriod;
use App\Modules\Sessions\Resources\ClosurePeriods;
use App\Modules\Sessions\Resources\Room;
use App\Modules\Sessions\Resources\Rooms;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    inTenant();
});

function roomsManager(): User
{
    return userWithPermissions([Permissions::ROOMS_MANAGE]);
}

// ---------------------------------------------------------------- rooms

it('creates, changes and lists the rooms of the organisme', function () {
    $user = roomsManager();

    $this->actingAs($user)->post('/admin/sessions-settings/rooms', ['name' => ' Salle Bocuse ', 'capacity' => '12', 'address' => '3 rue des Lilas, Brest', 'active' => true])
        ->assertRedirect()->assertSessionHasNoErrors();
    $room = Room::query()->sole();
    expect($room->name)->toBe('Salle Bocuse')->and($room->capacity)->toBe(12)->and($room->active)->toBeTrue();

    $this->actingAs($user)->put("/admin/sessions-settings/rooms/{$room->id}", ['name' => 'Salle Escoffier', 'capacity' => '', 'address' => '', 'active' => false])
        ->assertRedirect()->assertSessionHasNoErrors();
    $room->refresh();
    expect($room->name)->toBe('Salle Escoffier')->and($room->capacity)->toBeNull()->and($room->address)->toBeNull()->and($room->active)->toBeFalse();

    Room::query()->create(['name' => 'Atelier', 'capacity' => 8]);
    $this->actingAs($user)->get('/admin/sessions-settings')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/sessions-settings')
        ->has('rooms', 2)
        ->where('rooms.0.name', 'Atelier')
        ->where('rooms.1.name', 'Salle Escoffier')
        ->where('rooms.1.active', false));
});

it('validates a room in French', function () {
    $this->actingAs(roomsManager())->post('/admin/sessions-settings/rooms', ['name' => '', 'capacity' => '0', 'address' => str_repeat('a', 501)])
        ->assertSessionHasErrors([
            'name' => 'Indiquez le nom de la salle.',
            'capacity' => 'La capacité doit être un nombre entier supérieur à 0.',
            'address' => "L'adresse ne doit pas dépasser 500 caractères.",
        ]);
    $this->actingAs(roomsManager())->post('/admin/sessions-settings/rooms', ['name' => str_repeat('a', 121)])
        ->assertSessionHasErrors(['name' => 'Le nom ne doit pas dépasser 120 caractères.']);
    expect(Room::query()->count())->toBe(0);
});

it('refuses the rooms to whoever lacks sessions.rooms.manage', function () {
    $room = Room::query()->create(['name' => 'Salle A']);
    $user = userWithPermissions([Permissions::SESSIONS_UPDATE]);

    $this->actingAs($user)->get('/admin/sessions-settings')->assertForbidden();
    $this->actingAs($user)->post('/admin/sessions-settings/rooms', ['name' => 'B'])->assertForbidden();
    $this->actingAs($user)->delete("/admin/sessions-settings/rooms/{$room->id}")->assertForbidden();
    expect(Room::query()->count())->toBe(1);
});

it('deletes an unused room, never a used one (deactivate it instead)', function () {
    $used = Room::query()->create(['name' => 'Salle utilisée']);
    $free = Room::query()->create(['name' => 'Salle libre']);
    Rooms::registerUsage('test.seances', fn (string $roomId): bool => $roomId === $used->id);

    $this->actingAs(roomsManager())->delete("/admin/sessions-settings/rooms/{$used->id}")
        ->assertStatus(409)->assertSee('Cette salle est utilisée : désactivez-la plutôt que de la supprimer.');
    $this->actingAs(roomsManager())->delete("/admin/sessions-settings/rooms/{$free->id}")->assertRedirect();

    expect(Room::query()->pluck('name')->all())->toBe(['Salle utilisée'])
        ->and(Room::withTrashed()->find($free->id)?->trashed())->toBeTrue();
});

it('gives the active rooms by name', function () {
    Room::query()->create(['name' => 'Salle B']);
    Room::query()->create(['name' => 'salle a']);
    Room::query()->create(['name' => 'Salle fermée', 'active' => false]);
    Room::query()->create(['name' => 'Salle supprimée'])->delete();

    expect(Rooms::active()->pluck('name')->all())->toBe(['salle a', 'Salle B']);
});

it('keeps the rooms of each organisme apart (RLS)', function () {
    $other = Tenant::factory()->create();
    $foreign = app(TenantContext::class)->run($other, fn () => Room::query()->create(['name' => 'Salle ailleurs']));
    inTenant();

    expect(Room::query()->count())->toBe(0);
    $this->actingAs(roomsManager())->put("/admin/sessions-settings/rooms/{$foreign->id}", ['name' => 'Piratée'])->assertNotFound();
    $this->actingAs(roomsManager())->delete('/admin/sessions-settings/rooms/not-a-uuid')->assertNotFound();
    expect(app(TenantContext::class)->run($other, fn () => Room::query()->sole()->name))->toBe('Salle ailleurs');
});

// ---------------------------------------------------------------- closure periods

it('creates, changes and deletes the closure periods', function () {
    $user = roomsManager();

    $this->actingAs($user)->post('/admin/sessions-settings/closures', ['starts_on' => '2026-12-24', 'ends_on' => '2027-01-02', 'label' => 'Fermeture de fin d’année'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $closure = ClosurePeriod::query()->sole();
    expect($closure->starts_on->toDateString())->toBe('2026-12-24')->and($closure->label)->toBe('Fermeture de fin d’année');

    $this->actingAs($user)->put("/admin/sessions-settings/closures/{$closure->id}", ['starts_on' => '2026-12-23', 'ends_on' => '2027-01-01', 'label' => 'Noël'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($closure->refresh()->starts_on->toDateString())->toBe('2026-12-23')->and($closure->label)->toBe('Noël');

    $this->actingAs($user)->get('/admin/sessions-settings')->assertInertia(fn (Assert $page) => $page
        ->has('closures', 1)->where('closures.0.label', 'Noël')->where('closures.0.starts_on', '2026-12-23'));

    $this->actingAs($user)->delete("/admin/sessions-settings/closures/{$closure->id}")->assertRedirect();
    expect(ClosurePeriod::query()->count())->toBe(0);
});

it('validates a closure period in French', function () {
    $this->actingAs(roomsManager())->post('/admin/sessions-settings/closures', ['starts_on' => '2027-01-10', 'ends_on' => '2027-01-09', 'label' => ''])
        ->assertSessionHasErrors([
            'ends_on' => 'La fin doit être le même jour que le début ou après.',
            'label' => "Indiquez l'intitulé de la fermeture.",
        ]);
    $this->actingAs(roomsManager())->post('/admin/sessions-settings/closures', ['starts_on' => 'demain', 'ends_on' => '', 'label' => str_repeat('a', 121)])
        ->assertSessionHasErrors([
            'starts_on' => 'Indiquez une date de début valide.',
            'ends_on' => 'Indiquez la date de fin.',
            'label' => "L'intitulé ne doit pas dépasser 120 caractères.",
        ]);
    expect(ClosurePeriod::query()->count())->toBe(0);
});

it('finds the closure covering a day, bounds included', function () {
    ClosurePeriod::query()->create(['starts_on' => '2026-12-24', 'ends_on' => '2027-01-02', 'label' => 'Fin d’année']);
    ClosurePeriod::query()->create(['starts_on' => '2027-08-01', 'ends_on' => '2027-08-15', 'label' => 'Été']);

    expect(ClosurePeriods::covering(CarbonImmutable::parse('2026-12-24'))?->label)->toBe('Fin d’année')
        ->and(ClosurePeriods::covering(CarbonImmutable::parse('2027-01-02'))?->label)->toBe('Fin d’année')
        ->and(ClosurePeriods::covering(CarbonImmutable::parse('2027-01-03')))->toBeNull()
        ->and(ClosurePeriods::covering(CarbonImmutable::parse('2027-08-10', 'Europe/Paris'))?->label)->toBe('Été');
});

it('keeps the closure periods of each organisme apart (RLS)', function () {
    $other = Tenant::factory()->create();
    $foreign = app(TenantContext::class)->run($other, fn () => ClosurePeriod::query()->create(['starts_on' => '2027-01-01', 'ends_on' => '2027-01-01', 'label' => 'Ailleurs']));
    inTenant();

    expect(ClosurePeriods::covering(CarbonImmutable::parse('2027-01-01')))->toBeNull();
    $this->actingAs(roomsManager())->delete("/admin/sessions-settings/closures/{$foreign->id}")->assertNotFound();
});
