<?php

namespace App\Modules\Connectors\Ai;

use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\HostPolicy;

/**
 * The one server an AI configuration may call, and the network policy of
 * each call (guard()):
 *
 *  - only the registered scheme, host and port: any other URL is refused,
 *    whatever its address (redirects are never followed either);
 *  - « Serveur sur le réseau local » checked AND allowed by the platform
 *    (tms.connectors.allow_local_ai, refused in multi mode): that host:port
 *    may be private (localViolation()). This exception is scoped to this
 *    endpoint object: no global switch;
 *  - otherwise: https and public addresses only (HostPolicy strict).
 *
 * The returned addresses are pinned for the connection (DNS rebinding).
 */
final class AiEndpoint
{
    /**
     * Hosts of the TMS's own stack (docker compose services): never an AI
     * server, whatever the box says (the AI must run on another machine).
     */
    public const STACK_HOSTS = ['localhost', 'app', 'caddy', 'postgres', 'redis', 'gotenberg', 'worker', 'scheduler', 'vite'];

    public const LOCAL_NOT_ALLOWED = 'Les serveurs d\'IA sur le réseau local ne sont pas autorisés sur cette plateforme.';

    private function __construct(
        public readonly string $baseUrl,
        private readonly string $scheme,
        private readonly string $host,
        private readonly int $port,
        public readonly bool $localNetwork,
    ) {}

    public static function localAllowed(): bool
    {
        return (bool) config('tms.connectors.allow_local_ai');
    }

    /**
     * French reason why a server on the local network at $host ($ips) may
     * not be used, or null: the platform must allow it; the TMS's own stack
     * (STACK_HOSTS by name, their addresses compared as canonical bytes
     * whatever the spelling, loopback) is refused; addresses must
     * be private or public, never internal; with http, private only.
     *
     * @param  list<string>  $ips
     */
    public static function localViolation(string $host, string $scheme, array $ips): ?string
    {
        if (! self::localAllowed()) {
            return self::LOCAL_NOT_ALLOWED;
        }
        if (self::isStackName($host)) {
            return 'Cet hôte fait partie du TMS lui-même : le serveur d\'IA doit être une autre machine.';
        }
        $stack = array_filter(array_map(self::canonical(...), self::stackAddresses()));
        foreach ([$host, ...$ips] as $address) {
            $bytes = self::canonical($address);
            if ($bytes !== null && in_array($bytes, $stack, true)) {
                return 'Cette adresse est celle d\'un service du TMS : le serveur d\'IA doit être une autre machine.';
            }
        }
        $reason = HostPolicy::localNetworkViolation($ips, privateOnly: $scheme === 'http');

        return $reason === null ? null : "Cet hôte pointe vers {$reason} : adresse non autorisée.";
    }

    /**
     * A stack service by name: the bare name (« postgres ») or that name
     * under a private suffix (« postgres.local », « app.internal »). A
     * public name whose first label happens to match (« app.example.com »)
     * is not one.
     */
    private static function isStackName(string $host): bool
    {
        $host = rtrim(strtolower($host), '.');
        if (in_array($host, self::STACK_HOSTS, true)) {
            return true;
        }
        $labels = explode('.', $host);

        return count($labels) === 2 && in_array($labels[0], self::STACK_HOSTS, true) && in_array($labels[1], ['local', 'internal'], true);
    }

    /**
     * The binary form of an IP address, IPv4-mapped (::ffff:a.b.c.d) and
     * IPv4-compatible (::a.b.c.d) IPv6 reduced to the 4-byte IPv4, so that
     * every spelling of one address compares equal; null for a name.
     */
    private static function canonical(string $address): ?string
    {
        $bytes = @inet_pton(trim($address, '[]'));
        if ($bytes === false) {
            return null;
        }
        if (strlen($bytes) === 16) {
            $prefix = substr($bytes, 0, 12);
            $v4 = substr($bytes, 12);
            if ($prefix === str_repeat("\0", 10)."\xff\xff"
                || ($prefix === str_repeat("\0", 12) && $v4 !== "\0\0\0\0" && $v4 !== "\0\0\0\1")) {
                return $v4;
            }
        }

        return $bytes;
    }

    /**
     * Addresses of the stack's services, as this machine resolves them.
     *
     * @return list<string>
     */
    private static function stackAddresses(): array
    {
        $ips = [];
        foreach (self::STACK_HOSTS as $name) {
            $ips = [...$ips, ...HostPolicy::resolve($name)];
        }

        return array_values(array_unique($ips));
    }

    /**
     * @throws AiUnavailable when the configuration has no usable address
     */
    public static function fromConfig(ConnectorConfig $config): self
    {
        $provider = AiProvider::tryFrom((string) ($config->settings['provider'] ?? ''));
        if ($provider === AiProvider::Anthropic) {
            return new self(AnthropicClient::BASE_URL, 'https', 'api.anthropic.com', 443, false);
        }

        $url = $config->settings['base_url'] ?? null;
        $parsed = is_string($url) ? AiBaseUrl::parse($url) : null;
        if ($provider === null || $parsed === null) {
            throw AiUnavailable::notConfigured();
        }
        $host = str_contains($parsed['host'], ':') ? "[{$parsed['host']}]" : $parsed['host'];
        $port = $parsed['port'] === ($parsed['scheme'] === 'http' ? 80 : 443) ? '' : ":{$parsed['port']}";

        return new self(
            "{$parsed['scheme']}://{$host}{$port}{$parsed['path']}",
            $parsed['scheme'],
            $parsed['host'],
            $parsed['port'],
            AiBaseUrl::isLocal($config->settings['local_network'] ?? false),
        );
    }

    /**
     * The checked addresses of $url's host.
     *
     * @return list<string>
     *
     * @throws AiUnavailable refused address (or no address at all)
     */
    public function guard(string $url): array
    {
        $parsed = AiBaseUrl::parse($url);
        if ($parsed === null || $parsed['scheme'] !== $this->scheme || $parsed['host'] !== $this->host || $parsed['port'] !== $this->port) {
            throw AiUnavailable::hostRefused();
        }

        $ips = HostPolicy::resolve($this->host);
        if ($ips === []) {
            throw AiUnavailable::unreachable();
        }
        $reason = $this->localNetwork
            ? self::localViolation($this->host, $this->scheme, $ips)
            : ($this->scheme === 'https' ? HostPolicy::violation($ips, strict: true) : 'http');
        if ($reason !== null) {
            throw AiUnavailable::hostRefused();
        }

        return $ips;
    }
}
