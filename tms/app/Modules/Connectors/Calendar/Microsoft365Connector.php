<?php

namespace App\Modules\Connectors\Calendar;

use App\Modules\Connectors\Core\ConnectionTestResult;
use App\Modules\Connectors\Core\Connector;
use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorKind;
use App\Modules\Connectors\Core\ConnectorRun;
use App\Modules\Connectors\Core\HasFormLabels;
use App\Modules\Connectors\Core\Retryable;
use Closure;
use Throwable;

/**
 * Microsoft 365: pushes the meetings and calls of each connected user to
 * his Outlook calendar (PushActivityToOutlook). The organisme declares one
 * Azure application (settings tenant + client_id, secret client_secret);
 * each user then connects his own account (« Connecter Outlook »). See
 * docs/connectors.md.
 */
class Microsoft365Connector implements Connector, HasFormLabels, Retryable
{
    public function __construct(private readonly GraphClient $graph) {}

    public static function key(): string
    {
        return 'microsoft365';
    }

    public static function label(): string
    {
        return 'Microsoft 365 (envoi vers l\'agenda Outlook)';
    }

    public static function kind(): ConnectorKind
    {
        return ConnectorKind::Sync;
    }

    /**
     * The organisme's configuration when it can be used: enabled, with its
     * application id and secret.
     */
    public static function activeConfig(): ?ConnectorConfig
    {
        $config = ConnectorConfig::query()->where('connector_key', self::key())->whereNull('company_id')->where('enabled', true)->first();

        return $config !== null && is_string($config->settings['client_id'] ?? null) && $config->secret('client_secret') !== null ? $config : null;
    }

    /** An Azure directory: common / organizations / consumers, its GUID or one of its domains. */
    public static function validTenant(string $tenant): bool
    {
        return in_array($tenant, ['common', 'organizations', 'consumers'], true)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenant) === 1
            || preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $tenant) === 1;
    }

    public function settingsRules(): array
    {
        return [
            'tenant' => ['nullable', 'string', 'max:253', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && $value !== '' && ! self::validTenant($value)) {
                    $fail('Indiquez « common », l\'identifiant (GUID) de l\'annuaire Azure ou son domaine (ex. organisme.onmicrosoft.com).');
                }
            }],
            'client_id' => ['required', 'uuid'],
        ];
    }

    public function secretKeys(): array
    {
        return ['client_secret'];
    }

    public function labels(): array
    {
        return [
            'tenant' => 'Annuaire Azure (ID de l\'annuaire, domaine, ou vide = common)',
            'client_id' => 'ID d\'application (client)',
            'client_secret' => 'Secret client (valeur)',
        ];
    }

    public function test(ConnectorConfig $c): ConnectionTestResult
    {
        try {
            return $this->graph->ping($c)
                ? new ConnectionTestResult(true, 'Application Azure reconnue par Microsoft.')
                : new ConnectionTestResult(true, 'Microsoft joignable. Le secret sera vérifié à la première connexion d\'un utilisateur.');
        } catch (GraphRetryLater) {
            return new ConnectionTestResult(false, 'Microsoft est injoignable pour le moment. Réessayez plus tard.');
        } catch (Throwable) {
            return new ConnectionTestResult(false, 'Microsoft a refusé l\'identifiant ou le secret de l\'application. Vérifiez la configuration Azure.');
        }
    }

    /** Replays the push of one action (payload_ref « activity:<id> »). */
    public function retry(ConnectorRun $run): void
    {
        if (preg_match('/^activity:([0-9a-f-]{36})$/', (string) $run->payload_ref, $m) === 1) {
            PushActivityToOutlook::dispatch($m[1], url('/'));
        }
    }
}
