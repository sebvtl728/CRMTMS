<?php

use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorRegistry;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Core\HostPolicy;
use App\Modules\Connectors\Sirene\InseeCodes;
use App\Modules\Connectors\Sirene\SireneClient;
use App\Modules\Connectors\Sirene\SireneConnector;
use App\Modules\Connectors\Sirene\SireneUnavailable;
use App\Modules\Core\Access\Role;
use App\Modules\Core\References\CapFormationReferenceSeeder;
use App\Modules\Core\References\ReferenceList;
use App\Modules\Crm\CrmServiceProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

const SIEGE_SIRET = '32230644000213';
const AGENCY_SIRET = '32230644000239';
const UNKNOWN_SIRET = '73282932000074';

beforeEach(function () {
    CrmServiceProvider::registerReferenceUsage();
    CapFormationReferenceSeeder::run(tenant());
    HostPolicy::resolveUsing(fn (string $host): array => ['203.0.113.10']);
    Cache::flush();
});
afterEach(fn () => HostPolicy::resolveUsing(null));

function sireneFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/sirene/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
}

/** Starts from a fresh fake: Http::fake() alone keeps the first matching stub. */
function freshHttp(): void
{
    Http::swap((new Factory)->preventStrayRequests());
}

function fakeSirene(string $fixture = 'dassault-systemes'): void
{
    freshHttp();
    Http::fake(['recherche-entreprises.api.gouv.fr/*' => Http::response(sireneFixture($fixture))]);
}

it('prefills an entreprise from a known siret', function () {
    fakeSirene();

    $this->actingAs(commercial())->getJson('/crm/sirene/'.SIEGE_SIRET)
        ->assertOk()
        ->assertJsonPath('data.name', 'DASSAULT SYSTEMES')
        ->assertJsonPath('data.siret', SIEGE_SIRET)
        ->assertJsonPath('data.naf_code', '5829C')
        ->assertJsonPath('data.address_line1', '10 RUE MARCEL DASSAULT')
        ->assertJsonPath('data.postal_code', '78140')
        ->assertJsonPath('data.city', 'VELIZY-VILLACOUBLAY')
        ->assertJsonPath('data.legal_form_label', null)
        ->assertJsonPath('data.employee_range_label', '2 000 à 4 999 salariés')
        ->assertJsonPath('data.company_size_label', '250 et plus');

    Http::assertSent(fn (Request $r) => str_starts_with((string) $r->url(), 'https://recherche-entreprises.api.gouv.fr/search?')
        && $r['q'] === SIEGE_SIRET);
});

it('connects to the address it checked, never resolving the name again (dns rebinding)', function () {
    freshHttp();
    $pinned = null;
    Http::fake(function (Request $request, array $options) use (&$pinned) {
        $pinned = $options['curl'][CURLOPT_RESOLVE] ?? null;

        return Http::response(sireneFixture('dassault-systemes'));
    });

    $this->actingAs(commercial())->getJson('/crm/sirene/'.SIEGE_SIRET)->assertOk();

    expect($pinned)->toBe(['recherche-entreprises.api.gouv.fr:443:203.0.113.10']);
});

it('uses the establishment that carries the siret, not the head office', function () {
    fakeSirene();

    $this->actingAs(commercial())->getJson('/crm/sirene/'.AGENCY_SIRET)
        ->assertOk()
        ->assertJsonPath('data.siret', AGENCY_SIRET)
        ->assertJsonPath('data.postal_code', '31770')
        ->assertJsonPath('data.city', 'COLOMIERS')
        ->assertJsonPath('data.employee_range_label', '100 à 199 salariés')
        ->assertJsonPath('data.company_size_label', '50-249');
});

it('maps the legal form and size onto the organisme references by approximate label', function () {
    $body = sireneFixture('dassault-systemes');
    $body['results'][0]['nature_juridique'] = '5710'; // SAS
    $body['results'][0]['siege']['tranche_effectif_salarie'] = '12'; // 20 à 49
    freshHttp();
    Http::fake(['*' => Http::response($body)]);

    $this->actingAs(commercial())->getJson('/crm/sirene/'.SIEGE_SIRET)
        ->assertOk()
        ->assertJsonPath('data.legal_form_label', 'SAS')
        ->assertJsonPath('data.legal_form_id', crmItem(ReferenceList::LegalFormOrg, 'SAS')->id)
        ->assertJsonPath('data.company_size_id', crmItem(ReferenceList::CompanySize, '10-49')->id);
});

