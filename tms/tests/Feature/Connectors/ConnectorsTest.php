<?php

use App\Modules\Connectors\Core\ConnectionTestResult;
use App\Modules\Connectors\Core\Connector;
use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorKind;
use App\Modules\Connectors\Core\ConnectorRegistry;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Core\Retryable;
use App\Modules\Connectors\Smtp\SmtpHostPolicy;
use App\Modules\Core\Audit\AuditLog;
use App\Modules\Core\Companies\Company;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => SmtpHostPolicy::resolveUsing(fn (string $host): array => ['203.0.113.10']));
afterEach(fn () => SmtpHostPolicy::resolveUsing(null));

/**
 * @param  array<string, mixed>  $overrides
 */
function smtpConfig(array $overrides = []): ConnectorConfig
{
    tenant();

    return ConnectorConfig::create(array_merge([
        'connector_key' => 'smtp',
        'enabled' => true,
        'settings' => ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'no-reply@cap.test', 'from_name' => 'CAP'],
        'secrets' => ['username' => 'cap-user', 'password' => 's3cret'],
    ], $overrides));
}

/** @return array<string, mixed> */
function smtpForm(array $overrides = []): array
{
    return array_merge([
        'enabled' => true,
        'settings' => ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'no-reply@cap.test', 'from_name' => 'CAP'],
        'secrets' => ['username' => 'cap-user', 'password' => 's3cret'],
    ], $overrides);
}

/** A retryable connector used to exercise the "relancer" rules. */
class FakeRetryableConnector implements Connector, Retryable
{
    public static int $retries = 0;

    public static function key(): string
    {
        return 'fake_sync';
    }

    public static function label(): string
    {
        return 'Fake';
    }

    public static function kind(): ConnectorKind
    {
        return ConnectorKind::Sync;
    }

    public function settingsRules(): array
    {
        return ['endpoint' => ['required', 'string']];
    }

    public function secretKeys(): array
    {
        return ['token'];
    }

    public function test(ConnectorConfig $c): ConnectionTestResult
    {
        return new ConnectionTestResult(true, 'OK');
    }

    public function retry(ConnectorRun $run): void
    {
        self::$retries++;
    }
}

it('stores connector secrets encrypted', function () {
    smtpConfig();

    $raw = (string) DB::table('connector_configs')->value('secrets');
    expect($raw)->not->toContain('s3cret')->and($raw)->not->toContain('cap-user');
    expect(ConnectorConfig::query()->sole()->secrets)->toBe(['username' => 'cap-user', 'password' => 's3cret']);
});

it('never exposes secrets to the UI', function () {
    smtpConfig();

    $this->actingAs(admin(twoFactor: true))->get('/admin/connectors/smtp')
        ->assertOk()
        ->assertDontSee('s3cret')
        ->assertDontSee('cap-user')
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/connectors/edit')
            ->where('config.secrets_set', ['username' => true, 'password' => true])
            ->missing('config.secrets')
            ->where('config.settings.host', 'smtp.example.test'));
});

it('never serialises secrets', function () {
    expect(smtpConfig()->toArray())->not->toHaveKey('secrets');
});

it('rejects a second tenant-wide configuration of the same connector', function () {
    smtpConfig();

    expect(fn () => smtpConfig())->toThrow(UniqueConstraintViolationException::class);
});

it('allows one configuration per company next to the tenant-wide one', function () {
    tenant();
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    smtpConfig();
    smtpConfig(['company_id' => $company->id]);
    smtpConfig(['company_id' => $other->id]);

    expect(fn () => smtpConfig(['company_id' => $company->id]))->toThrow(UniqueConstraintViolationException::class);
});

it('lists the smtp connector in the registry', function () {
    $registry = app(ConnectorRegistry::class);

    expect($registry->all())->toHaveKey('smtp')
        ->and($registry->make('smtp')->kind())->toBe(ConnectorKind::Delivery);
    expect(fn () => $registry->make('inconnu'))->toThrow(InvalidArgumentException::class);
});

