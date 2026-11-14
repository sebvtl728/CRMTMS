<?php

namespace App\Modules\Connectors\Calendar;

use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\HostPolicy;
use App\Modules\Core\Audit\AuditRecorder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Microsoft identity platform (OAuth 2.0, authorization code + PKCE) and
 * Microsoft Graph calendar calls of the microsoft365 connector.
 *
 *  - only https://login.microsoftonline.com and https://graph.microsoft.com
 *    are ever called, each time re-checked by HostPolicy (public addresses
 *    only) and without following redirects;
 *  - an access token is renewed shortly before it expires, and once more
 *    when Graph answers 401; Microsoft refusing the refresh (invalid_grant)
 *    marks the link « À reconnecter » (OutlookReconnectRequired);
 *  - 429, 5xx and timeouts raise GraphRetryLater (with Retry-After);
 *  - exception messages are written here: never Microsoft's text, a token,
 *    the secret nor any event content.
 */
class GraphClient
{
    public const LOGIN_URL = 'https://login.microsoftonline.com';

    public const GRAPH_URL = 'https://graph.microsoft.com/v1.0';

    public const SCOPES = 'offline_access https://graph.microsoft.com/Calendars.ReadWrite';

    public const TIMEOUT_SECONDS = 10;

    /** Longest Retry-After honoured. */
    public const MAX_RETRY_AFTER_SECONDS = 3600;

    private const ALLOWED_HOSTS = ['login.microsoftonline.com', 'graph.microsoft.com'];

