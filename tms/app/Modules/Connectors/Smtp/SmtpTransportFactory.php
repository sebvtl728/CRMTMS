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
        $this->check($settings);
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
     * The host must resolve and satisfy SmtpHostPolicy. Called by make(), so
     * the connection test AND every real send (TenantMailer) are covered.
     *
     * @param  array<string, mixed>  $settings
     *
     * @throws SmtpHostRefused with a French message
     */
    public function check(array $settings): void
    {
        $host = (string) ($settings['host'] ?? '');
        $ips = SmtpHostPolicy::resolve($host);

        if ($ips === []) {
            throw new SmtpHostRefused("Le serveur « {$host} » est introuvable (nom de domaine inconnu).");
        }

        if (($reason = SmtpHostPolicy::violation($ips)) !== null) {
            throw new SmtpHostRefused("Le serveur « {$host} » pointe vers {$reason} : envoi refusé.");
        }
    }
}
