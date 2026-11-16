<?php

use App\Modules\Connectors\Ai\Ai;
use App\Modules\Connectors\Ai\AiConnector;
use App\Modules\Connectors\Ai\AiEndpoint;
use App\Modules\Connectors\Ai\AiInvalidResponse;
use App\Modules\Connectors\Ai\AiUnavailable;
use App\Modules\Connectors\Ai\AnthropicClient;
use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorRegistry;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Core\HostPolicy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

const AI_PROVIDER_TEXT = 'invalid x-api-key: request id req_011CSecretProviderText';
const AI_PROMPT = 'Programme confidentiel : formation Excel avancé pour Mme Dupont';

beforeEach(function () {
    HostPolicy::resolveUsing(fn (string $host): array => match ($host) {
        'ia.lan' => ['192.168.1.20'],
        'other.lan' => ['192.168.1.30'],
        'metadata.lan' => ['169.254.169.254'],
        'localhost' => ['127.0.0.1'],
        default => ['203.0.113.40'],
    });
});
afterEach(fn () => HostPolicy::resolveUsing(null));

/** @param  array<string, mixed>  $stubs */
function aiHttp(array $stubs): void
{
    Http::swap((new Factory)->preventStrayRequests());
    Http::fake($stubs);
}

/** @param  array<string, mixed>  $settings */
function aiConfig(array $settings = [], ?string $key = 'sk-test-'.'0123456789abcdef', bool $enabled = true): ConnectorConfig
{
    tenant();
    $config = new ConnectorConfig(['connector_key' => 'ai', 'company_id' => null]);
    $config->fill(['enabled' => $enabled, 'settings' => ['provider' => 'anthropic', ...$settings], 'secrets' => $key === null ? null : ['api_key' => $key]])->save();

    return $config;
}

function aiLocalConfig(bool $local = true, string $baseUrl = 'http://192.168.1.20:11434/v1'): ConnectorConfig
{
    return aiConfig(['provider' => 'openai_compatible', 'base_url' => $baseUrl, 'model' => 'llama3.1:8b', 'local_network' => $local], key: null);
}

/** @return array<string, mixed> */
function aiSchema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string', 'maxLength' => 20],
            'hours' => ['type' => 'number', 'minimum' => 0],
        ],
        'required' => ['title', 'hours'],
        'additionalProperties' => false,
    ];
}

/** @return array<string, mixed> */
function anthropicAnswer(string $text, string $stop = 'end_turn'): array
{
    return ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => $stop, 'content' => [['type' => 'text', 'text' => $text]]];
}

/** @return array<string, mixed> */
function openAiAnswer(string $content, string $finish = 'stop'): array
{
    return ['id' => 'chatcmpl-1', 'choices' => [['index' => 0, 'finish_reason' => $finish, 'message' => ['role' => 'assistant', 'content' => $content]]]];
}

function aiCall(): array
{
    return app(Ai::class)->structured('Tu extrais une formation.', AI_PROMPT, aiSchema(), 'training.from_pdf');
}

// --- Anthropic ---------------------------------------------------------------

it('asks Anthropic for a structured answer and returns the decoded object', function () {
    aiConfig();
    $pinned = null;
    Http::swap((new Factory)->preventStrayRequests());
    Http::fake(['api.anthropic.com/v1/messages' => function (Request $request, array $options) use (&$pinned) {
        $pinned = $options['curl'][CURLOPT_RESOLVE] ?? null;

        return Http::response(anthropicAnswer('{"title":"Excel","hours":14}'));
    }]);

    expect(aiCall())->toBe(['title' => 'Excel', 'hours' => 14]);

    Http::assertSent(function (Request $r) {
        $body = $r->data();

        return $r->url() === 'https://api.anthropic.com/v1/messages'
            && $r->hasHeader('x-api-key', 'sk-test-0123456789abcdef')
            && $r->hasHeader('anthropic-version', AnthropicClient::API_VERSION)
            && $body['model'] === AnthropicClient::DEFAULT_MODEL
            && $body['system'] === 'Tu extrais une formation.'
            && $body['messages'] === [['role' => 'user', 'content' => AI_PROMPT]]
            && $body['max_tokens'] <= AnthropicClient::MAX_OUTPUT_TOKENS
            && $body['output_config']['format']['type'] === 'json_schema'
            // Constraints the API does not accept are checked here, not sent.
            && ! isset($body['output_config']['format']['schema']['properties']['title']['maxLength'])
            && $body['output_config']['format']['schema']['required'] === ['title', 'hours']
            && ! isset($body['temperature']);
    });
    expect($pinned)->toBe(['api.anthropic.com:443:203.0.113.40']);
});

