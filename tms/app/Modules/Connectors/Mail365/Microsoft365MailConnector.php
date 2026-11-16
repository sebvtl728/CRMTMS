<?php

namespace App\Modules\Connectors\Mail365;

use App\Modules\Connectors\Calendar\GraphRetryLater;
use App\Modules\Connectors\Calendar\Microsoft365Connector;
use App\Modules\Connectors\Core\ConnectionTestResult;
use App\Modules\Connectors\Core\Connector;
use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorKind;
use App\Modules\Connectors\Core\HasFormLabels;
use Closure;
use Throwable;

/**
 * Microsoft 365 mail: every e-mail of the organisme leaves through Microsoft
 * Graph (GraphTransport, chosen by TenantMailer) from one fixed mailbox. The
 * organisme declares an Azure application with the APPLICATION permission
 * Mail.Send (admin consent, client credentials): settings tenant (one
 * precise directory), client_id, from_address, from_name; secret
 * client_secret. See docs/connectors.md.
 */
class Microsoft365MailConnector implements Connector, HasFormLabels
{
    private const MULTI_TENANT = ['common', 'organizations', 'consumers'];

    public function __construct(private readonly GraphMailClient $client) {}

    public static function key(): string
    {
        return 'microsoft365_mail';
    }

    public static function label(): string
    {
        return 'Microsoft 365 (envoi des e-mails)';
    }

    public static function kind(): ConnectorKind
    {
        return ConnectorKind::Delivery;
    }

    /**
     * One Azure directory (its GUID or one of its domains): an application
     * token cannot be obtained from common / organizations / consumers.
     */
    public static function validDirectory(string $tenant): bool
    {
        return ! in_array(strtolower($tenant), self::MULTI_TENANT, true) && Microsoft365Connector::validTenant($tenant);
    }

    /**
     * The organisme's configuration when it can send: enabled, complete
     * (directory, application, mailbox) and with its secret.
     */
    public static function activeConfig(): ?ConnectorConfig
    {
        $config = ConnectorConfig::query()->where('connector_key', self::key())->whereNull('company_id')->where('enabled', true)->first();
        if ($config === null) {
            return null;
        }
        $settings = $config->settings;

        return is_string($settings['tenant'] ?? null) && self::validDirectory($settings['tenant'])
            && is_string($settings['client_id'] ?? null) && $settings['client_id'] !== ''
            && is_string($settings['from_address'] ?? null) && $settings['from_address'] !== ''
            && $config->secret('client_secret') !== null
            ? $config : null;
    }

    public function settingsRules(): array
    {
        return [
            'tenant' => ['required', 'string', 'max:253', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! self::validDirectory($value)) {
                    $fail('Indiquez l\'identifiant (GUID) de l\'annuaire Azure ou un de ses domaines (ex. capformation.net) : « common », « organizations » et « consumers » ne permettent pas l\'envoi par l\'application.');
                }
            }],
            'client_id' => ['required', 'uuid'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function secretKeys(): array
    {
        return ['client_secret'];
    }

    public function labels(): array
    {
        return [
            'tenant' => 'Annuaire Azure (ID de l\'annuaire ou domaine)',
            'client_id' => 'ID d\'application (client)',
            'from_address' => 'Adresse d\'expédition (boîte Microsoft 365)',
            'from_name' => 'Nom d\'expéditeur',
            'client_secret' => 'Secret client (valeur)',
        ];
    }

    public function test(ConnectorConfig $c): ConnectionTestResult
    {
        try {
            $this->client->token($c, fresh: true);

            return new ConnectionTestResult(true, 'Application reconnue par Microsoft.');
        } catch (GraphRetryLater) {
            return new ConnectionTestResult(false, 'Microsoft est injoignable pour le moment. Réessayez plus tard.');
        } catch (GraphMailFailed $e) {
            return new ConnectionTestResult(false, $e->getMessage());
        } catch (Throwable) {
            return new ConnectionTestResult(false, 'Échec inattendu lors du test Microsoft 365. Vérifiez les paramètres.');
        }
    }
}
