<?php

use App\Modules\Connectors\Calendar\GraphRetryLater;
use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorKind;
use App\Modules\Connectors\Core\ConnectorRegistry;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Core\HostPolicy;
use App\Modules\Connectors\Mail365\GraphMailClient;
use App\Modules\Connectors\Mail365\GraphMailFailed;
use App\Modules\Connectors\Mail365\GraphTransport;
use App\Modules\Connectors\Mail365\Microsoft365MailConnector;
use App\Modules\Connectors\Smtp\SmtpHostPolicy;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\Tenancy\Tenant;
use App\Modules\Crm\Brochures\BrochureSending;
use App\Modules\Crm\CrmServiceProvider;
use App\Modules\Crm\Quotes\Quote;
use App\Modules\Crm\Quotes\QuoteMailFailed;
use App\Modules\Crm\Quotes\SendQuote;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

const MAIL365_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';
const MAIL365_CLIENT = 'b4a7c2e1-3f5d-4c8a-9e6b-1d2f3a4b5c6d';
const MAIL365_FROM = 'envoi@cap.test';
const MAIL365_TOKEN_URL = 'https://login.microsoftonline.com/'.MAIL365_TENANT.'/oauth2/v2.0/token';
const MAIL365_GRAPH = 'https://graph.microsoft.com/v1.0/users/'.MAIL365_FROM;
const MAIL365_UPLOAD = 'https://outlook.office.com/api/v2.0/Users(\'u\')/Messages(\'AAMkDraft\')/AttachmentSessions(\'s1\')?authtoken=upload-secret-token';

/** Microsoft's own error text: never shown, journaled nor logged by the TMS. */
const MAIL365_MS_TEXT = 'AADSTS7000222: The provided client secret keys for app are expired. Trace ID: 0000';

beforeEach(function () {
    HostPolicy::resolveUsing(fn (string $host): array => ['203.0.113.30']);
    SmtpHostPolicy::resolveUsing(fn (string $host): array => ['203.0.113.10']);
});
afterEach(function () {
    HostPolicy::resolveUsing(null);
    SmtpHostPolicy::resolveUsing(null);
});

/**
 * A fresh fake (no stray request): the token endpoint answers a new token
 * each time, then $stubs.
 *
 * @param  array<string, mixed>  $stubs
 */
function mail365Http(array $stubs = []): void
{
    Http::swap((new Factory)->preventStrayRequests());
    Http::fake([
        'login.microsoftonline.com/*' => fn () => Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => 'tok-'.Str::random(12)]),
        ...$stubs,
    ]);
}

/** The organisme's Microsoft 365 mail configuration; a test secret generated per run. */
function mail365Config(array $settings = [], bool $enabled = true): ConnectorConfig
{
    tenant();

    $config = ConnectorConfig::query()->firstOrNew(['connector_key' => 'microsoft365_mail', 'company_id' => null]);
    $config->fill([
        'enabled' => $enabled,
        'settings' => ['tenant' => MAIL365_TENANT, 'client_id' => MAIL365_CLIENT, 'from_address' => MAIL365_FROM, 'from_name' => 'CAP Formation', ...$settings],
        'secrets' => ['client_secret' => 'test-'.Str::random(32)],
    ])->save();

    return $config;
}

/** @return list<Request> */
function mail365Requests(string $prefix): array
{
    return Http::recorded(fn (Request $r) => str_starts_with($r->url(), $prefix))->map(fn (array $pair) => $pair[0])->values()->all();
}

function mail365Raw(string $text = 'Bonjour Léa', ?Closure $more = null): void
{
    TenantMailer::for()->raw($text, function (Message $m) use ($more) {
        $m->to('lea.durand@example.test', 'Léa Durand')->subject('Votre devis CF-2026-0001');
        if ($more !== null) {
            $more($m);
        }
    });
}

it('registers the microsoft365_mail delivery connector with its settings, secret and french labels', function () {
    $connector = app(ConnectorRegistry::class)->make('microsoft365_mail');

    expect($connector)->toBeInstanceOf(Microsoft365MailConnector::class)
        ->and($connector::label())->toBe('Microsoft 365 (envoi des e-mails)')
        ->and($connector::kind())->toBe(ConnectorKind::Delivery)
        ->and(array_keys($connector->settingsRules()))->toBe(['tenant', 'client_id', 'from_address', 'from_name'])
        ->and($connector->secretKeys())->toBe(['client_secret'])
        ->and(array_keys($connector->labels()))->toBe(['tenant', 'client_id', 'from_address', 'from_name', 'client_secret']);
});