it('uses the model chosen by the administrator', function () {
    aiConfig(['model' => 'claude-haiku-5-5']);
    aiHttp(['*' => Http::response(anthropicAnswer('{"title":"Excel","hours":7}'))]);

    aiCall();

    Http::assertSent(fn (Request $r) => $r->data()['model'] === 'claude-haiku-5-5');
});

it('turns an unusable Anthropic answer into AiInvalidResponse (broken json, wrong types, too long, refusal, truncated)', function (array $answer) {
    aiConfig();
    aiHttp(['*' => Http::response($answer)]);

    expect(fn () => aiCall())->toThrow(AiInvalidResponse::class, AiInvalidResponse::MESSAGE);
})->with([
    'broken json' => [anthropicAnswer('{"title":"Excel",')],
    'not an object' => [anthropicAnswer('["Excel"]')],
    'wrong type' => [anthropicAnswer('{"title":"Excel","hours":"quatorze"}')],
    'missing field' => [anthropicAnswer('{"title":"Excel"}')],
    'too long' => [anthropicAnswer('{"title":"'.str_repeat('x', 21).'","hours":7}')],
    'below minimum' => [anthropicAnswer('{"title":"Excel","hours":-1}')],
    'refusal' => [anthropicAnswer('{"title":"Excel","hours":7}', 'refusal')],
    'truncated' => [anthropicAnswer('{"title":"Excel","hours":7}', 'max_tokens')],
    'no text block' => [['stop_reason' => 'end_turn', 'content' => []]],
]);

it('drops keys the schema does not declare', function () {
    aiConfig();
    aiHttp(['*' => Http::response(anthropicAnswer('{"title":"Excel","hours":7,"extra":"x"}'))]);

    expect(aiCall())->toBe(['title' => 'Excel', 'hours' => 7]);
});

it('answers a fixed French message on refused credentials, never the provider text', function (int $status) {
    aiConfig();
    aiHttp(['*' => Http::response(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => AI_PROVIDER_TEXT]], $status)]);

    try {
        aiCall();
        $this->fail('AiUnavailable expected');
    } catch (AiUnavailable $e) {
        expect($e->getMessage())->toBe(AiUnavailable::CREDENTIALS)
            ->and($e->getMessage())->not->toContain('req_')
            ->and($e->retryable)->toBeFalse();
    }
})->with([401, 403]);

it('reports a busy, failing or unreachable provider as AiUnavailable, retryable', function (Closure $stub) {
    aiConfig();
    aiHttp(['*' => $stub]);

    try {
        aiCall();
        $this->fail('AiUnavailable expected');
    } catch (AiUnavailable $e) {
        expect($e->retryable)->toBeTrue()
            ->and($e->getMessage())->toBeIn([AiUnavailable::UNREACHABLE, AiUnavailable::BUSY])
            ->and($e->getStatusCode())->toBe(503);
    }
})->with([
    '429' => [fn () => Http::response(['error' => ['message' => AI_PROVIDER_TEXT]], 429)],
    '500' => [fn () => Http::response('boom', 500)],
    '529 overloaded' => [fn () => Http::response(['error' => ['type' => 'overloaded_error']], 529)],
    'timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out after 120000 milliseconds')],
]);

it('waits 120 seconds at most and bounds the output', function () {
    aiConfig();
    $timeout = null;
    Http::swap((new Factory)->preventStrayRequests());
    Http::fake(['*' => function (Request $r, array $options) use (&$timeout) {
        $timeout = $options['timeout'] ?? null;

        return Http::response(anthropicAnswer('{"title":"Excel","hours":7}'));
    }]);

    aiCall();

    expect($timeout)->toBe(120);
});

// --- Compatible OpenAI -------------------------------------------------------

