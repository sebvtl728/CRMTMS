<?php

use App\Modules\Crm\Import\ImportTarget;
use Illuminate\Support\Facades\Route;

const FR_THROTTLE = 'Trop de requêtes, réessayez dans quelques instants.';

it('does not let import progress polling throttle the Sirene lookup or the search', function () {
    fakeImportDisk();
    $user = commercial();
    $this->actingAs($user);
    $batch = uploadImport("Raison sociale;Ville\nHôtel du Lac;Annecy\n", ImportTarget::Organisations);

    for ($i = 0; $i < 100; $i++) {
        $this->getJson("/crm/import/{$batch->id}/status")->assertOk();
    }

    $this->getJson('/crm/search?q=zz')->assertOk();
    expect($this->getJson('/crm/sirene/00000000000000')->status())->not->toBe(429);
});

it('gives every plain-throttled route a named limiter', function () {
    $plain = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && preg_match('/^throttle:\d+/', $m)))
        ->map(fn ($route) => $route->uri())
        ->values()->all();

    expect($plain)->toBe([]);
});

it('answers a throttled JSON request in french', function () {
    $this->actingAs(commercial());

    foreach (range(1, 60) as $i) {
        $this->getJson('/crm/search?q=zz')->assertOk();
    }
    $this->getJson('/crm/search?q=zz')->assertStatus(429)->assertExactJson(['message' => FR_THROTTLE]);
});

it('answers a throttled page in french html', function () {
    foreach (range(1, 10) as $i) {
        $this->get('/activation/'.str_repeat('a', 128));
    }
    $this->get('/activation/'.str_repeat('a', 128))->assertStatus(429)->assertSee('Trop de tentatives');
});

it('limits the connector test button to 5 a minute per user, in french', function () {
    $this->actingAs(admin(twoFactor: true));

    foreach (range(1, 5) as $i) {
        expect($this->postJson('/admin/connectors/smtp/test')->status())->not->toBe(429);
    }
    $this->postJson('/admin/connectors/smtp/test')->assertStatus(429)->assertExactJson(['message' => FR_THROTTLE]);
});
