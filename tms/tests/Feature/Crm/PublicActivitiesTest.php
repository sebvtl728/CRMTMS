<?php

use App\Modules\Core\Access\PermissionSync;
use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Crm\Activities\Activity;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Gdpr\Anonymiser;
use App\Modules\Crm\Organisations\Organisation;
use App\Modules\Crm\Public\Activities;
use Carbon\CarbonImmutable;

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    $this->travelTo(CarbonImmutable::parse('2026-11-20 06:30', 'Europe/Paris'));
});

it('plans a call on a live fiche for the given user, due at the given time, visible in his « À faire »', function () {
    PermissionSync::run();
    $trainer = userWithRole(Role::Formateur);
    $lea = makePerson('Durand', ['first_name' => 'Léa']);

    $id = Activities::planCall('person', $lea->id, $trainer->id, 'Suivi à 3 mois : Léa Durand, HACCP (S-2026-0005)', CarbonImmutable::parse('2026-11-20 09:00', 'Europe/Paris'));

    $activity = Activity::query()->findOrFail($id);
    expect($activity->person_id)->toBe($lea->id)
        ->and($activity->responsible_user_id)->toBe($trainer->id)
        ->and($activity->subject)->toBe('Suivi à 3 mois : Léa Durand, HACCP (S-2026-0005)')
        ->and($activity->done_at)->toBeNull()
        ->and($activity->due_at->setTimezone('Europe/Paris')->format('Y-m-d H:i'))->toBe('2026-11-20 09:00')
        ->and($activity->type->meta['planning'] ?? null)->toBe('call');

    $sections = $this->actingAs($trainer)->get('/crm/todo')->assertOk()->original->getData()['page']['props']['sections'];
    expect(collect($sections)->flatten(1)->pluck('subject')->all())->toContain('Suivi à 3 mois : Léa Durand, HACCP (S-2026-0005)');
});

it('plans nothing on a trashed or anonymised fiche', function () {
    $user = commercial();
    $trashed = Organisation::query()->create(['name' => 'Hôtel du Parc']);
    $trashed->delete();
    $anonymised = makePerson('Martin', ['first_name' => 'Claire']);
    app(Anonymiser::class)->anonymise($anonymised, 'request');

    expect(Activities::planCall('organisation', $trashed->id, $user->id, 'Réactivation', CarbonImmutable::now()))->toBeNull()
        ->and(Activities::planCall('person', $anonymised->id, $user->id, 'Réactivation', CarbonImmutable::now()))->toBeNull()
        ->and(Activity::query()->count())->toBe(0);
});
