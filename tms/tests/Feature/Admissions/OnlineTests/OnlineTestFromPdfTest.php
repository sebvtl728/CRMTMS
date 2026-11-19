<?php

use App\Modules\Admissions\OnlineTests\OnlineTest;
use App\Modules\Connectors\Core\HostPolicy;
use Illuminate\Http\UploadedFile;

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

it('drafts an online test from a pdf: sections and questions, never the right answers', function () {
    assistantConnector();
    fakeAssistantAi(json_encode(['title' => 'Livret métiers support', 'sections' => [
        ['title' => 'Mathématiques commerciales', 'intro' => null, 'questions' => [
            ['type' => 'true_false', 'label' => '1 h 45 min est égale à 1,75 centièmes', 'support_text' => null, 'choices' => [], 'answer' => true],
            ['type' => 'short_text', 'label' => 'Que signifie TVA ?', 'support_text' => null, 'choices' => [], 'answer' => null],
            ['type' => 'dessin', 'label' => 'Tracez un diagramme', 'support_text' => null, 'choices' => [], 'answer' => null],
            ['type' => 'single_choice', 'label' => 'Durée de référence des congés payés', 'support_text' => null, 'choices' => ['30 jours ouvrables', '30 jours ouvrés'], 'answer' => null],
        ]],
    ]], JSON_THROW_ON_ERROR));
    $this->actingAs(admin(twoFactor: true));

    $this->post('/admissions/online-tests/from-pdf', ['file' => otLivret()])->assertRedirect()
        ->assertSessionHas('status', 'Brouillon créé depuis le PDF : relisez les questions et ajoutez les bonnes réponses avant de publier.');

    $test = OnlineTest::query()->sole();
    $questions = $test->draft_content['sections'][0]['questions'];
    expect($test->title)->toBe('Livret métiers support')
        ->and($test->current_version_id)->toBeNull()
        ->and(array_column($questions, 'type'))->toBe(['true_false', 'short_text', 'single_choice'])
        ->and(array_column($questions, 'answer'))->toBe([null, null, null])
        ->and(array_column($questions[2]['choices'], 'label'))->toBe(['30 jours ouvrables', '30 jours ouvrés'])
        ->and(sentToAssistant())->toContain('Que signifie TVA');
});

it('asks to configure the ai connector first', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->post('/admissions/online-tests/from-pdf', ['file' => otLivret()])
        ->assertSessionHasErrors(['file' => 'Configurez l\'intelligence artificielle dans Administration → Connecteurs.']);
    expect(OnlineTest::query()->count())->toBe(0);
});
