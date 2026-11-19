<?php

use App\Modules\Admissions\OnlineTests\OnlineTestContent;
use App\Modules\Admissions\OnlineTests\OnlineTests;
use App\Modules\Admissions\OnlineTests\OnlineTestVersion;
use App\Modules\Core\References\ReferenceList;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/admissions.php';
require_once __DIR__.'/Support.php';

beforeEach(fn () => adSetUp());

it('validates the content of a test with french messages', function (string $path, mixed $value, string $key, string $message) {
    $content = otContent();
    data_set($content, $path, $value);
    try {
        OnlineTestContent::validate($content);
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors()[$key][0] ?? null)->toBe($message);
    }
})->with([
    'unknown type' => ['sections.1.questions.0.type', 'dessin', 'sections.1.questions.0.type', 'Type de question inconnu.'],
    'answer not a choice' => ['sections.1.questions.2.answer', 'zz', 'sections.1.questions.2.answer', 'La bonne réponse doit être un des choix.'],
    'negative points' => ['sections.1.questions.0.points', -1, 'sections.1.questions.0.points', 'Les points vont de 0 à 100.'],
    'number answer' => ['sections.1.questions.1.answer', 'trente', 'sections.1.questions.1.answer', 'La bonne réponse doit être un nombre.'],
    'no label' => ['sections.0.questions.0.label', '', 'sections.0.questions.0.label', 'Écrivez la question.'],
    'choice without choices' => ['sections.1.questions.2.choices', [], 'sections.1.questions.2.choices', 'Proposez au moins deux choix.'],
]);

it('publishes a frozen version with its total; later draft edits leave it unchanged', function () {
    $test = otPublished();
    $v1 = OnlineTestVersion::query()->findOrFail($test->current_version_id);
    expect($v1->number)->toBe(1)->and($v1->total_points)->toBe(20);

    $content = otContent();
    $content['sections'][1]['questions'][4]['points'] = 5;
    app(OnlineTests::class)->update($test, ['title' => 'Test tertiaire', 'content' => $content], admin());
    expect($v1->fresh()->total_points)->toBe(20)->and($test->fresh()->current_version_id)->toBe($v1->id);

    app(OnlineTests::class)->publish($test->fresh(), admin());
    $v2 = OnlineTestVersion::query()->findOrFail($test->fresh()->current_version_id);
    expect($v2->number)->toBe(2)->and($v2->total_points)->toBe(15)->and($v1->fresh()->content['sections'][1]['questions'][4]['points'])->toBe(10);
});

it('offers the published tests of a training or a das, never a draft or a trashed one', function () {
    $published = otPublished();
    $draft = app(OnlineTests::class)->create(['title' => 'Brouillon', 'das_ids' => [adTraining()->das_id], 'training_ids' => [], 'content' => otContent()], admin());
    $other = otPublished(['title' => 'Autre', 'das_ids' => [crmItem(ReferenceList::Das, 'Métiers supports')->id]]);
    $trashed = otPublished(['title' => 'Corbeille']);
    app(OnlineTests::class)->trash($trashed);

    $tests = app(OnlineTests::class);
    expect(collect($tests->offered(adTraining()->id, null))->pluck('title')->all())->toBe(['Test tertiaire'])
        ->and(collect($tests->offered(null, adTraining()->das_id))->pluck('id')->all())->toBe([$published->id])
        ->and($tests->offered(null, null))->toBe([]);
    expect($draft)->not->toBeNull()->and($other)->not->toBeNull();
});
