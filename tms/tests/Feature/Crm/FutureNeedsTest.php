<?php

use App\Modules\Core\Access\Role;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Gdpr\PersonDataExport;
use App\Modules\Crm\Organisations\Organisation;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Europe/Paris'));
});

it('saves future needs with their date and author, 2000 characters at most', function () {
    $user = commercial();
    $hotel = Organisation::query()->create(['name' => 'Hôtel du Parc', 'responsible_user_id' => $user->id]);

    $this->actingAs($user)->put("/crm/organisations/{$hotel->id}/future-needs", ['future_needs' => 'Former deux serveurs au service en salle au printemps.'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $hotel->refresh();
    expect($hotel->future_needs)->toBe('Former deux serveurs au service en salle au printemps.')
        ->and($hotel->future_needs_updated_by)->toBe($user->id)
        ->and($hotel->future_needs_updated_at->setTimezone('Europe/Paris')->format('Y-m-d H:i'))->toBe('2026-11-20 10:00');

    $this->get("/crm/organisations/{$hotel->id}")->assertInertia(fn (Assert $p) => $p
        ->where('organisation.future_needs.text', 'Former deux serveurs au service en salle au printemps.')
        ->where('organisation.future_needs.updated_on', '20/11/2026')
        ->where('organisation.future_needs.updated_by', $user->name));

    $this->put("/crm/organisations/{$hotel->id}/future-needs", ['future_needs' => str_repeat('a', 2001)])
        ->assertSessionHasErrors(['future_needs' => 'Les besoins futurs tiennent en 2000 caractères au plus.']);

    // Emptied: no date nor author left.
    $this->put("/crm/organisations/{$hotel->id}/future-needs", ['future_needs' => ''])->assertSessionHasNoErrors();
    expect($hotel->fresh()->only(['future_needs', 'future_needs_updated_at', 'future_needs_updated_by']))
        ->toBe(['future_needs' => null, 'future_needs_updated_at' => null, 'future_needs_updated_by' => null]);

    // Without the right to edit the fiche: refused, nothing changed.
    $this->actingAs(userWithRole(Role::Formateur))->put("/crm/organisations/{$hotel->id}/future-needs", ['future_needs' => 'x'])->assertForbidden();
    expect($hotel->fresh()->future_needs)->toBeNull();
});

it('erases future needs of an anonymised person and exports them', function () {
    $user = commercial();
    $lea = makePerson('Durand', ['first_name' => 'Léa', 'responsible_user_id' => $user->id]);
    $this->actingAs($user)->put("/crm/people/{$lea->id}/future-needs", ['future_needs' => 'CAP cuisine en 2027.'])->assertSessionHasNoErrors();

    expect(PersonDataExport::for($lea->fresh())['identite']['besoins_futurs'])->toBe('CAP cuisine en 2027.');

    app(Anonymiser::class)->anonymise($lea->fresh(), 'request');
    expect($lea->fresh()->future_needs)->toBeNull();
});

it('never writes future needs of a person in clear in the audit log', function () {
    $user = commercial();
    $lea = makePerson('Durand', ['first_name' => 'Léa', 'responsible_user_id' => $user->id]);
    $this->actingAs($user)->put("/crm/people/{$lea->id}/future-needs", ['future_needs' => 'CAP cuisine en 2027.']);

    $logs = AuditLog::query()->where('auditable_id', $lea->id)->get()->map(fn ($l) => json_encode([$l->old_values, $l->new_values]))->implode(' ');
    expect($logs)->not->toContain('CAP cuisine');
});
