<?php

use App\Modules\Admissions\OnlineTests\ImportOnlineTestFromPdf;
use App\Modules\Admissions\OnlineTests\OnlineTest;
use App\Modules\Connectors\Ai\AiClient;
use App\Modules\Connectors\Core\HostPolicy;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/admissions.php';
require_once __DIR__.'/../../Catalogue/Support/assistant.php';

beforeEach(function () {
    adSetUp();
    HostPolicy::resolveUsing(fn (string $host): array => ['203.0.113.40']);
});
afterEach(fn () => HostPolicy::resolveUsing(null));

function otLivret(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('livret.pdf', assistantPdf([[
        'Livret de recrutement et positionnement', 'MATHEMATIQUES COMMERCIALES', '1 h 45 min est egale a 1.75 centiemes : vrai ou faux', 'Que signifie TVA ?',
    ]]));
}

function otAiAnswer(): string
{
    return json_encode(['title' => 'Livret métiers support', 'sections' => [
        ['title' => 'Mathématiques commerciales', 'intro' => null, 'questions' => [
            ['type' => 'true_false', 'label' => '1 h 45 min est égale à 1,75 centièmes', 'support_text' => null, 'choices' => [], 'answer' => true],
            ['type' => 'short_text', 'label' => 'Que signifie TVA ?', 'support_text' => null, 'choices' => [], 'answer' => null],
            ['type' => 'dessin', 'label' => 'Tracez un diagramme', 'support_text' => null, 'choices' => [], 'answer' => null],
            ['type' => 'single_choice', 'label' => 'Durée de référence des congés payés', 'support_text' => null, 'choices' => ['30 jours ouvrables', '30 jours ouvrés'], 'answer' => null],
        ]],
    ]], JSON_THROW_ON_ERROR);
}

it('starts the import in the background and shows « Import en cours »', function () {
    Queue::fake();
    assistantConnector();
    $this->actingAs(admin(twoFactor: true));

    $this->post('/admissions/online-tests/from-pdf', ['file' => otLivret()])->assertRedirect()
        ->assertSessionHas('status', 'Import du PDF en cours : le brouillon apparaît ici dans quelques minutes.');

    $test = OnlineTest::query()->sole();
    expect($test->import_status)->toBe(OnlineTest::IMPORTING)->and($test->title)->toBe('Import de livret.pdf');
    Queue::assertPushedOn('imports', ImportOnlineTestFromPdf::class, fn (ImportOnlineTestFromPdf $job) => $job->testId === $test->id && str_contains($job->text, 'Que signifie TVA'));
    $this->get("/admissions/online-tests/{$test->id}/edit")->assertInertia(fn (Assert $p) => $p->where('test.import_status', 'importing'));
});

it('fills the draft from the ai, never with the right answers, with a long wait allowed', function () {
    assistantConnector();
    fakeAssistantAi(otAiAnswer());
    $this->actingAs(admin(twoFactor: true));

    $this->post('/admissions/online-tests/from-pdf', ['file' => otLivret()]); // the sync queue runs the job

    $test = OnlineTest::query()->sole();
    $questions = $test->draft_content['sections'][0]['questions'];
    expect($test->import_status)->toBeNull()
        ->and($test->title)->toBe('Livret métiers support')
        ->and($test->current_version_id)->toBeNull()
        ->and(array_column($questions, 'type'))->toBe(['true_false', 'short_text', 'single_choice'])
        ->and(array_column($questions, 'answer'))->toBe([null, null, null])
        ->and(sentToAssistant())->toContain('Que signifie TVA')
        ->and(ImportOnlineTestFromPdf::AI_TIMEOUT_SECONDS)->toBeGreaterThan(AiClient::TIMEOUT_SECONDS);
});

it('keeps the failure on the test when the ai does not answer', function () {
    assistantConnector();
    Http::swap((new Factory)->preventStrayRequests());
    Http::fake(['https://api.anthropic.com/*' => Http::response('', 503)]);
    $this->actingAs(admin(twoFactor: true));

    $this->post('/admissions/online-tests/from-pdf', ['file' => otLivret()]);

    $test = OnlineTest::query()->sole();
    expect($test->import_status)->toBe(OnlineTest::IMPORT_FAILED)->and($test->import_error)->not->toBeNull();
    $this->get("/admissions/online-tests/{$test->id}/edit")->assertInertia(fn (Assert $p) => $p->where('test.import_status', 'failed')->where('test.import_error', $test->import_error));
});

it('asks to configure the ai connector first', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->post('/admissions/online-tests/from-pdf', ['file' => otLivret()])
        ->assertSessionHasErrors(['file' => 'Configurez l\'intelligence artificielle dans Administration → Connecteurs.']);
    expect(OnlineTest::query()->count())->toBe(0);
});
