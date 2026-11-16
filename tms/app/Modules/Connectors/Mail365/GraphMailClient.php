<?php

namespace App\Modules\Connectors\Mail365;

use App\Modules\Connectors\Calendar\GraphClient;
use App\Modules\Connectors\Calendar\GraphRetryLater;
use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\HostPolicy;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;

/**
 * Microsoft Graph calls of the microsoft365_mail connector: the organisme's
 * Azure application sends from one fixed mailbox with the application
 * permission Mail.Send (client credentials, no mailbox password).
 *
 *  - application token cached (encrypted) under a key holding the TMS
 *    tenant AND the configuration id (rule 8), renewed 5 minutes before it
 *    expires, once more when Graph answers 401; never stored in the database;
 *  - a message of at most 3 Mo once base64-encoded goes as MIME to
 *    /sendMail; a larger one as a draft, its attachments through upload
 *    sessions (chunks of 3.75 Mo, a multiple of 320 Kio), then /send;
 *  - only login.microsoftonline.com and graph.microsoft.com are called (and
 *    the upload URLs Graph hands out, on its Outlook hosts), in https,
 *    re-checked by HostPolicy (public addresses only, connection pinned),
 *    redirects not followed;
 *  - 401 / 403 / 404 and the refusal of the credentials raise
 *    GraphMailFailed (for good); 429, 5xx and timeouts GraphRetryLater;
 *    messages are written here: never Microsoft's text, a token, the
 *    secret, an upload URL nor an address.
 */
class GraphMailClient
{
    /** Largest base64-encoded MIME message sent in one /sendMail call. */
    public const MIME_LIMIT = 3 * 1024 * 1024;

    /** Largest attachment added to a draft in one call (bigger: upload session). */
    public const SMALL_ATTACHMENT = 3_000_000;

    /** Upload chunk: a multiple of 320 Kio, at most 4 Mo. */
    public const CHUNK_BYTES = 12 * 320 * 1024;

    public const TIMEOUT_SECONDS = 60;

    public const CONNECT_TIMEOUT_SECONDS = 10;

    /** Seconds a token is renewed before Microsoft's expiry. */
    private const TOKEN_MARGIN = 300;

    private const API_HOSTS = ['login.microsoftonline.com', 'graph.microsoft.com'];

    /** Hosts of the upload URLs returned by createUploadSession. */
    private const UPLOAD_HOSTS = ['outlook.office.com', 'outlook.office365.com', 'graph.microsoft.com'];

    /**
     * Cache key of the application token: the TMS tenant, the configuration,
     * and a digest of the credentials (a new secret or application gets a
     * new token at once).
     */
    public static function cacheKey(ConnectorConfig $config): string
    {
        $credentials = hash('sha256', implode("\0", [
            (string) ($config->settings['tenant'] ?? ''),
            (string) ($config->settings['client_id'] ?? ''),
            (string) $config->secret('client_secret'),
        ]));

        return "tenant:{$config->tenant_id}:mail365:{$config->id}:token:".substr($credentials, 0, 16);
    }

    /**
     * An application token for Graph, from the cache unless $fresh.
     *
     * @throws GraphMailFailed|GraphRetryLater
     */
    public function token(ConnectorConfig $config, bool $fresh = false): string
    {
        $key = self::cacheKey($config);
        $cached = $fresh ? null : Cache::get($key);
        if (is_string($cached)) {
            try {
                return Crypt::decryptString($cached);
            } catch (DecryptException) {
                Cache::forget($key);
            }
        }

        $tenant = $config->settings['tenant'] ?? null;
        $clientId = $config->settings['client_id'] ?? null;
        $secret = $config->secret('client_secret');
        if (! is_string($tenant) || ! Microsoft365MailConnector::validDirectory($tenant) || ! is_string($clientId) || $clientId === '' || $secret === null) {
            throw new GraphMailFailed(GraphMailFailed::INCOMPLETE);
        }

        $response = $this->send($this->http()->asForm(), 'POST', GraphClient::LOGIN_URL.'/'.rawurlencode($tenant).'/oauth2/v2.0/token', ['form_params' => [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $secret,
            'scope' => 'https://graph.microsoft.com/.default',
        ]]);
        $token = $response->json('access_token');
        if ($response->status() !== 200 || ! is_string($token) || $token === '') {
            throw new GraphMailFailed(GraphMailFailed::APP_REFUSED, $response->status());
        }

        $expiresIn = $response->json('expires_in');
        $expiresIn = is_numeric($expiresIn) ? (int) $expiresIn : 3599;
        $ttl = $expiresIn > 2 * self::TOKEN_MARGIN ? $expiresIn - self::TOKEN_MARGIN : intdiv($expiresIn, 2);
        if ($ttl > 0) {
            Cache::put($key, Crypt::encryptString($token), $ttl);
        }

        return $token;
    }

