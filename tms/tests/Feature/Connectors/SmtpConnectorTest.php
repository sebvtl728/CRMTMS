<?php

use App\Modules\Connectors\Core\ConnectorRegistry;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Smtp\SmtpConnector;
use App\Modules\Connectors\Smtp\SmtpTransportFactory;
use App\Modules\Connectors\Smtp\TenantMailer;
use App\Modules\Core\Companies\Company;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

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
})->with(['127.0.0.1', '169.254.169.254', '0.0.0.0', '::1']);

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
