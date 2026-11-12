<?php

use App\Modules\Connectors\Core\ConnectorRegistry;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Smtp\SmtpConnector;
use App\Modules\Connectors\Smtp\SmtpHostPolicy;
use App\Modules\Connectors\Smtp\SmtpHostRefused;
use App\Modules\Connectors\Smtp\SmtpTransportFactory;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Companies\Company;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/** No network: names resolve to a public documentation address, except a few fixtures. */
beforeEach(function () {
    SmtpHostPolicy::resolveUsing(fn (string $host): array => match ($host) {
        'invalid.invalid' => [],
        'localhost' => ['127.0.0.1'],
        'rebind.example.test' => ['127.0.0.1'],
        'lan.example.test' => ['10.0.0.5'],
        'dual.example.test' => ['203.0.113.7', '::ffff:169.254.169.254'],
        default => ['203.0.113.10'],
    });
});
afterEach(fn () => SmtpHostPolicy::resolveUsing(null));

/** Fails like a real server rejecting the credentials: the raw text echoes the username. */
class ThrowingTransport implements TransportInterface
{
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        throw new TransportException('Failed to authenticate on SMTP server with username "smtpuser" using the following authenticators: "LOGIN". Authenticator "LOGIN" returned "535 raw-server-text".');
    }

    public function __toString(): string
    {
        return 'throwing://';
    }
}

/** Records messages instead of talking to a server. */
class RecordingTransport implements TransportInterface
{
    /** @var list<RawMessage> */
    public array $sent = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->sent[] = $message;

        return null;
    }

    public function __toString(): string
    {
        return 'recording://';
    }
}

function fakeSmtpTransport(): RecordingTransport
{
    $transport = new RecordingTransport;
    app()->instance(SmtpTransportFactory::class, new class($transport) extends SmtpTransportFactory
    {
        public function __construct(private readonly TransportInterface $fake) {}

        public function check(array $settings): void {}

        public function make(array $settings, array $secrets): TransportInterface
        {
            return $this->fake;
        }
    });

    return $transport;
}

/** @return list<string> */
function smtpErrors(array $settings): array
{
    return array_keys(Validator::make(['settings' => $settings], collect((new SmtpConnector(new SmtpTransportFactory))->settingsRules())->mapWithKeys(fn ($r, $k) => ["settings.$k" => $r])->all())->errors()->toArray());
}

$valid = ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'a@cap.test', 'from_name' => 'CAP'];

it('declares smtp metadata', function () {
    expect(SmtpConnector::key())->toBe('smtp')
        ->and(SmtpConnector::kind()->value)->toBe('delivery')
        ->and((new SmtpConnector(new SmtpTransportFactory))->secretKeys())->toBe(['username', 'password']);
});

it('accepts valid smtp settings', function () use ($valid) {
    expect(smtpErrors($valid))->toBe([])
        ->and(smtpErrors([...$valid, 'host' => '203.0.113.7', 'from_name' => null]))->toBe([]);
});

it('rejects hosts with scheme, path, port or spaces', function (string $host) use ($valid) {
    expect(smtpErrors([...$valid, 'host' => $host]))->toContain('settings.host');
})->with(['smtp://mail.test', 'mail.test/path', 'mail.test:25', 'mail test', 'mail.test?x=1', '-bad.test', 'user@mail.test']);

it('rejects loopback, link-local and unspecified addresses', function (string $host) use ($valid) {
    config(['tms.connectors.allow_internal_hosts' => false]);
    expect(smtpErrors([...$valid, 'host' => $host]))->toContain('settings.host');
})->with(['127.0.0.1', '169.254.169.254', '0.0.0.0', '::1', 'localhost', 'rebind.example.test', 'dual.example.test', '::ffff:127.0.0.1', '::ffff:169.254.169.254', '::ffff:7f00:1', '::127.0.0.1', '64:ff9b::7f00:1', '100.64.0.1', '224.0.0.1', 'fe80::1', '127.1', '2130706433', '0177.0.0.1']);

it('classifies address forms', function (string $ip, ?string $class) {
    expect(SmtpHostPolicy::classify($ip))->toBe($class);
})->with([['203.0.113.7', null], ['2001:db8::1', null], ['10.0.0.5', 'private'], ['172.20.1.1', 'private'], ['192.168.1.1', 'private'], ['fd00::1', 'private'], ['::ffff:10.0.0.5', 'private'], ['172.32.0.1', null]]);

it('refuses private networks in multi mode but not in single mode', function () use ($valid) {
    config(['tms.connectors.allow_private_hosts' => false]);
    expect(smtpErrors([...$valid, 'host' => '10.0.0.5']))->toContain('settings.host')
        ->and(smtpErrors([...$valid, 'host' => 'lan.example.test']))->toContain('settings.host');

    config(['tms.connectors.allow_private_hosts' => true]);
    expect(smtpErrors([...$valid, 'host' => '10.0.0.5']))->toBe([])
        ->and(smtpErrors([...$valid, 'host' => 'lan.example.test']))->toBe([])
        ->and(smtpErrors([...$valid, 'host' => '127.0.0.1']))->toContain('settings.host');
});

it('defaults private hosts to the install mode', function () {
    expect(config('tms.mode'))->toBe('single')->and(config('tms.connectors.allow_private_hosts'))->toBeTrue();
});