it('tracks successful runs', function () {
    $run = ConnectorRun::track(smtpConfig(), 'out', fn () => '12 lignes exportées', 'export:42');

    expect($run->status)->toBe('success')
        ->and($run->summary)->toBe('12 lignes exportées')
        ->and($run->direction)->toBe('out')
        ->and($run->payload_ref)->toBe('export:42')
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull();
});

it('tracks failed runs with error message', function () {
    $run = ConnectorRun::track(smtpConfig(), 'out', fn () => throw new RuntimeException('Connexion refusée'));

    expect($run->status)->toBe('failed')->and($run->error)->toContain('Connexion refusée')->and($run->finished_at)->not->toBeNull();
    expect(ConnectorRun::query()->sole()->status)->toBe('failed');
});

it('truncates long errors and masks the secrets of the configuration', function () {
    $run = ConnectorRun::track(smtpConfig(), 'in', fn () => throw new RuntimeException('auth s3cret '.str_repeat('x', 5000)));

    expect(mb_strlen((string) $run->error))->toBeLessThanOrEqual(2000)
        ->and($run->error)->not->toContain('s3cret');
});

it('rejects an invalid run direction', function () {
    expect(fn () => ConnectorRun::track(smtpConfig(), 'sideways', fn () => null))->toThrow(InvalidArgumentException::class);
});

it('isolates runs and configurations between tenants', function () {
    $run = ConnectorRun::track(smtpConfig(), 'out', fn () => 'ok');
    $other = tenantOnHost('autre.test');

    app(TenantContext::class)->run($other, function () use ($run) {
        expect(ConnectorRun::query()->count())->toBe(0)->and(ConnectorConfig::query()->count())->toBe(0);
        expect(ConnectorRun::query()->find($run->id))->toBeNull();
    });
});

it('only connectors.manage may configure', function () {
    tenant();
    smtpConfig();
    $run = ConnectorRun::track(smtpConfig(['company_id' => Company::factory()->create()->id]), 'out', fn () => throw new RuntimeException('x'), 'p:1');

    $this->actingAs(direction(twoFactor: true));
    $this->get('/admin/connectors')->assertForbidden();
    $this->get('/admin/connectors/smtp')->assertForbidden();
    $this->putJson('/admin/connectors/smtp', smtpForm())->assertForbidden();
    $this->postJson('/admin/connectors/smtp/test')->assertForbidden();
    $this->deleteJson('/admin/connectors/smtp/secrets/password')->assertForbidden();
    $this->postJson("/admin/connectors/runs/{$run->id}/retry")->assertForbidden();
});

it('requires authentication', function () {
    $this->get('/admin/connectors')->assertRedirect('/login');
});

it('lists connectors and recent runs for an admin', function () {
    ConnectorRun::track(smtpConfig(), 'out', fn () => 'ok');

    $this->actingAs(admin(twoFactor: true))->get('/admin/connectors')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/connectors/index')
            ->where('connectors.0.key', 'smtp')
            ->where('connectors.0.configured', true)
            ->has('runs', 1));
});

it('creates a configuration and keeps stored secrets when the field is left empty', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->putJson('/admin/connectors/smtp', smtpForm())->assertRedirect();
    $config = ConnectorConfig::query()->sole();
    expect($config->secrets['password'])->toBe('s3cret')->and($config->company_id)->toBeNull();

    $this->putJson('/admin/connectors/smtp', smtpForm(['secrets' => ['username' => '', 'password' => ''], 'settings' => ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'ssl', 'from_address' => 'a@cap.test', 'from_name' => '']]))->assertRedirect();
    $config->refresh();
    expect($config->secrets)->toBe(['username' => 'cap-user', 'password' => 's3cret'])
        ->and($config->settings['encryption'])->toBe('ssl')
        ->and(ConnectorConfig::query()->count())->toBe(1);

    $this->putJson('/admin/connectors/smtp', smtpForm(['secrets' => ['password' => 'n3w']]))->assertRedirect();
    expect($config->refresh()->secrets)->toBe(['username' => 'cap-user', 'password' => 'n3w']);
});

