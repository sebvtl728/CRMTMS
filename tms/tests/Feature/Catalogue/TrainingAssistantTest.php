<?php

use App\Modules\Catalogue\Assistant\PdfText;
use App\Modules\Catalogue\Assistant\TrainingAssistant;
use App\Modules\Catalogue\Assistant\TrainingDraft;
use App\Modules\Catalogue\CatalogueServiceProvider;
use App\Modules\Catalogue\Permissions;
use App\Modules\Catalogue\Training;
use App\Modules\Connectors\Ai\Ai;
use App\Modules\Connectors\Ai\AiInvalidResponse;
use App\Modules\Connectors\Ai\AnthropicClient;
use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Core\HostPolicy;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceItem;
use App\Modules\Core\References\ReferenceList;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\E2eAiClient;

beforeEach(function () {
    CatalogueServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    HostPolicy::resolveUsing(fn (string $host): array => ['203.0.113.40']);
});
afterEach(fn () => HostPolicy::resolveUsing(null));

function assistantConnector(): ConnectorConfig
{
    tenant();
    $config = new ConnectorConfig(['connector_key' => 'ai', 'company_id' => null]);
    $config->fill(['enabled' => true, 'settings' => ['provider' => 'anthropic'], 'secrets' => ['api_key' => 'sk-test-'.'assistant0123456789']])->save();

    return $config;
}