    /**
     * Sends a whole MIME message (From, To, Cc, Bcc read from its headers).
     *
     * @throws GraphMailFailed|GraphRetryLater
     */
    public function sendMime(ConnectorConfig $config, string $mime): void
    {
        $response = $this->graph($config, 'POST', '/sendMail', raw: base64_encode($mime));

        if (! $response->successful()) {
            throw GraphMailFailed::rejected($response->status());
        }
    }

    /**
     * Sends a message too large for /sendMail: draft, attachments (upload
     * sessions for the large ones), then send. The draft is deleted when a
     * step fails, so a retry never leaves a second copy behind.
     *
     * @throws GraphMailFailed|GraphRetryLater
     */
    public function sendLarge(ConnectorConfig $config, Email $email): void
    {
        $response = $this->graph($config, 'POST', '/messages', ['json' => $this->draft($config, $email)]);
        $id = $response->json('id');
        if (! $response->successful() || ! is_string($id) || $id === '') {
            throw GraphMailFailed::rejected($response->status());
        }
        $message = '/messages/'.rawurlencode($id);

        try {
            foreach ($email->getAttachments() as $attachment) {
                $this->attach($config, $message, $attachment);
            }

            $response = $this->graph($config, 'POST', $message.'/send');
            if (! $response->successful()) {
                throw GraphMailFailed::rejected($response->status());
            }
        } catch (Throwable $e) {
            try {
                $this->graph($config, 'DELETE', $message);
            } catch (Throwable) {
                // Best effort: an unsent draft stays in the mailbox's Drafts.
            }

            throw $e;
        }
    }

    /**
     * The Graph message resource of a draft (attachments added afterwards).
     *
     * @return array<string, mixed>
     */
    private function draft(ConnectorConfig $config, Email $email): array
    {
        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        $from = $email->getFrom()[0] ?? null;

        return array_filter([
            'subject' => (string) $email->getSubject(),
            'body' => $html !== null
                ? ['contentType' => 'HTML', 'content' => self::content($html)]
                : ['contentType' => 'Text', 'content' => self::content($text)],
            'from' => ['emailAddress' => self::address(new Address(self::mailbox($config), $from?->getName() ?? ''))],
            'toRecipients' => self::recipients($email->getTo()),
            'ccRecipients' => self::recipients($email->getCc()),
            'bccRecipients' => self::recipients($email->getBcc()),
            'replyTo' => self::recipients($email->getReplyTo()),
        ], fn (mixed $value): bool => $value !== []);
    }

    private function attach(ConnectorConfig $config, string $message, DataPart $part): void
    {
        $bytes = $part->getBody();
        $name = $part->getFilename() ?? 'piece-jointe';
        $type = $part->getMediaType().'/'.$part->getMediaSubtype();
        $inline = $part->getDisposition() === 'inline';
        $contentId = $inline && $part->hasContentId() ? $part->getContentId() : null;

        if (strlen($bytes) < self::SMALL_ATTACHMENT) {
            $response = $this->graph($config, 'POST', $message.'/attachments', ['json' => array_filter([
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $name,
                'contentType' => $type,
                'contentBytes' => base64_encode($bytes),
                'isInline' => $inline,
                'contentId' => $contentId,
            ], fn (mixed $value): bool => $value !== null)]);
            if (! $response->successful()) {
                throw GraphMailFailed::rejected($response->status());
            }

            return;
        }

        $response = $this->graph($config, 'POST', $message.'/attachments/createUploadSession', ['json' => ['AttachmentItem' => array_filter([
            'attachmentType' => 'file',
            'name' => $name,
            'size' => strlen($bytes),
            'contentType' => $type,
            'isInline' => $inline,
            'contentId' => $contentId,
        ], fn (mixed $value): bool => $value !== null)]]);
        $uploadUrl = $response->json('uploadUrl');
        if (! $response->successful() || ! is_string($uploadUrl)) {
            throw GraphMailFailed::rejected($response->status());
        }
        $uploadHost = parse_url($uploadUrl, PHP_URL_HOST);
        if (! is_string($uploadHost) || ! in_array(strtolower($uploadHost), self::UPLOAD_HOSTS, true) || parse_url($uploadUrl, PHP_URL_SCHEME) !== 'https') {
            throw new GraphMailFailed(GraphMailFailed::UNKNOWN_UPLOAD);
        }

        $total = strlen($bytes);
        for ($offset = 0; $offset < $total; $offset += self::CHUNK_BYTES) {
            $chunk = substr($bytes, $offset, self::CHUNK_BYTES);
            $end = $offset + strlen($chunk) - 1;
            // The upload URL carries its own token: no Authorization header.
            $response = $this->send(
                $this->http()->withHeaders(['Content-Range' => "bytes {$offset}-{$end}/{$total}"])->withBody($chunk, 'application/octet-stream'),
                'PUT', $uploadUrl, hosts: self::UPLOAD_HOSTS,
            );
            if (! $response->successful()) {
                throw new GraphMailFailed("Microsoft 365 a refusé la pièce jointe (HTTP {$response->status()}).", $response->status());
            }
        }
    }