it('requires the stored secrets again when the host or the port changes', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->putJson('/admin/connectors/smtp', smtpForm())->assertRedirect();
    $config = ConnectorConfig::query()->sole();

    $moved = fn (array $settings, array $secrets) => smtpForm(['settings' => [...smtpForm()['settings'], ...$settings], 'secrets' => $secrets]);

    // The stored password must never be sent to another server without being typed again.
    $this->putJson('/admin/connectors/smtp', $moved(['host' => 'evil.example.test'], ['username' => '', 'password' => '']))
        ->assertUnprocessable()->assertJsonValidationErrors(['secrets.username', 'secrets.password']);
    $this->putJson('/admin/connectors/smtp', $moved(['port' => 2525], ['username' => 'cap-user', 'password' => '']))
        ->assertUnprocessable()->assertJsonValidationErrors(['secrets.password']);
    expect($config->refresh()->settings['host'])->toBe('smtp.example.test')
        ->and($config->settings['port'])->toBe(587);

    $this->putJson('/admin/connectors/smtp', $moved(['host' => 'mail.cap.test', 'port' => 465], ['username' => 'cap-user', 'password' => 'n3w']))->assertRedirect();
    expect($config->refresh()->settings['host'])->toBe('mail.cap.test')
        ->and($config->secrets['password'])->toBe('n3w');
});

it('clears one secret on explicit request only', function () {
    smtpConfig();
    $this->actingAs(admin(twoFactor: true));

    $this->deleteJson('/admin/connectors/smtp/secrets/password')->assertRedirect();
    expect(ConnectorConfig::query()->sole()->secrets)->toBe(['username' => 'cap-user']);

    $this->deleteJson('/admin/connectors/smtp/secrets/inconnu')->assertNotFound();
});

it('scopes a configuration to a company of the tenant only', function () {
    tenant();
    $company = Company::factory()->create();
    $foreign = app(TenantContext::class)->run(tenantOnHost('x.test'), fn () => Company::factory()->create());
    $this->actingAs(admin(twoFactor: true));

    $this->putJson('/admin/connectors/smtp', smtpForm(['company_id' => $company->id]))->assertRedirect();
    expect(ConnectorConfig::query()->sole()->company_id)->toBe($company->id);

    $this->putJson('/admin/connectors/smtp', smtpForm(['company_id' => $foreign->id]))->assertStatus(422)->assertJsonValidationErrors('company_id');
});

it('validates settings with the connector rules and ignores unknown keys', function () {
    $this->actingAs(admin(twoFactor: true));

    $this->putJson('/admin/connectors/smtp', smtpForm(['settings' => ['host' => '', 'port' => 'abc']]))
        ->assertStatus(422)->assertJsonValidationErrors(['settings.host', 'settings.port']);

    $this->putJson('/admin/connectors/smtp', smtpForm(['settings' => [...smtpForm()['settings'], 'evil' => 'x'], 'secrets' => ['password' => 'p', 'other' => 'y']]))->assertRedirect();
    $config = ConnectorConfig::query()->sole();
    expect($config->settings)->not->toHaveKey('evil')->and($config->secrets)->not->toHaveKey('other');
});

it('returns 404 for an unknown connector', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->get('/admin/connectors/inconnu')->assertNotFound();
    $this->putJson('/admin/connectors/inconnu', [])->assertNotFound();
});