it('leaves references empty when no label matches', function () {
    fakeSirene(); // legal form 5800 (société européenne) has no reference

    $this->actingAs(commercial())->getJson('/crm/sirene/'.SIEGE_SIRET)
        ->assertOk()
        ->assertJsonPath('data.legal_form_id', null);
});

it('answers 404 in French for an unknown siret', function () {
    fakeSirene('not-found');

    $this->actingAs(commercial())->getJson('/crm/sirene/'.UNKNOWN_SIRET)
        ->assertNotFound()
        ->assertJsonPath('message', 'Aucune entreprise trouvée pour ce SIRET.');
});

it('answers 503 in French when the directory is down, for a server error and a timeout', function () {
    freshHttp();
    Http::fake(['*' => Http::response('boom', 500)]);
    $this->actingAs(commercial())->getJson('/crm/sirene/'.SIEGE_SIRET)
        ->assertStatus(503)
        ->assertJsonPath('message', 'Service de l\'annuaire indisponible, saisissez les informations manuellement.');

    freshHttp();
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);
    $this->getJson('/crm/sirene/'.SIEGE_SIRET)->assertStatus(503);
});

it('answers 503 on a rate limited or malformed response and does not follow redirects', function () {
    freshHttp();
    Http::fake(['*' => Http::response('', 429)]);
    $this->actingAs(commercial())->getJson('/crm/sirene/'.SIEGE_SIRET)->assertStatus(503);

    freshHttp();
    Http::fake(['*' => Http::response('<html>not json</html>', 200)]);
    $this->getJson('/crm/sirene/'.SIEGE_SIRET)->assertStatus(503);

    freshHttp();
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);
    $this->getJson('/crm/sirene/'.SIEGE_SIRET)->assertStatus(503);
    Http::assertNotSent(fn (Request $r) => str_contains((string) $r->url(), '169.254'));
});

it('caches a successful lookup for 24 hours under a tenant prefixed key', function () {
    fakeSirene();
    $user = commercial();
    $key = 'tenant:'.tenant()->id.':sirene:'.SIEGE_SIRET;

    $this->actingAs($user)->getJson('/crm/sirene/'.SIEGE_SIRET)->assertOk();
    expect(Cache::has($key))->toBeTrue();

    $this->getJson('/crm/sirene/'.SIEGE_SIRET)->assertOk()->assertJsonPath('data.name', 'DASSAULT SYSTEMES');
    Http::assertSentCount(1);

    $this->travel(24 * 60 + 1)->minutes();
    expect(Cache::has($key))->toBeFalse();
});

it('caches "not found" for one hour only and never caches errors', function () {
    fakeSirene('not-found');
    $key = 'tenant:'.tenant()->id.':sirene:'.UNKNOWN_SIRET;

    $this->actingAs(commercial())->getJson('/crm/sirene/'.UNKNOWN_SIRET)->assertNotFound();
    $this->getJson('/crm/sirene/'.UNKNOWN_SIRET)->assertNotFound();
    Http::assertSentCount(1);
    $this->travel(61)->minutes();
    expect(Cache::has($key))->toBeFalse();

    freshHttp();
    Http::fake(['*' => Http::response('boom', 500)]);
    $this->getJson('/crm/sirene/'.SIEGE_SIRET)->assertStatus(503);
    expect(Cache::has('tenant:'.tenant()->id.':sirene:'.SIEGE_SIRET))->toBeFalse();
    fakeSirene();
    $this->getJson('/crm/sirene/'.SIEGE_SIRET)->assertOk();
});

it('does not cache sole traders (natural persons)', function () {
    $body = sireneFixture('dassault-systemes');
    $body['results'][0]['nature_juridique'] = '1000';
    freshHttp();
    Http::fake(['*' => Http::response($body)]);

    $this->actingAs(commercial())->getJson('/crm/sirene/'.SIEGE_SIRET)->assertOk();
    expect(Cache::has('tenant:'.tenant()->id.':sirene:'.SIEGE_SIRET))->toBeFalse();
});

it('keeps the cache of one organisme away from another', function () {
    fakeSirene();
    $this->actingAs(commercial())->getJson('/crm/sirene/'.SIEGE_SIRET)->assertOk();

    $other = tenantOnHost('autre.tms.test');
    expect(Cache::has('tenant:'.$other->id.':sirene:'.SIEGE_SIRET))->toBeFalse();
});

