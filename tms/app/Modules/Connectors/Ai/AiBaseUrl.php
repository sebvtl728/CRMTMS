<?php

namespace App\Modules\Connectors\Ai;

use App\Modules\Connectors\Core\HostPolicy;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Base address of an OpenAI-compatible server (« …/v1 »): http(s), a host,
 * an optional port and path, no credentials, query nor fragment.
 *
 *  - box « Serveur sur le réseau local » unchecked: https only, and the host
 *    must resolve to public addresses only (HostPolicy::violation strict);
 *  - box checked (only when the platform allows it, tms.connectors
 *    .allow_local_ai): the host may be private, never the TMS's own stack,
 *    loopback, link-local (cloud metadata) nor reserved; http only towards
 *    private addresses (AiEndpoint::localViolation).
 *
 * A name that does not resolve yet is accepted: every call checks again.
 */
final class AiBaseUrl implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @return array{scheme: string, host: string, port: int, path: string}|null
     */
    public static function parse(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if (! preg_match('#^(/[A-Za-z0-9._~-]+)*$#', $path) || str_contains($path, '/..') || str_contains($path, '/./')) {
            return null;
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isName = (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $host)
            && ! preg_match('/(^|\.)[0-9]+$/', $host);
        if (! $isIp && ! $isName) {
            return null;
        }

        return [
            'scheme' => $parts['scheme'],
            'host' => $host,
            'port' => $parts['port'] ?? ($parts['scheme'] === 'http' ? 80 : 443),
            'path' => $path,
        ];
    }

    public static function isLocal(mixed $flag): bool
    {
        return $flag === true || $flag === 1 || $flag === '1' || $flag === 'true';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $parsed = is_string($value) ? self::parse($value) : null;
        if ($parsed === null) {
            $fail('Indiquez une adresse complète, par exemple https://api.exemple.com/v1 (sans identifiant, paramètre ni ancre).');

            return;
        }

        $local = self::isLocal($this->data['settings']['local_network'] ?? false);
        if ($local && ! AiEndpoint::localAllowed()) {
            $fail(AiEndpoint::LOCAL_NOT_ALLOWED);

            return;
        }
        if ($parsed['scheme'] !== 'https' && ! $local) {
            $fail('L\'adresse doit commencer par https:// (http n\'est permis que pour un serveur sur le réseau local).');

            return;
        }

        $ips = HostPolicy::resolve($parsed['host']);
        if ($local) {
            if (($reason = AiEndpoint::localViolation($parsed['host'], $parsed['scheme'], $ips)) !== null) {
                $fail($reason);
            }

            return;
        }
        if (($reason = HostPolicy::violation($ips, strict: true)) !== null) {
            $fail("Cet hôte pointe vers {$reason} : cochez « Serveur sur le réseau local » pour un serveur interne, ou indiquez une adresse publique.");
        }
    }
}
