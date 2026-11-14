<?php

namespace App\Modules\Connectors\Smtp;

use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Builds the Symfony SMTP transport of a configuration. Isolated so tests can
 * substitute it (no network in tests).
 */
class SmtpTransportFactory
{
    /** Seconds allowed to connect / to wait on the server. */
    public const TIMEOUT = 10;

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, string>  $secrets
     *
     * @throws SmtpHostRefused
     */
    public function make(array $settings, array $secrets): TransportInterface
    {
        $ips = $this->check($settings);
        $host = (string) ($settings['host'] ?? '');
        $encryption = (string) ($settings['encryption'] ?? 'tls');
        $transport = (new EsmtpTransportFactory)->create(new Dsn(
            $encryption === 'ssl' ? 'smtps' : 'smtp',
            $host,
            ($secrets['username'] ?? '') !== '' ? $secrets['username'] : null,
            ($secrets['password'] ?? '') !== '' ? $secrets['password'] : null,
            isset($settings['port']) ? (int) $settings['port'] : null,
            // tls: STARTTLS is mandatory (a server, or a MITM, that does not
            // offer it makes the send fail instead of falling back to clear
            // text with the password). none: no STARTTLS upgrade either.
            match ($encryption) {
                'tls' => ['require_tls' => true],
                'none' => ['auto_tls' => false],
                default => [],
            },
        ));

        if ($transport instanceof EsmtpTransport && ($stream = $transport->getStream()) instanceof SocketStream) {
            $stream->setTimeout(self::TIMEOUT);
            self::pin($stream, $host, $ips[0]);
        }

        return $transport;
    }

    /**
     * The socket connects to the checked address, never resolving the name
     * again (DNS rebinding); TLS (implicit or STARTTLS) still verifies the
     * certificate against the name, sent as SNI.
     */
    private static function pin(SocketStream $stream, string $host, string $ip): void
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return; // a literal address: nothing to resolve
        }

        $options = $stream->getStreamOptions();
        $options['ssl'] = [...($options['ssl'] ?? []), 'peer_name' => $host, 'SNI_server_name' => $host];
        $stream->setStreamOptions($options);
        $stream->setHost(str_contains($ip, ':') ? "[{$ip}]" : $ip);
    }

    /**
     * The host must resolve and satisfy SmtpHostPolicy. Called by make(), so
     * the connection test AND every real send (TenantMailer) are covered.
     *
     * @param  array<string, mixed>  $settings
     * @return non-empty-list<string> the checked addresses
     *
     * @throws SmtpHostRefused with a French message
     */
    public function check(array $settings): array
    {
        $host = (string) ($settings['host'] ?? '');
        $ips = SmtpHostPolicy::resolve($host);

        if ($ips === []) {
            throw new SmtpHostRefused("Le serveur « {$host} » est introuvable (nom de domaine inconnu).");
        }

        if (($reason = SmtpHostPolicy::violation($ips)) !== null) {
            throw new SmtpHostRefused("Le serveur « {$host} » pointe vers {$reason} : envoi refusé.");
        }

        return $ips;
    }
}
