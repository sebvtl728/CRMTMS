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
 *  - « Serveur sur le réseau local » checked: that host:port may be private
 *    or loopback (HostPolicy::localNetworkViolation), in http or https.
 *    This exception is scoped to this endpoint object: no global switch;
 *  - otherwise: https and public addresses only (HostPolicy strict).
 *
 * The returned addresses are pinned for the connection (DNS rebinding).
 */
final class AiEndpoint
{
    private function __construct(
        public readonly string $baseUrl,
        private readonly string $scheme,
        private readonly string $host,
        private readonly int $port,
        public readonly bool $localNetwork,
    ) {}

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
            ? HostPolicy::localNetworkViolation($ips)
            : ($this->scheme === 'https' ? HostPolicy::violation($ips, strict: true) : 'http');
        if ($reason !== null) {
            throw AiUnavailable::hostRefused();
        }

        return $ips;
    }
}