it('journals each remote lookup with the siret and no company or people data', function () {
    fakeSirene();

    $this->actingAs(commercial())->getJson('/crm/sirene/'.SIEGE_SIRET)->assertOk();
    $this->getJson('/crm/sirene/'.SIEGE_SIRET)->assertOk(); // cache hit: not journaled

    $run = ConnectorRun::query()->sole();
    expect($run->status)->toBe('success')
        ->and($run->direction)->toBe('in')
        ->and($run->config->connector_key)->toBe('sirene')
        ->and($run->summary)->toContain(SIEGE_SIRET)
        ->and($run->summary.$run->error)->not->toContain('DASSAULT')
        ->and(json_encode($run->getAttributes()))->not->toContain('CAUCHOIS')
        ->and($run->payload_ref)->toBeNull();
});

it('journals failures and not found', function () {
    freshHttp();
    Http::fake(['*' => Http::response('boom', 500)]);
    $this->actingAs(commercial())->getJson('/crm/sirene/'.SIEGE_SIRET)->assertStatus(503);
    expect(ConnectorRun::query()->latest('started_at')->latest('id')->first()->status)->toBe('failed');

    fakeSirene('not-found');
    $this->getJson('/crm/sirene/'.UNKNOWN_SIRET)->assertNotFound();
    $run = ConnectorRun::query()->latest('started_at')->latest('id')->first();
    expect($run->status)->toBe('success')->and($run->summary)->toContain('aucune entreprise');
});

it('works without any tenant configuration and enables itself by default', function () {
    fakeSirene();
    expect(ConnectorConfig::query()->where('connector_key', 'sirene')->exists())->toBeFalse();

    $this->actingAs(commercial())->getJson('/crm/sirene/'.SIEGE_SIRET)->assertOk();

    $config = ConnectorConfig::query()->where('connector_key', 'sirene')->sole();
    expect($config->enabled)->toBeTrue();
});

it('can be disabled by the organisme, then calls nothing', function () {
    ConnectorConfig::create(['connector_key' => 'sirene', 'enabled' => false, 'settings' => []]);
    freshHttp();
    Http::fake();

    $this->actingAs(commercial())->getJson('/crm/sirene/'.SIEGE_SIRET)
        ->assertStatus(503)
        ->assertJsonPath('message', 'Le préremplissage par SIRET est désactivé pour votre organisme.');
    Http::assertNothingSent();
});

it('uses the base url configured by the organisme', function () {
    ConnectorConfig::create(['connector_key' => 'sirene', 'enabled' => true, 'settings' => ['base_url' => 'https://annuaire.example.test']]);
    Http::fake(['annuaire.example.test/*' => Http::response(sireneFixture('dassault-systemes'))]);

    $this->actingAs(commercial())->getJson('/crm/sirene/'.SIEGE_SIRET)->assertOk();
    Http::assertSent(fn (Request $r) => str_starts_with((string) $r->url(), 'https://annuaire.example.test/search'));
});

it('refuses to call a base url that resolves to a private or local address, even when private hosts are allowed', function () {
    config(['tms.connectors.allow_private_hosts' => true, 'tms.connectors.allow_internal_hosts' => true]);
    HostPolicy::resolveUsing(fn (string $host): array => ['10.0.0.5']);
    ConnectorConfig::create(['connector_key' => 'sirene', 'enabled' => true, 'settings' => ['base_url' => 'https://annuaire.example.test']]);
    freshHttp();
    Http::fake();

    $this->actingAs(commercial())->getJson('/crm/sirene/'.SIEGE_SIRET)->assertStatus(503);
    Http::assertNothingSent();

    HostPolicy::resolveUsing(fn (string $host): array => ['127.0.0.1']);
    $this->getJson('/crm/sirene/'.SIEGE_SIRET)->assertStatus(503);
    Http::assertNothingSent();
});

it('validates the base url setting: https and a public host only', function () {
    $rules = (new SireneConnector(app(SireneClient::class)))->settingsRules();
    $check = fn (?string $url) => Validator::make(['base_url' => $url], ['base_url' => $rules['base_url']])->passes();

    expect($check('https://recherche-entreprises.api.gouv.fr'))->toBeTrue()
        ->and($check(null))->toBeTrue() // empty = default
        ->and($check('http://recherche-entreprises.api.gouv.fr'))->toBeFalse()
        ->and($check('https://user:pw@annuaire.example.test'))->toBeFalse()
        ->and($check('https://annuaire.example.test:8443'))->toBeFalse()
        ->and($check('https://127.0.0.1'))->toBeFalse()
        ->and($check('https://169.254.169.254'))->toBeFalse()
        ->and($check('ftp://annuaire.example.test'))->toBeFalse()
        ->and($check('annuaire.example.test'))->toBeFalse();

    config(['tms.connectors.allow_private_hosts' => true]);
    expect($check('https://10.0.0.5'))->toBeFalse();

    HostPolicy::resolveUsing(fn (string $host): array => ['10.0.0.5']);
    expect($check('https://annuaire.example.test'))->toBeFalse();
});

