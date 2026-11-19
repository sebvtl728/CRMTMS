<?php

use App\Modules\Admissions\OnlineTests\OnlineTest;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/admissions.php';
require_once __DIR__.'/Support.php';

beforeEach(fn () => adSetUp());

it('reserves the online tests to who manages the catalogue', function () {
    $this->actingAs(commercial())->get('/admissions/online-tests')->assertForbidden();
    $this->actingAs(admin(twoFactor: true))->get('/admissions/online-tests')->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('admissions/online-tests/index')->has('tests', 0));
});

it('creates a test, saves its draft and publishes it', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->post('/admissions/online-tests', ['title' => 'Test tertiaire'])->assertRedirect();
    $test = OnlineTest::query()->sole();
    expect($test->draft_content)->toBe(['sections' => []]);

    $this->putJson("/admissions/online-tests/{$test->id}", [
        'title' => 'Test tertiaire', 'time_limit_minutes' => 45, 'das_ids' => [adTraining()->das_id], 'training_ids' => [], 'content' => otContent(),
    ])->assertOk()->assertJsonPath('status', 'Brouillon enregistré.');

    $this->get("/admissions/online-tests/{$test->id}/edit")->assertInertia(fn (Assert $p) => $p
        ->component('admissions/online-tests/edit')
        ->where('test.title', 'Test tertiaire')
        ->where('test.time_limit_minutes', 45)
        ->has('test.content.sections', 2)
        ->where('test.published_version', null)
        ->has('options.das'));

    $this->post("/admissions/online-tests/{$test->id}/publish")->assertRedirect()->assertSessionHas('status', 'Test publié (version 1).');
    $this->get('/admissions/online-tests')->assertInertia(fn (Assert $p) => $p
        ->where('tests.0.title', 'Test tertiaire')->where('tests.0.published_version', 1)->where('tests.0.total_points', 20));
});

it('refuses an invalid draft with the french message of the question', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->post('/admissions/online-tests', ['title' => 'Test']);
    $test = OnlineTest::query()->sole();
    $content = otContent();
    $content['sections'][1]['questions'][0]['type'] = 'dessin';

    $this->putJson("/admissions/online-tests/{$test->id}", ['content' => $content])->assertUnprocessable()
        ->assertJsonValidationErrors(['sections.1.questions.0.type' => 'Type de question inconnu.']);
    $this->post("/admissions/online-tests/{$test->id}/publish")->assertSessionHasErrors(['content' => 'Ajoutez au moins une question avant de publier.']);
});
