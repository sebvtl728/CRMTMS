<?php

namespace App\Modules\Connectors\Sirene;

use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Core\HostPolicy;
use App\Modules\Core\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Looks a SIRET up in the public « Recherche d'entreprises » API
 * (GET {base}/search?q=<siret>).
 *
 *  - answers are cached per organisme (key tenant:<id>:sirene:<siret>): a
 *    hit for 24 h, "not found" for 1 h; errors are never cached, nor are
 *    sole traders (their name is a natural person's);
 *  - only real remote calls are journaled (connector_runs, summary = SIRET
 *    and outcome; never names, addresses or managers);
 *  - the host is re-checked at call time (public addresses only) and
 *    redirects are not followed.
 */
class SireneClient
{
    public const TIMEOUT_SECONDS = 5;

    public const CACHE_HIT_TTL_HOURS = 24;

    public const CACHE_MISS_TTL_HOURS = 1;

    private const PING_SIRET = '32230644000213';

    public function __construct(private readonly TenantContext $tenants) {}

    public static function cacheKey(string $tenantId, string $siret): string
    {
        return "tenant:{$tenantId}:sirene:{$siret}";
    }

    /**
     * @throws SireneUnavailable
     */
    public function lookup(string $siret): ?CompanyLookupResult
    {
        $tenantId = $this->tenants->id();
        $key = self::cacheKey($tenantId, $siret);

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return ($cached['found'] ?? false) ? CompanyLookupResult::fromArray($cached['data']) : null;
        }

        $config = $this->config();
        if (! $config->enabled) {
            throw SireneUnavailable::disabled();
        }
        $baseUrl = $this->baseUrl($config);

        $result = null;
        $run = ConnectorRun::track($config, 'in', function () use ($baseUrl, $siret, &$result): string {
            $result = $this->fetch($baseUrl, $siret);

            return "SIRET {$siret} : ".($result === null ? 'aucune entreprise trouvée' : 'entreprise trouvée');
        });

        if ($run->failed()) {
            throw SireneUnavailable::generic();
        }

        if ($result === null) {
            Cache::put($key, ['found' => false], now()->addHours(self::CACHE_MISS_TTL_HOURS));
        } elseif (! str_starts_with((string) $result->legalFormCode, '1')) {
            Cache::put($key, ['found' => true, 'data' => $result->toArray()], now()->addHours(self::CACHE_HIT_TTL_HOURS));
        }

        return $result;
    }

    /**
     * Connection test of a saved configuration: one request, result ignored.
     *
     * @throws SireneUnavailable
     */
    public function ping(ConnectorConfig $config): void
    {
        $this->fetch($this->baseUrl($config), self::PING_SIRET);
    }

    /**
     * The organisme's configuration, created enabled on first use (the
     * connector works out of the box; an administrator may disable it).
     */
    private function config(): ConnectorConfig
    {
        if (($config = $this->findConfig()) !== null) {
            return $config;
        }

        try {
            return ConnectorConfig::create(['connector_key' => SireneConnector::key(), 'enabled' => true, 'settings' => []]);
        } catch (UniqueConstraintViolationException) {
            return ConnectorConfig::query()->where('connector_key', SireneConnector::key())->whereNull('company_id')->firstOrFail();
        }
    }

    private function findConfig(): ?ConnectorConfig
    {
        return ConnectorConfig::query()->where('connector_key', SireneConnector::key())->whereNull('company_id')->first();
    }

    /**
     * @throws SireneUnavailable when the base URL or its host is not acceptable
     */
    private function baseUrl(ConnectorConfig $config): string
    {
        $url = rtrim((string) (($config->settings['base_url'] ?? null) ?: SireneConnector::DEFAULT_BASE_URL), '/');

        $host = SireneBaseUrl::host($url) ?? throw SireneUnavailable::generic();
        $ips = HostPolicy::resolve($host);
        if ($ips === [] || HostPolicy::violation($ips, strict: true) !== null) {
            throw SireneUnavailable::generic();
        }

        return $url;
    }

    /**
     * @throws RuntimeException on any failure to get a usable answer
     */
    private function fetch(string $baseUrl, string $siret): ?CompanyLookupResult
    {
        try {
            $response = Http::acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->get("{$baseUrl}/search", ['q' => $siret, 'per_page' => 1]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Annuaire injoignable : '.$e->getMessage(), previous: $e);
        }

        if ($response->status() !== 200) {
            throw new RuntimeException("Annuaire : réponse HTTP {$response->status()}.");
        }
        $body = $response->json();
        if (! is_array($body) || ! is_array($body['results'] ?? null)) {
            throw new RuntimeException('Annuaire : réponse illisible.');
        }

        foreach ($body['results'] as $company) {
            if (is_array($company) && ($found = $this->parse($company, $siret)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $company
     */
    private function parse(array $company, string $siret): ?CompanyLookupResult
    {
        $establishments = array_merge(
            is_array($company['siege'] ?? null) ? [$company['siege']] : [],
            is_array($company['matching_etablissements'] ?? null) ? $company['matching_etablissements'] : [],
        );
        $establishment = null;
        foreach ($establishments as $candidate) {
            if (is_array($candidate) && ($candidate['siret'] ?? null) === $siret) {
                $establishment = $candidate;
                break;
            }
        }
        $name = $company['nom_raison_sociale'] ?? $company['nom_complet'] ?? null;
        if ($establishment === null || ! is_string($name) || $name === '') {
            return null;
        }

        $naf = $establishment['activite_principale'] ?? $company['activite_principale'] ?? null;
        $postalCode = $this->string($establishment['code_postal'] ?? null);
        $city = $this->string($establishment['libelle_commune'] ?? null);
        $range = InseeCodes::employeeRange($this->string($establishment['tranche_effectif_salarie'] ?? $company['tranche_effectif_salarie'] ?? null));
        $legalCode = $this->string($company['nature_juridique'] ?? null);

        return new CompanyLookupResult(
            name: $name,
            siret: $siret,
            nafCode: is_string($naf) ? str_replace('.', '', $naf) : null,
            legalFormLabel: InseeCodes::legalFormLabel($legalCode),
            addressLine1: $this->street($this->string($establishment['adresse'] ?? null), $postalCode, $city),
            postalCode: $postalCode,
            city: $city,
            employeeRangeLabel: $range['label'] ?? null,
            employeeMin: $range['min'] ?? null,
            employeeMax: $range['max'] ?? null,
            legalFormCode: $legalCode,
        );
    }

    /** The directory's one-line address minus its trailing "<postal code> <city>". */
    private function street(?string $address, ?string $postalCode, ?string $city): ?string
    {
        if ($address === null) {
            return null;
        }
        if ($postalCode !== null && $city !== null) {
            $address = preg_replace('/\s+'.preg_quote($postalCode, '/').'\s+'.preg_quote($city, '/').'\s*$/iu', '', $address) ?? $address;
        }

        return $this->string($address);
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