it('requires a precise directory, an application id and a sender address', function (array $settings, array $errors) {
    $connector = app(ConnectorRegistry::class)->make('microsoft365_mail');
    $validator = validator([...['tenant' => MAIL365_TENANT, 'client_id' => MAIL365_CLIENT, 'from_address' => MAIL365_FROM], ...$settings], $connector->settingsRules());

    expect(array_keys($validator->errors()->toArray()))->toBe($errors);
})->with([
    'guid' => [[], []],
    'domain' => [['tenant' => 'capformation.net'], []],
    'onmicrosoft domain' => [['tenant' => 'capformation.onmicrosoft.com', 'from_name' => 'CAP'], []],
    'common' => [['tenant' => 'common'], ['tenant']],
    'organizations' => [['tenant' => 'organizations'], ['tenant']],
    'consumers' => [['tenant' => 'consumers'], ['tenant']],
    'empty' => [['tenant' => ''], ['tenant']],
    'url' => [['tenant' => 'https://evil.example/x'], ['tenant']],
    'client id' => [['client_id' => 'pas-un-guid'], ['client_id']],
    'sender' => [['from_address' => 'pas-une-adresse'], ['from_address']],
]);

it('refuses the « common » directory when the configuration is saved, in French', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->putJson('/admin/connectors/microsoft365_mail', [
        'enabled' => true,
        'settings' => ['tenant' => 'common', 'client_id' => MAIL365_CLIENT, 'from_address' => MAIL365_FROM],
        'secrets' => ['client_secret' => 'test-'.Str::random(32)],
    ])->assertStatus(422)->assertJsonValidationErrors(['settings.tenant' => 'Indiquez l\'identifiant (GUID) de l\'annuaire Azure ou un de ses domaines (ex. capformation.net) : « common », « organizations » et « consumers » ne permettent pas l\'envoi par l\'application.']);

    expect(ConnectorConfig::query()->where('connector_key', 'microsoft365_mail')->exists())->toBeFalse();
});

it('sends a small message as MIME through sendMail, from the configured mailbox, keeping the reply-to', function () {
    mail365Http([MAIL365_GRAPH.'/sendMail' => Http::response('', 202)]);
    mail365Config();

    mail365Raw(more: fn (Message $m) => $m->from('lea.commerciale@ailleurs.test', 'Léa Commerciale')->replyTo('commercial@cap.test', 'Paul Commercial')->bcc('archive@cap.test'));

    $token = mail365Requests('https://login.microsoftonline.com/');
    expect($token)->toHaveCount(1)
        ->and($token[0]->url())->toBe(MAIL365_TOKEN_URL)
        ->and($token[0]['grant_type'])->toBe('client_credentials')
        ->and($token[0]['scope'])->toBe('https://graph.microsoft.com/.default')
        ->and($token[0]['client_id'])->toBe(MAIL365_CLIENT);

    $sent = mail365Requests('https://graph.microsoft.com/');
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->method())->toBe('POST')
        ->and($sent[0]->url())->toBe(MAIL365_GRAPH.'/sendMail')
        ->and($sent[0]->header('Content-Type')[0])->toStartWith('text/plain')
        ->and($sent[0]->header('Authorization')[0])->toStartWith('Bearer tok-');

    $mime = base64_decode($sent[0]->body(), true);
    expect($mime)->toBeString()
        ->toContain('From: CAP Formation <'.MAIL365_FROM.'>')
        ->toContain('To: =?utf-8?Q?L=C3=A9a?= Durand <lea.durand@example.test>')
        ->toContain('Reply-To: Paul Commercial <commercial@cap.test>')
        ->toContain('Bcc: archive@cap.test')
        ->toContain('Subject: Votre devis CF-2026-0001')
        ->toContain('Bonjour L')
        ->not->toContain('ailleurs.test');
});

it('keeps the sender name of the message when the configuration has none', function () {
    mail365Http([MAIL365_GRAPH.'/sendMail' => Http::response('', 202)]);
    mail365Config(['from_name' => null]);

    mail365Raw(more: fn (Message $m) => $m->from('lea.commerciale@ailleurs.test', 'Léa Commerciale'));

    $mime = base64_decode(mail365Requests('https://graph.microsoft.com/')[0]->body(), true);
    expect($mime)->toContain('From: =?utf-8?Q?L=C3=A9a?= Commerciale <'.MAIL365_FROM.'>');
});