/**
 * The AI's answer (Anthropic protocol) for a proposal.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function assistantAnswer(array $overrides = []): array
{
    $proposal = [
        'title' => 'Excel perfectionnement',
        'das' => 'Hôtellerie-restauration',
        'duration_hours' => 14.0,
        'duration_days' => 2,
        'description' => 'Aller plus loin avec Excel.',
        'objectives' => ['Construire un tableau croisé dynamique', 'Écrire des formules imbriquées'],
        'audience' => 'Utilisateurs réguliers d’Excel.',
        'prerequisites' => 'Connaître les bases d’Excel.',
        'methods' => 'Exercices sur poste.',
        'evaluation' => 'Cas pratique final.',
        'sanction' => 'Attestation de fin de formation.',
        'accessibility' => null,
        'access_terms' => null,
        'results' => null,
        'modules' => [
            ['title' => 'Tableaux croisés', 'duration_hours' => 7, 'modality' => 'présentiel', 'content' => 'Créer, filtrer, mettre en forme.'],
            ['title' => 'Formules', 'duration_hours' => 7.5, 'modality' => 'Téléportation', 'content' => null],
        ],
        ...$overrides,
    ];

    return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => json_encode($proposal, JSON_THROW_ON_ERROR)]]];
}

/** @param  array<string, mixed>|string  $answer */
function fakeAssistantAi(array|string $answer): void
{
    Http::swap((new Factory)->preventStrayRequests());
    Http::fake(['https://api.anthropic.com/*' => is_string($answer)
        ? Http::response(['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $answer]]])
        : Http::response($answer)]);
}

/** The text the AI received (user message). */
function sentToAssistant(): string
{
    $sent = Http::recorded()->first();
    expect($sent)->not->toBeNull();
    /** @var Request $request */
    $request = $sent[0];

    return (string) collect($request->data()['messages'])->pluck('content')->flatten()->implode("\n");
}

/**
 * A minimal valid PDF whose pages show the given lines (ASCII), so
 * pdftotext reads them back.
 *
 * @param  list<list<string>>  $pages
 */
function assistantPdf(array $pages): string
{
    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $kids = [];
    $next = 4;
    $bodies = [];
    foreach ($pages as $lines) {
        $stream = "BT /F1 9 Tf 10 TL 20 780 Td\n";
        foreach ($lines as $line) {
            $stream .= '('.addcslashes($line, '()\\').") Tj T*\n";
        }
        $stream .= 'ET';
        $pageId = $next++;
        $contentId = $next++;
        $kids[] = "{$pageId} 0 R";
        $bodies[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
        $bodies[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
    }
    $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects += $bodies;
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    return $pdf.'trailer
<< /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}

function programmePdf(string $name = 'programme.pdf', ?string $content = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content ?? assistantPdf([['Programme Excel perfectionnement', 'Module 1 : tableaux croises', 'Duree : 14 heures']]));
}

// ------------------------------------------------------------------ routes and access

it('offers two routes reserved to catalogue.manage, under the ai limiter', function () {
    foreach (['catalogue.assistant.pdf', 'catalogue.assistant.prompt'] as $name) {
        $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];
        expect($middleware)->toContain('can:catalogue.manage')->toContain('throttle:ai');
    }

    assistantConnector();
    fakeAssistantAi(assistantAnswer());
    $this->actingAs(userWithPermissions([Permissions::VIEW]))
        ->postJson('/catalogue/assistant/prompt', ['prompt' => 'Une formation Excel'])->assertForbidden();
    Http::assertNothingSent();
});

it('asks to configure the AI connector when none is active, without reading the file', function () {
    Http::swap((new Factory)->preventStrayRequests());
    $this->actingAs(admin(twoFactor: true));

    $this->postJson('/catalogue/assistant/prompt', ['prompt' => 'Une formation Excel de deux jours'])
        ->assertStatus(503)->assertJson(['message' => TrainingAssistant::NOT_CONFIGURED]);
    $this->postJson('/catalogue/assistant/pdf', ['file' => programmePdf()])
        ->assertStatus(503)->assertJson(['message' => 'Configurez l\'intelligence artificielle dans Administration → Connecteurs.']);
    Http::assertNothingSent();

    // The pages tell the screen whether the assistant can be used.
    $this->get('/catalogue/create')->assertInertia(fn (Assert $p) => $p->where('assistant.configured', false));
    assistantConnector();
    $this->get('/catalogue/create')->assertInertia(fn (Assert $p) => $p->where('assistant.configured', true));
});

// ------------------------------------------------------------------ from a request

it('proposes a training sheet from a written request, checked and mapped to the organisme\'s lists', function () {
    assistantConnector();
    fakeAssistantAi(assistantAnswer());
    $this->actingAs(admin(twoFactor: true));

    $response = $this->postJson('/catalogue/assistant/prompt', ['prompt' => 'Une formation Excel perfectionnement de deux jours'])->assertOk();

    expect($response->json('proposal'))->toMatchArray([
        'title' => 'Excel perfectionnement',
        'das_id' => crmItem(ReferenceList::Das, 'Hôtellerie-restauration')->id,
        'duration_hours' => '14',
        'duration_days' => '2',
        'description' => 'Aller plus loin avec Excel.',
        'objectives' => ['Construire un tableau croisé dynamique', 'Écrire des formules imbriquées'],
        'accessibility' => null,
        'results' => null,
    ])->and($response->json('proposal.modules'))->toBe([
        // « présentiel » (case, accents) is the organisme's « Présentiel »; an unknown modality is left empty.
        ['title' => 'Tableaux croisés', 'duration_hours' => '7', 'modality_id' => crmItem(ReferenceList::TrainingModality, 'Présentiel')->id, 'content' => 'Créer, filtrer, mettre en forme.'],
        ['title' => 'Formules', 'duration_hours' => '7,5', 'modality_id' => null, 'content' => null],
    ])->and($response->json('truncated'))->toBeFalse();

    expect(sentToAssistant())->toContain('Une formation Excel perfectionnement de deux jours');
    // Nothing is saved by the AI.
    expect(Training::query()->count())->toBe(0);
});

it('leaves a DAS or a modality empty when it is unknown or inactive', function () {
    assistantConnector();
    $dist = crmItem(ReferenceList::TrainingModality, 'Distanciel (classe virtuelle)');
    $dist->update(['is_active' => false]);
    fakeAssistantAi(assistantAnswer(['das' => 'Aérospatiale', 'modules' => [
        ['title' => 'A', 'duration_hours' => null, 'modality' => 'Distanciel (classe virtuelle)', 'content' => null],
        ['title' => 'B', 'duration_hours' => null, 'modality' => null, 'content' => null],
    ]]));
    $this->actingAs(admin(twoFactor: true));

    $response = $this->postJson('/catalogue/assistant/prompt', ['prompt' => 'Une formation'])->assertOk();
    expect($response->json('proposal.das_id'))->toBeNull()
        ->and($response->json('proposal.modules.0.modality_id'))->toBeNull()
        ->and($response->json('proposal.modules.1.modality_id'))->toBeNull();
});

it('refuses an empty request or one longer than 2 000 characters', function () {
    assistantConnector();
    fakeAssistantAi(assistantAnswer());
    $this->actingAs(admin(twoFactor: true));

    $this->postJson('/catalogue/assistant/prompt', ['prompt' => '  '])->assertStatus(422)->assertJsonValidationErrors('prompt');
    $this->postJson('/catalogue/assistant/prompt', ['prompt' => str_repeat('a', 2001)])
        ->assertStatus(422)->assertJsonValidationErrors(['prompt' => 'La demande ne doit pas dépasser 2 000 caractères.']);
    $this->postJson('/catalogue/assistant/prompt', ['prompt' => str_repeat('é', 2000)])->assertOk();
    expect(Http::recorded())->toHaveCount(1);
});

// ------------------------------------------------------------------ invalid answers (Review Focus 1)

it('refuses an unusable answer with a clear French message, nothing proposed', function (array|string $answer) {
    assistantConnector();
    fakeAssistantAi($answer);
    $this->actingAs(admin(twoFactor: true));

    $response = $this->postJson('/catalogue/assistant/prompt', ['prompt' => 'Une formation Excel'])
        ->assertStatus(502)->assertJson(['message' => AiInvalidResponse::MESSAGE]);
    expect($response->json('proposal'))->toBeNull();
})->with([
    'broken json' => ['{"title": "Excel", '],
    'title too long' => [assistantAnswer(['title' => str_repeat('a', 201)])],
    'text too long' => [assistantAnswer(['methods' => str_repeat('a', 5001)])],
    'objective too long' => [assistantAnswer(['objectives' => [str_repeat('a', 501)]])],
    'too many objectives' => [assistantAnswer(['objectives' => array_fill(0, 21, 'Savoir faire')])],
    'too many modules' => [assistantAnswer(['modules' => array_fill(0, 31, ['title' => 'M', 'duration_hours' => 1, 'modality' => null, 'content' => null])])],
    'zero hours' => [assistantAnswer(['duration_hours' => 0])],
    'too many hours' => [assistantAnswer(['duration_hours' => 2000.5])],
    'module of zero hours' => [assistantAnswer(['modules' => [['title' => 'M', 'duration_hours' => 0, 'modality' => null, 'content' => null]]])],
    'module without title' => [assistantAnswer(['modules' => [['title' => '', 'duration_hours' => 1, 'modality' => null, 'content' => null]]])],
    'wrong type' => [assistantAnswer(['objectives' => 'Savoir faire'])],
    'missing field' => [(function () {
        $answer = assistantAnswer();
        $decoded = json_decode($answer['content'][0]['text'], true);
        unset($decoded['title']);
        $answer['content'][0]['text'] = json_encode($decoded);

        return $answer;
    })()],
]);

it('reports an AI that cannot be reached with the fixed French message', function () {
    assistantConnector();
    Http::swap((new Factory)->preventStrayRequests());
    Http::fake(['https://api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded secret text']], 529)]);
    $this->actingAs(admin(twoFactor: true));

    $this->postJson('/catalogue/assistant/prompt', ['prompt' => 'Une formation Excel'])
        ->assertStatus(503)->assertJsonMissing(['message' => 'overloaded secret text']);
});