it('asks an OpenAI-compatible server for a json_schema answer', function () {
    aiConfig(['provider' => 'openai_compatible', 'base_url' => 'https://llm.example.com/v1/', 'model' => 'mistral-large'], key: 'sk-openai-key-123');
    aiHttp(['llm.example.com/*' => Http::response(openAiAnswer('{"title":"Excel","hours":14}'))]);

    expect(aiCall())->toBe(['title' => 'Excel', 'hours' => 14]);

    Http::assertSent(function (Request $r) {
        $body = $r->data();

        return $r->url() === 'https://llm.example.com/v1/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer sk-openai-key-123')
            && $body['model'] === 'mistral-large'
            && $body['messages'][0] === ['role' => 'system', 'content' => 'Tu extrais une formation.']
            && $body['messages'][1] === ['role' => 'user', 'content' => AI_PROMPT]
            && $body['response_format']['type'] === 'json_schema'
            && $body['response_format']['json_schema']['strict'] === true
            && $body['response_format']['json_schema']['schema']['required'] === ['title', 'hours'];
    });
});

it('sends no Authorization header without a key', function () {
    aiLocalConfig();
    aiHttp(['*' => Http::response(openAiAnswer('{"title":"Excel","hours":14}'))]);

    aiCall();

    Http::assertSent(fn (Request $r) => ! $r->hasHeader('Authorization'));
});

it('falls back to json_object when the server rejects json_schema', function () {
    aiConfig(['provider' => 'openai_compatible', 'base_url' => 'https://llm.example.com/v1', 'model' => 'old-model'], key: null);
    aiHttp(['*' => Http::sequence()
        ->push(['error' => ['message' => 'response_format json_schema not supported']], 400)
        ->push(openAiAnswer("```json\n{\"title\":\"Excel\",\"hours\":14}\n```"))]);

    expect(aiCall())->toBe(['title' => 'Excel', 'hours' => 14]);

    $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->data()['response_format']['type'])->all();
    expect($sent)->toBe(['json_schema', 'json_object']);
    Http::assertSent(fn (Request $r) => $r->data()['response_format']['type'] === 'json_object'
        && str_contains($r->data()['messages'][0]['content'], '"required":["title","hours"]'));
});

it('turns an unusable OpenAI-compatible answer into AiInvalidResponse', function (array $answer) {
    aiLocalConfig();
    aiHttp(['*' => Http::response($answer)]);

    expect(fn () => aiCall())->toThrow(AiInvalidResponse::class, AiInvalidResponse::MESSAGE);
})->with([
    'broken json' => [openAiAnswer('{"title":')],
    'prose' => [openAiAnswer('Voici la formation : Excel, 14 h.')],
    'wrong type' => [openAiAnswer('{"title":14,"hours":14}')],
    'truncated' => [openAiAnswer('{"title":"Excel","hours":14}', 'length')],
    'no choice' => [['choices' => []]],
]);

it('answers fixed French messages for OpenAI-compatible errors', function () {
    aiLocalConfig();
    aiHttp(['*' => Http::response(['error' => ['message' => AI_PROVIDER_TEXT]], 401)]);
    expect(fn () => aiCall())->toThrow(AiUnavailable::class, AiUnavailable::CREDENTIALS);

    aiHttp(['*' => Http::response(['error' => ['message' => AI_PROVIDER_TEXT]], 404)]);
    expect(fn () => aiCall())->toThrow(AiUnavailable::class, AiUnavailable::REJECTED);
});

// --- Network policy (Review Focus 3) ----------------------------------------

it('refuses a server on the local network when the box is not checked, without any request', function (string $baseUrl) {
    aiLocalConfig(local: false, baseUrl: $baseUrl);
    aiHttp(['*' => Http::response(openAiAnswer('{"title":"Excel","hours":14}'))]);

    expect(fn () => aiCall())->toThrow(AiUnavailable::class, AiUnavailable::HOST_REFUSED);
    Http::assertNothingSent();
})->with([
    'private ip, http' => ['http://192.168.1.20:11434/v1'],
    'private ip, https' => ['https://192.168.1.20/v1'],
    'name resolving to a private ip' => ['https://ia.lan/v1'],
    'loopback' => ['https://127.0.0.1:8000/v1'],
]);

