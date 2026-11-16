<?php

use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Core\HostPolicy;
use App\Modules\Connectors\Mail365\GraphMailFailed;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Companies\Company;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const M365S_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';
const M365S_CLIENT = 'b4a7c2e1-3f5d-4c8a-9e6b-1d2f3a4b5c6d';
const M365S_FROM = 'envoi@cap.test';
const M365S_GRAPH = 'https://graph.microsoft.com/v1.0/users/'.M365S_FROM;
const M365S_MS_TEXT = 'AADSTS7000222: The provided client secret keys for app are expired. Trace ID: 0000';

beforeEach(function () {
    HostPolicy::resolveUsing(fn (string $host): array => ['203.0.113.30']);
});
afterEach(fn () => HostPolicy::resolveUsing(null));

/** @param  array<string, mixed>  $stubs */
function m365sHttp(array $stubs = []): void
{
    Http::swap((new Factory)->preventStrayRequests());
    Http::fake([
        'login.microsoftonline.com/*' => fn () => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'tok-'.Str::random(12)]),
        ...$stubs,
    ]);
}

/** @return array<string, mixed> */
function m365sPayload(array $settings = [], ?string $secret = null): array
{
    return [
        'enabled' => true,
        'settings' => ['tenant' => M365S_TENANT, 'client_id' => M365S_CLIENT, 'from_address' => M365S_FROM, 'from_name' => 'CAP Formation', ...$settings],
        'secrets' => ['client_secret' => $secret ?? 'test-'.Str::random(32)],
    ];
}

function m365sConfig(): ConnectorConfig
{
    tenant();
    $config = new ConnectorConfig(['connector_key' => 'microsoft365_mail', 'company_id' => null]);
    $config->fill(['enabled' => true, ...Arr::only(m365sPayload(), ['settings']), 'secrets' => ['client_secret' => 'test-'.Str::random(32)]])->save();

    return $config;
}

it('saves the configuration with an encrypted secret that never comes back to the screen', function () {
    $this->actingAs(admin(twoFactor: true));
    $secret = 'test-'.Str::random(40);

    $this->put('/admin/connectors/microsoft365_mail', m365sPayload(secret: $secret))->assertRedirect();

    $config = ConnectorConfig::query()->where('connector_key', 'microsoft365_mail')->sole();
    expect($config->secret('client_secret'))->toBe($secret)
        ->and(DB::table('connector_configs')->where('id', $config->id)->value('secrets'))->not->toContain($secret);

    $page = $this->get('/admin/connectors/microsoft365_mail')->assertOk()->assertDontSee($secret);
    expect($page->viewData('page')['props']['config']['secrets_set'])->toBe(['client_secret' => true]);
});

it('is organisme-wide only: no company scope is offered, saved nor read', function () {
    $this->actingAs(admin(twoFactor: true));
    $company = Company::factory()->create();

    $this->putJson('/admin/connectors/microsoft365_mail', ['company_id' => $company->id, ...m365sPayload()])
        ->assertStatus(422)->assertJsonValidationErrors(['company_id' => 'Ce connecteur s\'applique à tout l\'organisme, pas à une société.']);
    expect(ConnectorConfig::query()->where('connector_key', 'microsoft365_mail')->exists())->toBeFalse();

    $props = $this->get('/admin/connectors/microsoft365_mail?company='.$company->id)->assertOk()->viewData('page')['props'];
    expect($props['companyId'])->toBeNull()->and($props['connector']['organisme_wide'])->toBeTrue();

    $smtp = $this->get('/admin/connectors/smtp')->viewData('page')['props'];
    expect($smtp['connector']['organisme_wide'])->toBeFalse();
});