// ------------------------------------------------------------------ from a PDF

it('reads the text of a PDF on the server and sends only that text', function () {
    assistantConnector();
    fakeAssistantAi(assistantAnswer());
    $this->actingAs(admin(twoFactor: true));
    $file = programmePdf();
    $path = $file->getRealPath();

    $this->postJson('/catalogue/assistant/pdf', ['file' => $file])->assertOk()
        ->assertJsonPath('proposal.title', 'Excel perfectionnement')->assertJsonPath('truncated', false);

    $sent = sentToAssistant();
    expect($sent)->toContain('Programme Excel perfectionnement')->toContain('Module 1 : tableaux croises')
        ->not->toContain('%PDF')->not->toContain('/Helvetica');
    // No file kept after the analysis.
    expect(file_exists($path))->toBeFalse();
});

it('truncates the text of a long PDF to 60 000 characters and says so', function () {
    assistantConnector();
    fakeAssistantAi(assistantAnswer());
    $this->actingAs(admin(twoFactor: true));
    $line = str_repeat('Lorem ipsum dolor sit amet ', 3).'X';
    $pages = array_fill(0, 30, array_fill(0, 75, $line));

    $this->postJson('/catalogue/assistant/pdf', ['file' => programmePdf('long.pdf', assistantPdf($pages))])->assertOk()
        ->assertJsonPath('truncated', true);

    expect(PdfText::MAX_CHARS)->toBe(60000);
    $text = app(PdfText::class)->extract(programmePdf('long.pdf', assistantPdf($pages)));
    expect(mb_strlen($text->text))->toBe(60000)->and($text->truncated)->toBeTrue();
    expect(mb_strlen(sentToAssistant()))->toBeLessThan(60000 + 2000);
});