it('reaches the checked local server, pinned to its address, when the box is checked', function () {
    aiLocalConfig(baseUrl: 'http://ia.lan:11434/v1');
    $pinned = null;
    Http::swap((new Factory)->preventStrayRequests());
    Http::fake(['*' => function (Request $r, array $options) use (&$pinned) {
        $pinned = $options['curl'][CURLOPT_RESOLVE] ?? null;

        return Http::response(openAiAnswer('{"title":"Excel","hours":14}'));
    }]);

    expect(aiCall())->toBe(['title' => 'Excel', 'hours' => 14]);
    Http::assertSent(fn (Request $r) => $r->url() === 'http://ia.lan:11434/v1/chat/completions');
    expect($pinned)->toBe(['ia.lan:11434:192.168.1.20']);
});

it('opens only the registered host and port: another internal host, port or scheme stays refused', function () {
    $endpoint = AiEndpoint::fromConfig(aiLocalConfig(baseUrl: 'http://192.168.1.20:11434/v1'));

    expect($endpoint->guard('http://192.168.1.20:11434/v1/chat/completions'))->toBe(['192.168.1.20']);
    foreach (['http://192.168.1.30:11434/v1/chat/completions', 'http://192.168.1.20:8080/v1/chat/completions', 'http://other.lan:11434/v1', 'https://192.168.1.20:11434/v1', 'http://169.254.169.254/latest/meta-data'] as $url) {
        expect(fn () => $endpoint->guard($url))->toThrow(AiUnavailable::class, AiUnavailable::HOST_REFUSED);
    }
});

it('never reaches a link-local or reserved address, even with the box checked', function () {
    aiLocalConfig(baseUrl: 'http://metadata.lan/v1');
    aiHttp(['*' => Http::response(openAiAnswer('{"title":"Excel","hours":14}'))]);

    expect(fn () => aiCall())->toThrow(AiUnavailable::class, AiUnavailable::HOST_REFUSED);
    Http::assertNothingSent();
});

it('does not follow a redirect, from the local server or from a public provider', function () {
    aiLocalConfig();
    aiHttp(['*' => Http::response('', 302, ['Location' => 'http://192.168.1.30/admin'])]);
    expect(fn () => aiCall())->toThrow(AiUnavailable::class);
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '192.168.1.30'));

    ConnectorConfig::query()->delete();
    aiConfig();
    aiHttp(['*' => Http::response('', 307, ['Location' => 'http://169.254.169.254/'])]);
    expect(fn () => aiCall())->toThrow(AiUnavailable::class);
    Http::assertSentCount(1);
});

it('allows a loopback server only with the box checked (local AI on the same machine)', function () {
    aiLocalConfig(baseUrl: 'http://127.0.0.1:11434/v1');
    aiHttp(['*' => Http::response(openAiAnswer('{"title":"Excel","hours":14}'))]);

    expect(aiCall())->toBe(['title' => 'Excel', 'hours' => 14]);
});

// --- Service, journal, limiter -----------------------------------------------

it('is configured only when enabled and complete', function () {
    expect(app(Ai::class)->configured())->toBeFalse();

    $config = aiConfig(key: null);
    expect(app(Ai::class)->configured())->toBeFalse();

    $config->update(['secrets' => ['api_key' => 'sk-test-x123']]);
    expect(app(Ai::class)->configured())->toBeTrue();

    $config->update(['enabled' => false]);
    expect(app(Ai::class)->configured())->toBeFalse();

    $config->update(['enabled' => true, 'settings' => ['provider' => 'openai_compatible', 'base_url' => 'https://llm.example.com/v1']]);
    expect(app(Ai::class)->configured())->toBeFalse(); // model missing

    $config->update(['settings' => ['provider' => 'openai_compatible', 'base_url' => 'https://llm.example.com/v1', 'model' => 'm']]);
    expect(app(Ai::class)->configured())->toBeTrue();
});

it('refuses to call without a configuration, with a French message', function () {
    tenant();
    aiHttp([]);

    expect(fn () => aiCall())->toThrow(AiUnavailable::class, AiUnavailable::NOT_CONFIGURED);
});