it('requires the secret again when the directory or the application changes', function (string $setting, string $value) {
    $this->actingAs(admin(twoFactor: true));
    $this->put('/admin/connectors/microsoft365_mail', m365sPayload())->assertRedirect();

    $payload = m365sPayload([$setting => $value]);
    $payload['secrets']['client_secret'] = '';
    $this->putJson('/admin/connectors/microsoft365_mail', $payload)
        ->assertStatus(422)->assertJsonValidationErrors(['secrets.client_secret']);

    // The same values keep the stored secret.
    $payload = m365sPayload();
    $payload['secrets']['client_secret'] = '';
    $this->putJson('/admin/connectors/microsoft365_mail', $payload)->assertRedirect();
})->with([
    'directory' => ['tenant', 'capformation.net'],
    'application' => ['client_id', 'c5b8d3f2-4a6e-4d9b-8f7c-2e3a4b5c6d7e'],
]);

it('the test button sends a test e-mail to the current user through Graph and says so', function () {
    m365sHttp([M365S_GRAPH.'/sendMail' => Http::response('', 202)]);
    m365sConfig();
    $user = admin(twoFactor: true);
    $this->actingAs($user);

    $this->postJson('/admin/connectors/microsoft365_mail/test')
        ->assertOk()->assertJson(['ok' => true, 'message' => "E-mail de test envoyé à {$user->email}."]);

    $sent = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMail'))->map(fn ($pair) => $pair[0])->values();
    expect($sent)->toHaveCount(1);
    $mime = (string) base64_decode($sent[0]->body(), true);
    expect($mime)->toContain($user->email)->toContain('Subject:')->toContain(M365S_FROM);
    expect(ConnectorRun::query()->sole()->status)->toBe('success');
});

it('the test button reports a refusal in French without Microsoft\'s text', function (int $status) {
    m365sHttp([M365S_GRAPH.'/sendMail' => Http::response(['error' => ['message' => M365S_MS_TEXT]], $status)]);
    m365sConfig();
    $this->actingAs(admin(twoFactor: true));

    $response = $this->postJson('/admin/connectors/microsoft365_mail/test')->assertOk()->assertJson(['ok' => false, 'message' => GraphMailFailed::REFUSED])
        ->assertDontSee('AADSTS');
    expect((string) ConnectorRun::query()->sole()->error)->not->toContain('AADSTS');
})->with([401, 403]);

it('the test button says Microsoft is unreachable on a 503, and rejects a refused secret', function () {
    m365sHttp([M365S_GRAPH.'/sendMail' => Http::response('', 503)]);
    m365sConfig();
    $this->actingAs(admin(twoFactor: true));
    $this->postJson('/admin/connectors/microsoft365_mail/test')->assertOk()
        ->assertJson(['ok' => false, 'message' => 'Microsoft est injoignable pour le moment. Réessayez plus tard.']);

    Http::swap((new Factory)->preventStrayRequests());
    Http::fake(['login.microsoftonline.com/*' => Http::response(['error_description' => M365S_MS_TEXT], 401)]);
    $this->postJson('/admin/connectors/microsoft365_mail/test')->assertOk()
        ->assertJson(['ok' => false, 'message' => GraphMailFailed::APP_REFUSED])->assertDontSee('AADSTS');
});

it('refuses an upload address on an unknown host, for good, with a French message', function () {
    $draft = M365S_GRAPH.'/messages/AAMkDraft';
    m365sHttp([
        M365S_GRAPH.'/messages' => Http::response(['id' => 'AAMkDraft'], 201),
        $draft.'/attachments/createUploadSession' => Http::response(['uploadUrl' => 'https://evil.example.test/upload?authtoken=secret-upload'], 201),
        $draft => Http::response('', 204),
    ]);
    m365sConfig();

    $thrown = null;
    try {
        TenantMailer::for()->raw('Plaquette', fn (Message $m) => $m->to('lea.durand@example.test')->subject('Plaquette')
            ->attachData(random_bytes(5 * 1024 * 1024), 'plaquette.pdf', ['mime' => 'application/pdf']));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(GraphMailFailed::class)
        ->and($thrown->getMessage())->toBe('Microsoft 365 a proposé une adresse de dépôt non reconnue. Réessayez plus tard ou contactez le support.')
        ->and(Http::recorded(fn (Request $r) => str_contains($r->url(), 'evil.example.test'))->count())->toBe(0)
        ->and(Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === $draft)->count())->toBe(1);
});
