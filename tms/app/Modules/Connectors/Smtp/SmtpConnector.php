<?php

namespace App\Modules\Connectors\Smtp;

use App\Modules\Connectors\Core\ConnectionTestResult;
use App\Modules\Connectors\Core\Connector;
use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorKind;
use App\Modules\Connectors\Core\HasFormLabels;
use App\Modules\Core\Users\User;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Outgoing mail through the organisme's own SMTP server (see TenantMailer).
 */
class SmtpConnector implements Connector, HasFormLabels
{
    public function __construct(private readonly SmtpTransportFactory $transports) {}

    public static function key(): string
    {
        return 'smtp';
    }

    public static function label(): string
    {
        return 'Serveur SMTP (envoi d\'e-mails)';
    }

    public static function kind(): ConnectorKind
    {
        return ConnectorKind::Delivery;
    }

    public function settingsRules(): array
    {
        return [
            'host' => ['required', 'string', 'max:253', new SmtpHost],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', 'in:tls,ssl,none'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function secretKeys(): array
    {
        return ['username', 'password'];
    }

    public function labels(): array
    {
        return [
            'host' => 'Serveur (hôte)',
            'port' => 'Port',
            'encryption' => 'Chiffrement',
            'from_address' => 'Adresse d\'expédition',
            'from_name' => 'Nom d\'expéditeur',
            'username' => 'Identifiant',
            'password' => 'Mot de passe',
        ];
    }

    public function test(ConnectorConfig $c): ConnectionTestResult
    {
        $settings = $c->settings;
        $secrets = $c->secrets ?? [];

        try {
            $this->transports->check($settings);
            $transport = $this->transports->make($settings, $secrets);

            $user = Auth::user();
            $recipient = $user instanceof User ? $user->email : null;
            if ($recipient === null) {
                return new ConnectionTestResult(false, 'Aucun utilisateur connecté pour recevoir l\'e-mail de test.');
            }

            $transport->send((new Email)
                ->from(new Address((string) $settings['from_address'], (string) ($settings['from_name'] ?? '')))
                ->to($recipient)
                ->subject('Test de la connexion SMTP')
                ->text('Ceci est un e-mail de test envoyé depuis votre TMS. Si vous le lisez, la configuration SMTP fonctionne.'));

            return new ConnectionTestResult(true, "E-mail de test envoyé à {$recipient}.");
        } catch (RuntimeException $e) {
            // Our own French messages (unresolvable / internal host).
            return new ConnectionTestResult(false, $e->getMessage());
        } catch (TransportExceptionInterface $e) {
            return new ConnectionTestResult(false, $this->explain($e, $c));
        } catch (Throwable) {
            return new ConnectionTestResult(false, 'Échec inattendu lors du test SMTP. Vérifiez les paramètres.');
        }
    }

    /**
     * French summary of a transport failure; the server's raw text is never
     * relayed (it can echo credentials).
     */
    private function explain(TransportExceptionInterface $e, ConnectorConfig $c): string
    {
        $raw = strtolower($e->getMessage());
        $host = (string) ($c->settings['host'] ?? '');

        return match (true) {
            str_contains($raw, 'authenticate') || str_contains($raw, 'credentials') => 'Authentification refusée : vérifiez l\'identifiant et le mot de passe.',
            str_contains($raw, 'timed out') || str_contains($raw, 'timeout') => "Le serveur « {$host} » ne répond pas (délai dépassé).",
            str_contains($raw, 'could not be established') || str_contains($raw, 'connection refused') => "Connexion impossible au serveur « {$host} » : vérifiez le port et le chiffrement.",
            str_contains($raw, 'tls') || str_contains($raw, 'ssl') || str_contains($raw, 'crypto') => 'Échec de la négociation du chiffrement : vérifiez le mode (TLS / SSL) et le port.',
            default => 'Le serveur SMTP a refusé l\'envoi. Vérifiez les paramètres et l\'adresse d\'expédition.',
        };
    }
}