    /** Azure « tenants » that are not one directory (no client credentials there). */
    private const MULTI_TENANT = ['common', 'organizations', 'consumers'];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function authorizeUrl(ConnectorConfig $config, string $redirectUri, string $state, string $challenge): string
    {
        return self::LOGIN_URL.'/'.$this->tenant($config).'/oauth2/v2.0/authorize?'.http_build_query([
            'client_id' => $this->clientId($config),
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The tokens of an authorization code.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function exchangeCode(ConnectorConfig $config, string $code, string $verifier, string $redirectUri): array
    {
        $response = $this->tokenRequest($config, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
            'scope' => self::SCOPES,
        ]);
        $tokens = $response->json();

        if ($response->status() !== 200 || ! is_array($tokens) || ! is_string($tokens['access_token'] ?? null) || ! is_string($tokens['refresh_token'] ?? null)) {
            throw new RuntimeException("Microsoft : code d'autorisation refusé (HTTP {$response->status()}).");
        }
        if (! is_string($tokens['scope'] ?? null) || stripos($tokens['scope'], 'calendars.readwrite') === false) {
            throw new RuntimeException('Microsoft : accès à l\'agenda non accordé.');
        }

        return $tokens;
    }

    /**
     * Renews the tokens of $link, unless another job did it meanwhile (the
     * access token differs from $staleToken and is fresh).
     *
     * @throws OutlookReconnectRequired|GraphRetryLater|RuntimeException
     */
    public function refresh(ConnectorConfig $config, CalendarLink $link, ?string $staleToken = null): void
    {
        Cache::lock("tenant:{$link->tenant_id}:outlook:refresh:{$link->id}", 30)->block(15, function () use ($config, $link, $staleToken): void {
            $link->refresh();
            if (! $link->isActive()) {
                throw new OutlookReconnectRequired;
            }
            if ($link->access_token !== $staleToken && $link->accessTokenFresh()) {
                return;
            }

            $response = $this->tokenRequest($config, [
                'grant_type' => 'refresh_token',
                'refresh_token' => (string) $link->refresh_token,
                'scope' => self::SCOPES,
            ]);
            $tokens = $response->json();

            if ($response->status() === 400 && is_array($tokens) && ($tokens['error'] ?? null) === 'invalid_grant') {
                $link->markReconnect();
                $this->audit->record('calendar.outlook_reconnect_required', $link->user);

                throw new OutlookReconnectRequired;
            }
            if ($response->status() !== 200 || ! is_array($tokens) || ! is_string($tokens['access_token'] ?? null)) {
                throw new RuntimeException("Microsoft : renouvellement de l'accès refusé (HTTP {$response->status()}).");
            }

            $link->storeTokens($tokens);
        });
    }

    /**
     * Connection test: the application credentials for one directory, the
     * reachability of Microsoft for a multi-tenant application.
     *
     * @throws RuntimeException
     */
    public function ping(ConnectorConfig $config): bool
    {
        $tenant = $this->tenant($config);
        if (in_array($tenant, self::MULTI_TENANT, true)) {
            $response = $this->send($this->http(), 'GET', self::LOGIN_URL."/{$tenant}/v2.0/.well-known/openid-configuration");
            if ($response->status() !== 200) {
                throw new RuntimeException("Microsoft : réponse HTTP {$response->status()}.");
            }

            return false;
        }

        $response = $this->tokenRequest($config, ['grant_type' => 'client_credentials', 'scope' => 'https://graph.microsoft.com/.default']);
        if ($response->status() !== 200) {
            throw new RuntimeException('Identifiant ou secret de l\'application refusé par Microsoft.');
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return string the Outlook event id
     */
    public function createEvent(ConnectorConfig $config, CalendarLink $link, array $event): string
    {
        $response = $this->graph($config, $link, 'POST', '/me/events', $event);
        $id = $response->json('id');

        if (! in_array($response->status(), [200, 201], true) || ! is_string($id) || $id === '') {
            throw new RuntimeException("Microsoft Graph : création refusée (HTTP {$response->status()}).");
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return bool false when the event no longer exists in Outlook
     */
    public function updateEvent(ConnectorConfig $config, CalendarLink $link, string $eventId, array $event): bool
    {
        $response = $this->graph($config, $link, 'PATCH', '/me/events/'.rawurlencode($eventId), $event);

        return match (true) {
            $response->successful() => true,
            in_array($response->status(), [404, 410], true) => false,
            default => throw new RuntimeException("Microsoft Graph : modification refusée (HTTP {$response->status()})."),
        };
    }

    /** Deletes an event; one already gone is fine. */
    public function deleteEvent(ConnectorConfig $config, CalendarLink $link, string $eventId): void
    {
        $response = $this->graph($config, $link, 'DELETE', '/me/events/'.rawurlencode($eventId));

        if (! $response->successful() && ! in_array($response->status(), [404, 410], true)) {
            throw new RuntimeException("Microsoft Graph : suppression refusée (HTTP {$response->status()}).");
        }
    }

    /**
     * A Graph call with the link's token: renewed first when about to
     * expire, then once more on a 401.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function graph(ConnectorConfig $config, CalendarLink $link, string $method, string $path, ?array $body = null): Response
    {
        if (! $link->isActive()) {
            throw new OutlookReconnectRequired;
        }
        if (! $link->accessTokenFresh()) {
            $this->refresh($config, $link, $link->access_token);
        }

        $call = fn (): Response => $this->send(
            $this->http()->withToken((string) $link->access_token)->withHeaders(['Prefer' => 'outlook.timezone="Europe/Paris"']),
            $method, self::GRAPH_URL.$path, $body === null ? [] : ['json' => $body],
        );

        $response = $call();
        if ($response->status() === 401) {
            $this->refresh($config, $link, $link->access_token);
            $response = $call();
            if ($response->status() === 401) {
                throw new RuntimeException('Microsoft Graph : accès refusé (HTTP 401).');
            }
        }

        return $response;
    }

    /**
     * @param  array<string, string>  $form
     */
    private function tokenRequest(ConnectorConfig $config, array $form): Response
    {
        $secret = $config->secret('client_secret') ?? throw new RuntimeException('Secret de l\'application Azure non renseigné.');

        return $this->send($this->http()->asForm(), 'POST', self::LOGIN_URL.'/'.$this->tenant($config).'/oauth2/v2.0/token', ['form_params' => [
            ...$form,
            'client_id' => $this->clientId($config),
            'client_secret' => $secret,
        ]]);
    }

    /**
     * @param  array<string, mixed>  $options  ['json' => …] or ['form_params' => …]
     *
     * @throws GraphRetryLater on a timeout, 429 or 5xx
     */
    private function send(PendingRequest $request, string $method, string $url, array $options = []): Response
    {
        $this->guard($url);

        try {
            $response = $request->send($method, $url, $options);
        } catch (ConnectionException) {
            throw new GraphRetryLater('Microsoft injoignable (délai dépassé ou connexion refusée).');
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new GraphRetryLater("Microsoft indisponible (HTTP {$response->status()}).", $this->retryAfter($response));
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::TIMEOUT_SECONDS)
            ->withoutRedirecting();
    }

    /**
     * Only Microsoft's two hosts, resolving to public addresses only.
     */
    private function guard(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || ! in_array($host, self::ALLOWED_HOSTS, true) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Adresse refusée par la politique réseau des connecteurs.');
        }
        $ips = HostPolicy::resolve($host);
        if ($ips === [] || HostPolicy::violation($ips, strict: true) !== null) {
            throw new RuntimeException("Hôte {$host} refusé par la politique réseau des connecteurs.");
        }
    }

    private function retryAfter(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));
        if ($header === '' || ! ctype_digit($header)) {
            return null;
        }

        return min((int) $header, self::MAX_RETRY_AFTER_SECONDS);
    }

    private function tenant(ConnectorConfig $config): string
    {
        $tenant = $config->settings['tenant'] ?? null;

        return is_string($tenant) && Microsoft365Connector::validTenant($tenant) ? $tenant : 'common';
    }

    private function clientId(ConnectorConfig $config): string
    {
        $id = $config->settings['client_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : throw new RuntimeException('Identifiant de l\'application Azure non renseigné.');
    }
}