it('is registered as an import connector without secrets', function () {
    $connector = app(ConnectorRegistry::class)->make('sirene');

    expect($connector)->toBeInstanceOf(SireneConnector::class)
        ->and($connector::kind()->value)->toBe('import')
        ->and($connector->secretKeys())->toBe([]);
});

it('tests the connection against the directory', function () {
    fakeSirene();
    $config = ConnectorConfig::create(['connector_key' => 'sirene', 'enabled' => true, 'settings' => []]);
    $connector = app(ConnectorRegistry::class)->make('sirene');

    expect($connector->test($config)->ok)->toBeTrue();

    freshHttp();
    Http::fake(['*' => Http::response('boom', 500)]);
    expect($connector->test($config)->ok)->toBeFalse();
});

it('rejects an invalid siret with 422', function (string $siret) {
    freshHttp();
    Http::fake();

    $this->actingAs(commercial())->getJson('/crm/sirene/'.$siret)->assertStatus(422);
    Http::assertNothingSent();
})->with(['bad luhn' => '32230644000214', 'too short' => '123', 'letters' => 'ABCDEFGHIJKLMN', 'spaces' => '3223 0644 000213']);

it('requires login and the create or update permission', function () {
    freshHttp();
    Http::fake();

    $this->getJson('/crm/sirene/'.SIEGE_SIRET)->assertUnauthorized();
    $this->actingAs(userWithRole(Role::Direction, twoFactor: true))->getJson('/crm/sirene/'.SIEGE_SIRET)->assertForbidden();
    Http::assertNothingSent();
});

it('throttles lookups per user', function () {
    fakeSirene();
    $this->actingAs(commercial());

    for ($i = 0; $i < 30; $i++) {
        $this->getJson('/crm/sirene/'.SIEGE_SIRET)->assertOk();
    }
    $this->getJson('/crm/sirene/'.SIEGE_SIRET)->assertStatus(429);
});

it('exposes the client as a service that raises SireneUnavailable', function () {
    freshHttp();
    Http::fake(['*' => Http::response('boom', 500)]);
    $this->actingAs(commercial());

    expect(fn () => app(SireneClient::class)->lookup(SIEGE_SIRET))->toThrow(SireneUnavailable::class);
});

it('maps « SARL nationale » to SARL and only the unipersonal SARL to EURL', function () {
    expect(InseeCodes::legalFormLabel('5410'))->toBe('SARL')
        ->and(InseeCodes::legalFormLabel('5499'))->toBe('SARL')
        ->and(InseeCodes::legalFormLabel('5498'))->toBe('EURL');
});

it('stops answering from the cache once the organisme disabled the connector', function () {
    fakeSirene();
    $this->actingAs(commercial())->getJson('/crm/sirene/'.SIEGE_SIRET)->assertOk();
    ConnectorConfig::query()->where('connector_key', 'sirene')->update(['enabled' => false]);

    $this->getJson('/crm/sirene/'.SIEGE_SIRET)
        ->assertStatus(503)
        ->assertJsonPath('message', 'Le préremplissage par SIRET est désactivé pour votre organisme.');
});

it('treats a non-diffusible entreprise as not found and never prefills its placeholders', function (Closure $hide) {
    $body = sireneFixture('dassault-systemes');
    $hide($body['results'][0]);
    freshHttp();
    Http::fake(['*' => Http::response($body)]);

    $this->actingAs(commercial())->getJson('/crm/sirene/'.SIEGE_SIRET)
        ->assertNotFound()
        ->assertJsonPath('message', 'Entreprise non diffusible : saisissez les informations manuellement')
        ->assertJsonMissingPath('data');
    // Same answer from the cache.
    $this->getJson('/crm/sirene/'.SIEGE_SIRET)->assertNotFound()
        ->assertJsonPath('message', 'Entreprise non diffusible : saisissez les informations manuellement');
})->with([
    'company status P' => [function (array &$c) {
        $c['statut_diffusion'] = 'P';
    }],
    'establishment status P' => [function (array &$c) {
        $c['siege']['statut_diffusion_etablissement'] = 'P';
    }],
    'placeholder name' => [function (array &$c) {
        $c['nom_raison_sociale'] = '[NON-DIFFUSIBLE]';
        $c['nom_complet'] = '[NON-DIFFUSIBLE]';
    }],
]);