    /**
     * A Graph call on the sending mailbox, with the application token;
     * once more with a new token on a 401 (revoked meanwhile).
     *
     * @param  array<string, mixed>  $options
     */
    private function graph(ConnectorConfig $config, string $method, string $path, array $options = [], ?string $raw = null): Response
    {
        $url = GraphClient::GRAPH_URL.'/users/'.str_replace('%40', '@', rawurlencode(self::mailbox($config))).$path;
        $call = function (string $token) use ($method, $url, $options, $raw): Response {
            $request = $this->http()->withToken($token);

            return $this->send($raw === null ? $request : $request->withBody($raw, 'text/plain'), $method, $url, $options);
        };

        $response = $call($this->token($config));
        if ($response->status() === 401) {
            $response = $call($this->token($config, fresh: true));
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $hosts
     *
     * @throws GraphRetryLater on a timeout, 429 or 5xx
     */
    private function send(PendingRequest $request, string $method, string $url, array $options = [], array $hosts = self::API_HOSTS): Response
    {
        $ips = $this->guard($url, $hosts);

        try {
            // Connects to the checked addresses: no second resolution (DNS rebinding).
            $response = $request->withOptions(HostPolicy::pinned($url, $ips))->send($method, $url, $options);
        } catch (ConnectionException) {
            throw new GraphRetryLater('Microsoft 365 injoignable (délai dépassé ou connexion refusée).');
        }

        if ($response->status() === 429 || $response->serverError()) {
            throw new GraphRetryLater("Microsoft 365 indisponible (HTTP {$response->status()}).", $this->retryAfter($response));
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->withoutRedirecting();
    }

    /**
     * Only Microsoft's hosts, in https, resolving to public addresses only.
     *
     * @param  list<string>  $hosts
     * @return list<string> the checked addresses of the host
     */
    private function guard(string $url, array $hosts): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || ! in_array(strtolower($host), $hosts, true) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Adresse refusée par la politique réseau des connecteurs.');
        }
        $ips = HostPolicy::resolve($host);
        if ($ips === [] || HostPolicy::violation($ips, strict: true) !== null) {
            throw new RuntimeException("Hôte {$host} refusé par la politique réseau des connecteurs.");
        }

        return $ips;
    }

    private function retryAfter(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));
        if ($header === '' || ! ctype_digit($header)) {
            return null;
        }

        return min((int) $header, GraphClient::MAX_RETRY_AFTER_SECONDS);
    }

    /** The sending mailbox of the configuration. */
    public static function mailbox(ConnectorConfig $config): string
    {
        $address = $config->settings['from_address'] ?? null;

        return is_string($address) && $address !== '' ? $address : throw new GraphMailFailed(GraphMailFailed::INCOMPLETE);
    }

    /**
     * @param  list<Address>  $addresses
     * @return list<array{emailAddress: array<string, string>}>
     */
    private static function recipients(array $addresses): array
    {
        return array_map(fn (Address $a): array => ['emailAddress' => self::address($a)], $addresses);
    }

    /**
     * @return array<string, string>
     */
    private static function address(Address $address): array
    {
        return array_filter(['address' => $address->getAddress(), 'name' => $address->getName()], fn (string $v): bool => $v !== '');
    }

    /**
     * @param  resource|string|null  $body
     */
    private static function content(mixed $body): string
    {
        return is_resource($body) ? (string) stream_get_contents($body, -1, 0) : (string) $body;
    }
}