it('refuses a stored internal host at send time', function () {
    config(['tms.connectors.allow_internal_hosts' => false]);
    $base = ['port' => 587, 'encryption' => 'tls', 'from_address' => 'a@cap.test', 'from_name' => 'C'];

    foreach (['127.0.0.1', '::ffff:169.254.169.254', 'localhost', 'rebind.example.test', 'invalid.invalid'] as $host) {
        expect(fn () => (new SmtpTransportFactory)->make([...$base, 'host' => $host], []))->toThrow(SmtpHostRefused::class);
    }
    config(['tms.connectors.allow_private_hosts' => false]);
    expect(fn () => (new SmtpTransportFactory)->make([...$base, 'host' => 'lan.example.test'], []))->toThrow(SmtpHostRefused::class);

    tenant();
    smtpConfig(['settings' => [...$base, 'host' => 'rebind.example.test']]);
    expect(fn () => TenantMailer::for(null))->toThrow(SmtpHostRefused::class);
});

it('never relays raw transport errors to the admin', function () {
    app()->instance(SmtpTransportFactory::class, new class extends SmtpTransportFactory
    {
        public function make(array $settings, array $secrets): TransportInterface
        {
            return new ThrowingTransport;
        }
    });
    smtpConfig(['secrets' => ['username' => 'smtpuser', 'password' => 'pw-s3cret']]);
    $this->actingAs(admin(twoFactor: true));

    $response = $this->postJson('/admin/connectors/smtp/test')->assertOk()->assertJson(['ok' => false])
        ->assertDontSee('smtpuser')->assertDontSee('raw-server-text')->assertDontSee('535');
    expect($response->json('message'))->toContain('Authentification refusée');

    $error = (string) ConnectorRun::query()->sole()->error;
    expect($error)->not->toContain('smtpuser')->not->toContain('raw-server-text')->toContain('Authentification');
});

it('allows internal addresses when the install opts in', function () use ($valid) {
    config(['tms.connectors.allow_internal_hosts' => true]);
    expect(smtpErrors([...$valid, 'host' => '127.0.0.1']))->toBe([]);
});

it('rejects an invalid port, encryption or sender', function () use ($valid) {
    expect(smtpErrors([...$valid, 'port' => 0]))->toContain('settings.port')
        ->and(smtpErrors([...$valid, 'port' => 70000]))->toContain('settings.port')
        ->and(smtpErrors([...$valid, 'encryption' => 'starttls']))->toContain('settings.encryption')
        ->and(smtpErrors([...$valid, 'from_address' => 'nope']))->toContain('settings.from_address');
});

it('reports a failure in French on an unresolvable host', function () {
    $config = smtpConfig(['settings' => ['host' => 'invalid.invalid', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'a@cap.test', 'from_name' => 'CAP']]);

    $result = app(ConnectorRegistry::class)->make('smtp')->test($config);

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('invalid.invalid')
        ->and($result->message)->not->toContain('s3cret');
});

it('sends a test mail to the current user on the success path', function () {
    $transport = fakeSmtpTransport();
    $user = admin(twoFactor: true);
    $this->actingAs($user);

    $result = app(ConnectorRegistry::class)->make('smtp')->test(smtpConfig());

    expect($result->ok)->toBeTrue()->and($result->message)->toContain($user->email);
    expect($transport->sent)->toHaveCount(1);
    $raw = $transport->sent[0]->toString();
    expect($raw)->toContain($user->email)->toContain('no-reply@cap.test');
});

it('the test endpoint records a run and never leaks secrets', function () {
    smtpConfig(['settings' => ['host' => 'invalid.invalid', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'a@cap.test', 'from_name' => 'CAP']]);
    $this->actingAs(admin(twoFactor: true));

    $this->postJson('/admin/connectors/smtp/test')
        ->assertOk()->assertJson(['ok' => false])->assertDontSee('s3cret');

    $run = ConnectorRun::query()->sole();
    expect($run->status)->toBe('failed')->and($run->error)->not->toBeEmpty();
});

it('the test endpoint requires a saved configuration', function () {
    $this->actingAs(admin(twoFactor: true))->postJson('/admin/connectors/smtp/test')->assertStatus(422);
});

it('the test endpoint succeeds with a working transport', function () {
    fakeSmtpTransport();
    smtpConfig();

    $this->actingAs(admin(twoFactor: true))->postJson('/admin/connectors/smtp/test')->assertOk()->assertJson(['ok' => true]);
    expect(ConnectorRun::query()->sole()->status)->toBe('success');
});

it('builds the transport from the configuration with a bounded timeout', function () {
    $transport = (new SmtpTransportFactory)->make(
        ['host' => '203.0.113.9', 'port' => 2525, 'encryption' => 'none'],
        ['username' => 'u', 'password' => 'p'],
    );

    expect($transport)->toBeInstanceOf(EsmtpTransport::class)
        ->and($transport->getStream()->getTimeout())->toBeLessThanOrEqual(10);
});

describe('TenantMailer', function () {
    function mailerHost(?Company $company): string
    {
        $transport = TenantMailer::for($company)->getSymfonyTransport();

        return $transport instanceof EsmtpTransport ? $transport->getStream()->getHost() : 'default';
    }

    it('falls back to the instance mailer without configuration', function () {
        tenant();

        expect(mailerHost(null))->toBe('default');
    });

    it('uses the tenant-wide configuration', function () {
        smtpConfig();

        expect(mailerHost(null))->toBe('smtp.example.test')
            ->and(mailerHost(Company::factory()->create()))->toBe('smtp.example.test');
    });

    it('prefers the company configuration, then the tenant-wide one', function () {
        tenant();
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        smtpConfig();
        smtpConfig(['company_id' => $company->id, 'settings' => ['host' => 'smtp.company.test', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'c@cap.test', 'from_name' => 'C']]);

        expect(mailerHost($company))->toBe('smtp.company.test')
            ->and(mailerHost($other))->toBe('smtp.example.test')
            ->and(mailerHost(null))->toBe('smtp.example.test');
    });

    it('ignores disabled configurations', function () {
        smtpConfig(['enabled' => false]);

        expect(mailerHost(null))->toBe('default');
    });
});