it('sends a 15 Mo message as a draft with upload sessions in chunks of at most 4 Mo, then sends it', function () {
    $draft = MAIL365_GRAPH.'/messages/AAMkDraft';
    mail365Http([
        MAIL365_GRAPH.'/messages' => Http::response(['id' => 'AAMkDraft'], 201),
        $draft.'/attachments/createUploadSession' => Http::response(['uploadUrl' => MAIL365_UPLOAD], 201),
        $draft.'/attachments' => Http::response(['id' => 'att-small'], 201),
        $draft.'/send' => Http::response('', 202),
        'outlook.office.com/*' => Http::response(['nextExpectedRanges' => []], 200),
    ]);
    mail365Config();
    $big = random_bytes(15 * 1024 * 1024);
    $small = "%PDF-1.7 devis\n";

    TenantMailer::for()->raw('Voici notre devis et la plaquette.', fn (Message $m) => $m
        ->to('lea.durand@example.test')->cc('direction@cap.test')->replyTo('commercial@cap.test')->subject('Votre devis CF-2026-0001')
        ->attachData($small, 'CF-2026-0001.pdf', ['mime' => 'application/pdf'])
        ->attachData($big, 'plaquette.pdf', ['mime' => 'application/pdf']));

    $graph = mail365Requests('https://graph.microsoft.com/');
    expect(collect($graph)->map(fn (Request $r) => $r->method().' '.$r->url())->all())->toBe([
        'POST '.MAIL365_GRAPH.'/messages',
        'POST '.$draft.'/attachments',
        'POST '.$draft.'/attachments/createUploadSession',
        'POST '.$draft.'/send',
    ]);

    $message = $graph[0]->data();
    expect($message['subject'])->toBe('Votre devis CF-2026-0001')
        ->and($message['body'])->toBe(['contentType' => 'Text', 'content' => 'Voici notre devis et la plaquette.'])
        ->and($message['from'])->toBe(['emailAddress' => ['address' => MAIL365_FROM, 'name' => 'CAP Formation']])
        ->and($message['toRecipients'])->toBe([['emailAddress' => ['address' => 'lea.durand@example.test']]])
        ->and($message['ccRecipients'])->toBe([['emailAddress' => ['address' => 'direction@cap.test']]])
        ->and($message['replyTo'])->toBe([['emailAddress' => ['address' => 'commercial@cap.test']]]);

    expect($graph[1]->data())->toMatchArray(['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => 'CF-2026-0001.pdf', 'contentType' => 'application/pdf', 'contentBytes' => base64_encode($small)])
        ->and($graph[2]->data())->toBe(['AttachmentItem' => ['attachmentType' => 'file', 'name' => 'plaquette.pdf', 'size' => 15 * 1024 * 1024, 'contentType' => 'application/pdf', 'isInline' => false]]);

    // The upload URL carries its own token: no Authorization header, the chunks rebuild the file.
    $chunks = mail365Requests('https://outlook.office.com/');
    $total = strlen($big);
    $offset = 0;
    foreach ($chunks as $chunk) {
        $length = strlen($chunk->body());
        expect($chunk->method())->toBe('PUT')
            ->and($chunk->hasHeader('Authorization'))->toBeFalse()
            ->and($length)->toBeLessThanOrEqual(4 * 1024 * 1024)
            ->and($chunk->header('Content-Range')[0])->toBe('bytes '.$offset.'-'.($offset + $length - 1).'/'.$total);
        $offset += $length;
    }
    expect(count($chunks))->toBe(4)
        ->and(implode('', array_map(fn (Request $r) => $r->body(), $chunks)))->toBe($big);
});

it('deletes the draft when an upload fails, and raises a retryable error without Microsoft\'s text', function () {
    $draft = MAIL365_GRAPH.'/messages/AAMkDraft';
    mail365Http([
        MAIL365_GRAPH.'/messages' => Http::response(['id' => 'AAMkDraft'], 201),
        $draft.'/attachments/createUploadSession' => Http::response(['uploadUrl' => MAIL365_UPLOAD], 201),
        $draft => Http::response('', 204),
        'outlook.office.com/*' => Http::response(['error' => ['message' => MAIL365_MS_TEXT]], 503),
    ]);
    mail365Config();

    $thrown = null;
    try {
        TenantMailer::for()->raw('Plaquette', fn (Message $m) => $m->to('lea.durand@example.test')->subject('Plaquette')
            ->attachData(random_bytes(5 * 1024 * 1024), 'plaquette.pdf', ['mime' => 'application/pdf']));
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(GraphRetryLater::class)
        ->and((string) $thrown)->not->toContain('AADSTS')->not->toContain('upload-secret-token');
    $graph = collect(mail365Requests('https://graph.microsoft.com/'))->map(fn (Request $r) => $r->method().' '.$r->url())->all();
    expect($graph)->toContain('DELETE '.$draft)->not->toContain('POST '.$draft.'/send');
});

it('fails for good with a fixed French message on 401 and 403, never Microsoft\'s text', function (int $status, int $calls) {
    mail365Http([MAIL365_GRAPH.'/sendMail' => Http::response(['error' => ['code' => 'ErrorAccessDenied', 'message' => MAIL365_MS_TEXT]], $status)]);
    mail365Config();

    $thrown = null;
    try {
        mail365Raw();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(GraphMailFailed::class)
        ->and($thrown->getMessage())->toBe('Microsoft 365 a refusé l\'envoi : vérifiez la permission Mail.Send et l\'adresse d\'expédition.')
        ->and($thrown->status)->toBe($status)
        ->and((string) $thrown)->not->toContain('AADSTS')->not->toContain('tok-');
    // A 401 is tried once more with a new token (it may have been revoked).
    expect(mail365Requests(MAIL365_GRAPH.'/sendMail'))->toHaveCount($calls);
})->with(['401' => [401, 2], '403' => [403, 1]]);

it('fails for good when Microsoft refuses the application secret', function () {
    Http::swap((new Factory)->preventStrayRequests());
    Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_client', 'error_description' => MAIL365_MS_TEXT], 401)]);
    mail365Config();

    expect(fn () => mail365Raw())->toThrow(GraphMailFailed::class, 'Microsoft 365 a refusé l\'application : vérifiez l\'identifiant et le secret client (secret expiré ?).');
    expect(mail365Requests('https://graph.microsoft.com/'))->toBe([]);
});

it('raises a retryable error on 429, 5xx and timeouts', function (mixed $answer) {
    mail365Http([MAIL365_GRAPH.'/sendMail' => $answer]);
    mail365Config();

    $thrown = null;
    try {
        mail365Raw();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(GraphRetryLater::class)
        ->and((string) $thrown)->not->toContain('AADSTS');
})->with([
    '429' => fn () => Http::response(['error' => ['message' => MAIL365_MS_TEXT]], 429, ['Retry-After' => '30']),
    '503' => fn () => Http::response(['error' => ['message' => MAIL365_MS_TEXT]], 503),
    'timeout' => fn () => Http::failedConnection('cURL error 28: '.MAIL365_MS_TEXT),
]);

it('reuses the cached token, encrypted, under a key of the organisme and of the configuration', function () {
    mail365Http([MAIL365_GRAPH.'/sendMail' => Http::response('', 202)]);
    $config = mail365Config();

    mail365Raw();
    mail365Raw();

    expect(mail365Requests('https://login.microsoftonline.com/'))->toHaveCount(1)
        ->and(mail365Requests(MAIL365_GRAPH.'/sendMail'))->toHaveCount(2);

    $key = GraphMailClient::cacheKey($config);
    expect($key)->toStartWith('tenant:'.tenant()->id.':')->toContain($config->id)
        ->and(Cache::get($key))->toBeString()->not->toStartWith('tok-');
});

it('never shares a token between two organismes', function () {
    mail365Http([MAIL365_GRAPH.'/sendMail' => Http::response('', 202)]);
    $first = mail365Config();
    mail365Raw();

    $other = Tenant::factory()->create();
    inTenant($other);
    $second = mail365Config();
    mail365Raw();

    $bearers = collect(mail365Requests(MAIL365_GRAPH.'/sendMail'))->map(fn (Request $r) => $r->header('Authorization')[0]);
    expect(mail365Requests('https://login.microsoftonline.com/'))->toHaveCount(2)
        ->and($bearers->unique())->toHaveCount(2)
        ->and(GraphMailClient::cacheKey($first))->not->toBe(GraphMailClient::cacheKey($second));
});

it('names its transport microsoft-graph', function () {
    expect((string) new GraphTransport(app(GraphMailClient::class), mail365Config()))->toBe('microsoft-graph');
});

it('tests the connection by sending a test e-mail, and reports failures in French', function () {
    $connector = app(ConnectorRegistry::class)->make('microsoft365_mail');
    $config = mail365Config();
    $user = admin(twoFactor: true);
    $this->actingAs($user);

    mail365Http([MAIL365_GRAPH.'/sendMail' => Http::response('', 202)]);
    $result = $connector->test($config);
    expect($result->ok)->toBeTrue()->and($result->message)->toBe("E-mail de test envoyé à {$user->email}.");

    Http::swap((new Factory)->preventStrayRequests());
    Http::fake(['login.microsoftonline.com/*' => Http::response(['error_description' => MAIL365_MS_TEXT], 401)]);
    $result = $connector->test($config);
    expect($result->ok)->toBeFalse()->and($result->message)->not->toContain('AADSTS');

    Http::swap((new Factory)->preventStrayRequests());
    Http::fake(['login.microsoftonline.com/*' => Http::response('', 503)]);
    expect($connector->test($config)->message)->toBe('Microsoft est injoignable pour le moment. Réessayez plus tard.');
});

describe('TenantMailer', function () {
    function mail365Transport(?Company $company): string
    {
        $transport = TenantMailer::for($company)->getSymfonyTransport();

        return match (true) {
            $transport instanceof GraphTransport => 'graph',
            $transport instanceof EsmtpTransport => (string) ($transport->getStream()->getStreamOptions()['ssl']['peer_name'] ?? 'smtp'),
            default => 'default',
        };
    }

    it('prefers the company SMTP, then Microsoft 365, then the organisme SMTP, then the instance mailer', function () {
        tenant();
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        expect(mail365Transport(null))->toBe('default');

        smtpConfig();
        expect(mail365Transport(null))->toBe('smtp.example.test');

        $config = mail365Config();
        expect(mail365Transport(null))->toBe('graph')
            ->and(mail365Transport($other))->toBe('graph')
            ->and(TenantMailer::activeConfig(null)?->id)->toBe($config->id);

        smtpConfig(['company_id' => $company->id, 'settings' => ['host' => 'smtp.company.test', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'c@cap.test', 'from_name' => 'C']]);
        expect(mail365Transport($company))->toBe('smtp.company.test')
            ->and(mail365Transport($other))->toBe('graph');

        $config->update(['enabled' => false]);
        expect(mail365Transport($other))->toBe('smtp.example.test');
    });

    it('ignores a Microsoft 365 configuration without its secret', function () {
        mail365Config()->forceFill(['secrets' => null])->save();

        expect(mail365Transport(null))->toBe('default');
    });

    it('delivers somewhere with Microsoft 365 even when the instance mailer only logs', function () {
        config(['mail.default' => 'log']);
        tenant();
        expect(TenantMailer::deliversNowhere(null))->toBeTrue();

        mail365Config();
        expect(TenantMailer::deliversNowhere(null))->toBeFalse();
    });
});

describe('quote e-mails through Microsoft 365', function () {
    beforeEach(function () {
        CrmServiceProvider::registerReferenceUsage();
        CapFormationReferenceSeeder::run(tenant());
        fakeImportDisk();
    });

    /** Graph stubs plus the PDF renderer of the quotes. */
    function mail365QuoteHttp(mixed $sendMail): void
    {
        mail365Http([
            MAIL365_GRAPH.'/sendMail' => $sendMail,
            'gotenberg:3000/*' => Http::response('%PDF-1.7 devis', 200, ['Content-Type' => 'application/pdf']),
        ]);
        config(['documents.gotenberg_url' => 'http://gotenberg:3000']);
    }

    function mail365QueuedQuote(): SendQuote
    {
        Queue::fake();
        test()->actingAs(commercial());
        $quote = sendableQuote();
        test()->post("/crm/quotes/{$quote->id}/send", quoteMessage())->assertSessionHasNoErrors();

        $job = null;
        Queue::assertPushed(SendQuote::class, function (SendQuote $j) use (&$job) {
            $job = $j;

            return true;
        });

        return $job;
    }

    it('sends the quote and journals the run on the Microsoft 365 connector', function () {
        mail365QuoteHttp(Http::response('', 202));
        $config = mail365Config();
        $job = mail365QueuedQuote();

        app()->call([$job, 'handle']);

        $quote = Quote::query()->sole();
        expect($quote->email_status)->toBe('sent')
            ->and(base64_decode(mail365Requests(MAIL365_GRAPH.'/sendMail')[0]->body(), true))->toContain('CF-2026-0001.pdf');
        $run = ConnectorRun::query()->sole();
        expect($run->connector_config_id)->toBe($config->id)->and($run->status)->toBe('success');
    });

    it('marks the quote failed at once on a refusal, with the French message and no Microsoft text', function () {
        mail365QuoteHttp(Http::response(['error' => ['message' => MAIL365_MS_TEXT]], 403));
        mail365Config();
        $job = mail365QueuedQuote();

        app()->call([$job, 'handle']); // no exception: nothing to retry

        $quote = Quote::query()->sole();
        expect($quote->email_status)->toBe('failed')
            ->and($quote->email_error)->toBe('Microsoft 365 a refusé l\'envoi : vérifiez la permission Mail.Send et l\'adresse d\'expédition.');
        $run = ConnectorRun::query()->sole();
        expect($run->status)->toBe('failed')
            ->and($run->error)->toBe('Microsoft 365 a refusé l\'envoi : vérifiez la permission Mail.Send et l\'adresse d\'expédition.')
            ->and(json_encode($run->toArray()))->not->toContain('AADSTS');
    });

    it('retries the job on 429 and sends the e-mail once', function () {
        $busy = true;
        mail365QuoteHttp(function () use (&$busy) {
            return $busy ? Http::response(['error' => ['message' => MAIL365_MS_TEXT]], 429) : Http::response('', 202);
        });
        mail365Config();
        $job = mail365QueuedQuote();

        expect(fn () => app()->call([$job, 'handle']))->toThrow(QuoteMailFailed::class);
        $quote = Quote::query()->sole();
        expect($quote->email_status)->toBe('pending');

        $busy = false;
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']); // a replay after success sends nothing

        expect($quote->refresh()->email_status)->toBe('sent')
            ->and(collect(mail365Requests(MAIL365_GRAPH.'/sendMail'))->count())->toBe(2); // one refused, one accepted
    });
});

describe('brochure e-mails through Microsoft 365', function () {
    beforeEach(function () {
        CrmServiceProvider::registerReferenceUsage();
        CapFormationReferenceSeeder::run(tenant());
        fakeImportDisk();
    });

    it('sends a 15 Mo brochure whole through the draft and upload sessions', function () {
        $draft = MAIL365_GRAPH.'/messages/AAMkDraft';
        mail365Http([
            MAIL365_GRAPH.'/messages' => Http::response(['id' => 'AAMkDraft'], 201),
            $draft.'/attachments/createUploadSession' => Http::response(['uploadUrl' => MAIL365_UPLOAD], 201),
            $draft.'/send' => Http::response('', 202),
            'outlook.office.com/*' => Http::response('', 200),
        ]);
        mail365Config();
        $this->actingAs(commercial());
        $person = makePerson('Martin', ['first_name' => 'Claire', 'email' => 'claire.martin@example.test', 'is_individual' => true]);
        $content = '%PDF-1.7 '.random_bytes(15 * 1024 * 1024);
        $version = sendingBrochure('HACCP', $content);

        $this->post("/crm/people/{$person->id}/brochures/send", sendingPayload($version, $person->id))->assertSessionHasNoErrors();

        expect(BrochureSending::query()->sole()->email_status)->toBe('sent')
            ->and(implode('', array_map(fn (Request $r) => $r->body(), mail365Requests('https://outlook.office.com/'))))->toBe($content)
            ->and(mail365Requests(MAIL365_GRAPH.'/sendMail'))->toBe([]);
    });

    it('shows « Échec » with the French message when Microsoft refuses the sending', function () {
        mail365Http([MAIL365_GRAPH.'/sendMail' => Http::response(['error' => ['message' => MAIL365_MS_TEXT]], 401)]);
        mail365Config();
        $this->actingAs(commercial());
        $person = makePerson('Martin', ['first_name' => 'Claire', 'email' => 'claire.martin@example.test', 'is_individual' => true]);

        $this->post("/crm/people/{$person->id}/brochures/send", sendingPayload(sendingBrochure('HACCP'), $person->id))->assertSessionHasNoErrors();

        $sending = BrochureSending::query()->sole();
        expect($sending->email_status)->toBe('failed')
            ->and($sending->email_error)->toBe(GraphMailFailed::REFUSED);
        $response = $this->getJson("/crm/people/{$person->id}/timeline");
        expect(brochureEntry($response)['meta']['status_label'])->toBe('Échec')
            ->and($response->getContent())->not->toContain('AADSTS');
    });
});