it('refuses a trapped, empty, textless or too large PDF before any AI call', function (Closure $file, string $message) {
    assistantConnector();
    fakeAssistantAi(assistantAnswer());
    $this->actingAs(admin(twoFactor: true));

    $this->postJson('/catalogue/assistant/pdf', ['file' => $file()])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => $message]);
    Http::assertNothingSent();
})->with([
    'renamed executable' => [fn () => programmePdf('programme.pdf', "MZ\x90\x00 not a pdf"), 'Le programme doit être un fichier PDF.'],
    'script with a pdf name' => [fn () => programmePdf('programme.pdf', "#!/bin/sh\nrm -rf /\n"), 'Le programme doit être un fichier PDF.'],
    'pdf signature, other extension' => [fn () => programmePdf('programme.exe'), 'Le programme doit être un fichier PDF.'],
    'empty file' => [fn () => programmePdf('programme.pdf', ''), 'Le fichier est vide.'],
    'too large' => [fn () => UploadedFile::fake()->create('programme.pdf', 10241, 'application/pdf'), 'Le programme ne doit pas dépasser 10 Mo.'],
    'no text (scan)' => [fn () => programmePdf('scan.pdf', assistantPdf([[]])), PdfText::NO_TEXT],
    'broken pdf' => [fn () => programmePdf('broken.pdf', "%PDF-1.4\n1 0 obj << garbage"), PdfText::UNREADABLE],
    'no file' => [fn () => null, 'Choisissez un fichier PDF.'],
]);

// ------------------------------------------------------------------ privacy

it('never writes the training text into the logs nor the journal', function () {
    assistantConnector();
    fakeAssistantAi(assistantAnswer());
    $logged = [];
    Log::listen(function ($event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });
    $this->actingAs(admin(twoFactor: true));

    $this->postJson('/catalogue/assistant/prompt', ['prompt' => 'Formation confidentielle Dupont'])->assertOk();
    $this->postJson('/catalogue/assistant/pdf', ['file' => programmePdf()])->assertOk();

    $runs = ConnectorRun::query()->get();
    expect($runs)->toHaveCount(2)
        ->and($runs->pluck('summary')->implode(' '))->toContain('training.from_prompt')->toContain('training.from_pdf')
        ->not->toContain('Dupont')->not->toContain('Excel');
    expect(implode("\n", $logged))->not->toContain('Dupont')->not->toContain('Programme Excel');
});

it('lists the organisme\'s active DAS and modalities in the instructions', function () {
    assistantConnector();
    fakeAssistantAi(assistantAnswer());
    crmItem(ReferenceList::TrainingModality, 'Distanciel (classe virtuelle)')->update(['is_active' => false]);
    $this->actingAs(admin(twoFactor: true));

    $this->postJson('/catalogue/assistant/prompt', ['prompt' => 'Une formation'])->assertOk();

    /** @var Request $request */
    $request = Http::recorded()->first()[0];
    $system = json_encode($request->data()['system'] ?? '', JSON_UNESCAPED_UNICODE);
    expect($system)->toContain('Hôtellerie-restauration')->toContain('Présentiel')->not->toContain('Distanciel (classe virtuelle)');
    expect(TrainingDraft::schema()['properties']['modules']['maxItems'])->toBe(30);
    expect(ReferenceItem::query()->where('list', ReferenceList::TrainingModality->value)->count())->toBeGreaterThan(1);
});

// ------------------------------------------------------------------ offline AI of the end-to-end stack

it('uses the offline AI only when the end-to-end stack asks for it, never in production', function () {
    $resolve = function (): string {
        app()->forgetInstance(AnthropicClient::class);

        return get_class(app(AnthropicClient::class));
    };

    expect($resolve())->toBe(AnthropicClient::class);

    config(['tms.connectors.e2e_fake_ai' => true]);
    expect($resolve())->toBe(E2eAiClient::class);

    app()->detectEnvironment(fn () => 'production');
    try {
        expect($resolve())->toBe(AnthropicClient::class);
    } finally {
        app()->detectEnvironment(fn () => 'testing');
        app()->forgetInstance(AnthropicClient::class);
    }
});

it('proposes the fixed sheet of the offline AI through the usual checks', function () {
    config(['tms.connectors.e2e_fake_ai' => true]);
    app()->forgetInstance(AnthropicClient::class);
    app()->forgetInstance(Ai::class);
    app()->forgetInstance(TrainingAssistant::class);
    Http::swap((new Factory)->preventStrayRequests());
    assistantConnector();
    $this->actingAs(admin(twoFactor: true));

    $this->postJson('/catalogue/assistant/prompt', ['prompt' => 'Une formation accueil'])->assertOk()
        ->assertJsonPath('proposal.title', E2eAiClient::TITLE)
        ->assertJsonPath('proposal.modules.0.modality_id', crmItem(ReferenceList::TrainingModality, 'Présentiel')->id)
        ->assertJsonPath('proposal.modules.1.modality_id', null);
    Http::assertNothingSent();
    app()->forgetInstance(AnthropicClient::class);
    app()->forgetInstance(Ai::class);
});