it('journals each call with purpose, provider and duration, never the content', function () {
    $user = commercial();
    $this->actingAs($user);
    aiConfig();
    aiHttp(['*' => Http::sequence()
        ->push(anthropicAnswer('{"title":"Réponse secrète","hours":7}'))
        ->push(['error' => ['message' => AI_PROVIDER_TEXT]], 500)]);

    aiCall();
    expect(fn () => aiCall())->toThrow(AiUnavailable::class);

    $runs = ConnectorRun::query()->orderBy('started_at')->orderBy('id')->get();
    expect($runs)->toHaveCount(2)
        ->and($runs->pluck('status')->sort()->values()->all())->toBe(['failed', 'success']);

    $ok = $runs->firstWhere('status', 'success');
    expect($ok->summary)->toContain('training.from_pdf')->toContain('Anthropic')->toContain('ms')->toContain($user->id)
        ->and($ok->finished_at)->not->toBeNull();
    $failed = $runs->firstWhere('status', 'failed');
    expect($failed->error)->toBe(AiUnavailable::UNREACHABLE)->and($failed->summary)->toContain('training.from_pdf');

    $all = json_encode(DB::table('connector_runs')->get());
    expect($all)->not->toContain('confidentiel')->not->toContain('Dupont')->not->toContain('secrète')
        ->not->toContain('req_011')->not->toContain('Tu extrais');
});

it('accepts only a short machine purpose (no content in the journal)', function () {
    aiConfig();
    aiHttp([]);

    expect(fn () => app(Ai::class)->structured('s', 'u', aiSchema(), 'Formation de Mme Dupont'))->toThrow(InvalidArgumentException::class);
});

it('registers the ai limiter: 20 requests an hour per user and organisme', function () {
    $user = commercial();
    $request = Illuminate\Http\Request::create('/x');
    $request->setUserResolver(fn () => $user);

    $limits = RateLimiter::limiter('ai')($request);
    $limit = is_array($limits) ? $limits[0] : $limits;

    expect($limit->maxAttempts)->toBe(20)
        ->and($limit->decaySeconds)->toBe(3600)
        ->and($limit->key)->toContain((string) $user->id)->toContain(tenant()->id);
});

// --- Administration screen ---------------------------------------------------

/** @return array<string, mixed> */
function aiPayload(array $settings = [], ?string $key = null): array
{
    return [
        'enabled' => true,
        'settings' => ['provider' => 'anthropic', 'model' => '', 'base_url' => '', 'local_network' => false, ...$settings],
        'secrets' => ['api_key' => $key ?? ''],
    ];
}

it('saves the Anthropic configuration with an encrypted key that never comes back to the screen', function () {
    $this->actingAs(admin(twoFactor: true));
    $key = 'sk-ant-test-'.Str::random(40);

    $this->put('/admin/connectors/ai', aiPayload(key: $key))->assertRedirect()->assertSessionHasNoErrors();

    $config = ConnectorConfig::query()->where('connector_key', 'ai')->sole();
    expect($config->secret('api_key'))->toBe($key)
        ->and(DB::table('connector_configs')->where('id', $config->id)->value('secrets'))->not->toContain($key);

    $props = $this->get('/admin/connectors/ai')->assertOk()->assertDontSee($key)->viewData('page')['props'];
    expect($props['config']['secrets_set'])->toBe(['api_key' => true])
        ->and($props['connector']['organisme_wide'])->toBeTrue()
        ->and($props['connector']['fields']['provider']['type'])->toBe('select')
        ->and($props['connector']['fields']['local_network']['type'])->toBe('checkbox')
        ->and($props['connector']['fields']['local_network']['warning'])->toContain('réseau local')
        ->and($props['connector']['fields']['model']['suggestions'])->toContain(AnthropicClient::DEFAULT_MODEL);
});