it('audits configuration changes without ever recording a secret value', function () {
    $this->actingAs(admin(twoFactor: true));
    $this->putJson('/admin/connectors/smtp', smtpForm())->assertRedirect();
    $this->putJson('/admin/connectors/smtp', smtpForm(['secrets' => ['password' => 'rotated-s3cret']]))->assertRedirect();

    $logs = AuditLog::query()->where('auditable_type', (new ConnectorConfig)->getMorphClass())->get();
    expect($logs)->not->toBeEmpty();
    $dump = json_encode($logs->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    expect($dump)->not->toContain('s3cret')->and($dump)->not->toContain('cap-user')->and($dump)->toContain('[masqué]');
});

describe('relancer', function () {
    beforeEach(function () {
        FakeRetryableConnector::$retries = 0;
        app(ConnectorRegistry::class)->register(FakeRetryableConnector::class);
    });

    function failedRun(string $key = 'fake_sync', ?string $payload = 'job:7'): ConnectorRun
    {
        tenant();
        $config = ConnectorConfig::create(['connector_key' => $key, 'settings' => ['endpoint' => 'x']]);

        return ConnectorRun::track($config, 'out', fn () => throw new RuntimeException('boom'), $payload);
    }

    it('re-dispatches a failed run that kept its payload reference', function () {
        $run = failedRun();
        $this->actingAs(admin(twoFactor: true))->postJson("/admin/connectors/runs/{$run->id}/retry")->assertRedirect();

        expect(FakeRetryableConnector::$retries)->toBe(1);
        $latest = ConnectorRun::query()->latest('started_at')->first();
        expect(ConnectorRun::query()->count())->toBe(2)->and($latest->status)->toBe('success')->and($latest->payload_ref)->toBe('job:7');
    });

    it('refuses a run without payload reference', function () {
        $run = failedRun(payload: null);
        $this->actingAs(admin(twoFactor: true))->postJson("/admin/connectors/runs/{$run->id}/retry")->assertStatus(422);
        expect(FakeRetryableConnector::$retries)->toBe(0);
    });

    it('refuses a run that did not fail', function () {
        tenant();
        $config = ConnectorConfig::create(['connector_key' => 'fake_sync', 'settings' => ['endpoint' => 'x']]);
        $run = ConnectorRun::track($config, 'out', fn () => 'ok', 'job:1');
        $this->actingAs(admin(twoFactor: true))->postJson("/admin/connectors/runs/{$run->id}/retry")->assertStatus(422);
        expect(FakeRetryableConnector::$retries)->toBe(0);
    });

    it('refuses a connector that cannot be retried', function () {
        $run = ConnectorRun::track(smtpConfig(), 'out', fn () => throw new RuntimeException('x'), 'job:2');
        $this->actingAs(admin(twoFactor: true))->postJson("/admin/connectors/runs/{$run->id}/retry")->assertStatus(422);
    });

    it('exposes retryable only for failed runs with payload on a retryable connector', function () {
        failedRun();
        $this->actingAs(admin(twoFactor: true))->get('/admin/connectors')
            ->assertInertia(fn (Assert $page) => $page->where('runs.0.retryable', true));
    });

    it('cannot reach a run of another tenant', function () {
        $run = failedRun();
        $other = tenantOnHost('autre.test');

        app(TenantContext::class)->run($other, fn () => expect(ConnectorRun::query()->find($run->id))->toBeNull());
    });
});

it('shows the configuration of the selected company only', function () {
    tenant();
    $company = Company::factory()->create();
    smtpConfig();
    smtpConfig(['company_id' => $company->id, 'settings' => ['host' => 'smtp.company.test', 'port' => 25, 'encryption' => 'none', 'from_address' => 'c@cap.test', 'from_name' => 'C'], 'secrets' => null]);
    $this->actingAs(admin(twoFactor: true));

    $this->get("/admin/connectors/smtp?company={$company->id}")->assertInertia(fn (Assert $page) => $page
        ->where('companyId', $company->id)
        ->where('config.settings.host', 'smtp.company.test')
        ->where('config.secrets_set', ['username' => false, 'password' => false]));

    $this->get('/admin/connectors/smtp?company='.Str::uuid())->assertNotFound();
});
