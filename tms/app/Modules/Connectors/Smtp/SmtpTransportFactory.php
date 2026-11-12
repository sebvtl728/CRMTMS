<?php

namespace App\Modules\Connectors\Smtp;

use RuntimeException;
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
     */
    public function make(array $settings, array $secrets): TransportInterface
    {
        $host = (string) ($settings['host'] ?? '');
        $encryption = (string) ($settings['encryption'] ?? 'tls');
        $transport = (new EsmtpTransportFactory)->create(new Dsn(
            $encryption === 'ssl' ? 'smtps' : 'smtp',
            $host,
            ($secrets['username'] ?? '') !== '' ? $secrets['username'] : null,
            ($secrets['password'] ?? '') !== '' ? $secrets['password'] : null,
            isset($settings['port']) ? (int) $settings['port'] : null,
            // none: no STARTTLS upgrade either.
            $encryption === 'none' ? ['auto_tls' => false] : [],
        ));

        if ($transport instanceof EsmtpTransport && ($stream = $transport->getStream()) instanceof SocketStream) {
            $stream->setTimeout(self::TIMEOUT);
        }

        return $transport;
    }

    /**
     * Pre-flight check of the connection test: the host must resolve and, unless
     * the install opts in, must not point to a local address.
     *
     * @param  array<string, mixed>  $settings
     *
     * @throws RuntimeException with a French message
     */
    public function check(array $settings): void
    {
        $host = (string) ($settings['host'] ?? '');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            $ips = gethostbynamel($host) ?: [];
        }

        if ($ips === []) {
            throw new RuntimeException("Le serveur « {$host} » est introuvable (nom de domaine inconnu).");
        }

        if (! config('tms.connectors.allow_internal_hosts')) {
            foreach ($ips as $ip) {
                if (SmtpHost::isInternal($ip)) {
                    throw new RuntimeException("Le serveur « {$host} » pointe vers une adresse locale non autorisée.");
                }
            }
        }
    }
}