it('validates the configuration', function (array $settings, string $field) {
    $this->actingAs(admin(twoFactor: true));

    $this->putJson('/admin/connectors/ai', aiPayload($settings))->assertStatus(422)->assertJsonValidationErrors(["settings.{$field}"]);
})->with([
    'unknown provider' => [['provider' => 'gemini'], 'provider'],
    'openai without model' => [['provider' => 'openai_compatible', 'base_url' => 'https://llm.example.com/v1'], 'model'],
    'openai without address' => [['provider' => 'openai_compatible', 'model' => 'm'], 'base_url'],
    'not a url' => [['provider' => 'openai_compatible', 'model' => 'm', 'base_url' => 'llm.example.com'], 'base_url'],
    'ftp' => [['provider' => 'openai_compatible', 'model' => 'm', 'base_url' => 'ftp://llm.example.com/v1'], 'base_url'],
    'credentials in url' => [['provider' => 'openai_compatible', 'model' => 'm', 'base_url' => 'https://u:p@llm.example.com/v1'], 'base_url'],
    'query' => [['provider' => 'openai_compatible', 'model' => 'm', 'base_url' => 'https://llm.example.com/v1?x=1'], 'base_url'],
    'http on a public server' => [['provider' => 'openai_compatible', 'model' => 'm', 'base_url' => 'http://llm.example.com/v1'], 'base_url'],
    'private without the box' => [['provider' => 'openai_compatible', 'model' => 'm', 'base_url' => 'http://192.168.1.20:11434/v1'], 'base_url'],
    'loopback without the box' => [['provider' => 'openai_compatible', 'model' => 'm', 'base_url' => 'https://localhost/v1'], 'base_url'],
    'link-local even with the box' => [['provider' => 'openai_compatible', 'model' => 'm', 'base_url' => 'http://169.254.169.254/v1', 'local_network' => true], 'base_url'],
]);

it('accepts a local server when the box is checked', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->putJson('/admin/connectors/ai', aiPayload(['provider' => 'openai_compatible', 'model' => 'llama3.1:8b', 'base_url' => 'http://192.168.1.20:11434/v1', 'local_network' => true]))
        ->assertRedirect();

    expect(ConnectorConfig::query()->where('connector_key', 'ai')->sole()->settings['local_network'])->toBeTrue();
});

it('asks for the key again when the provider or the address changes', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->put('/admin/connectors/ai', aiPayload(['provider' => 'openai_compatible', 'model' => 'm', 'base_url' => 'https://llm.example.com/v1'], key: 'sk-first-key'))->assertSessionHasNoErrors();

    $this->putJson('/admin/connectors/ai', aiPayload(['provider' => 'openai_compatible', 'model' => 'm', 'base_url' => 'https://evil.example.com/v1']))
        ->assertStatus(422)->assertJsonValidationErrors(['secrets.api_key']);
    $this->putJson('/admin/connectors/ai', aiPayload(['provider' => 'anthropic']))
        ->assertStatus(422)->assertJsonValidationErrors(['secrets.api_key']);
});

it('is reserved to administrators', function () {
    $this->actingAs(direction(twoFactor: true))->get('/admin/connectors/ai')->assertForbidden();
});

it('tests the connection with a tiny structured request', function () {
    $this->actingAs(admin(twoFactor: true));
    aiConfig(['model' => 'claude-sonnet-5-5']);
    aiHttp(['*' => Http::response(anthropicAnswer('{"ok":true}'))]);

    $this->postJson('/admin/connectors/ai/test')->assertOk()
        ->assertJson(['ok' => true, 'message' => 'Intelligence artificielle joignable (modèle claude-sonnet-5-5).']);
    Http::assertSent(fn (Request $r) => $r->data()['output_config']['format']['schema']['required'] === ['ok']);
});

it('reports a failed connection test with a fixed French message', function () {
    $this->actingAs(admin(twoFactor: true));
    aiConfig();
    aiHttp(['*' => Http::response(['error' => ['message' => AI_PROVIDER_TEXT]], 401)]);

    $this->postJson('/admin/connectors/ai/test')->assertOk()->assertJson(['ok' => false, 'message' => AiUnavailable::CREDENTIALS]);
    expect(ConnectorRun::query()->sole()->error)->toBe(AiUnavailable::CREDENTIALS);
});

it('is registered as an organisme-wide connector', function () {
    expect(app(ConnectorRegistry::class)->make('ai'))->toBeInstanceOf(AiConnector::class)
        ->and(AiConnector::label())->toBe('Intelligence artificielle');
});
