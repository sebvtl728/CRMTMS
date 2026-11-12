<?php

namespace App\Modules\Connectors\Sirene;

use App\Modules\Connectors\Core\ConnectionTestResult;
use App\Modules\Connectors\Core\Connector;
use App\Modules\Connectors\Core\ConnectorConfig;
use App\Modules\Connectors\Core\ConnectorKind;
use App\Modules\Connectors\Core\HasFormLabels;
use Throwable;

/**
 * French public company directory (« Recherche d'entreprises »): prefills an
 * entreprise from its SIRET. Needs no secret and no tenant configuration
 * (enabled by default on the public API); an administrator may point it at
 * another base URL or disable it. See SireneClient and docs/connectors.md.
 */
class SireneConnector implements Connector, HasFormLabels
{
    public const DEFAULT_BASE_URL = 'https://recherche-entreprises.api.gouv.fr';

    public function __construct(private readonly SireneClient $client) {}

    public static function key(): string
    {
        return 'sirene';
    }

    public static function label(): string
    {
        return 'Annuaire des entreprises (préremplissage par SIRET)';
    }

    public static function kind(): ConnectorKind
    {
        return ConnectorKind::Import;
    }

    public function settingsRules(): array
    {
        return [
            'base_url' => ['nullable', 'string', 'max:255', new SireneBaseUrl],
        ];
    }

    public function secretKeys(): array
    {
        return [];
    }

    public function labels(): array
    {
        return ['base_url' => 'Adresse de l\'API (vide = '.self::DEFAULT_BASE_URL.')'];
    }

    public function test(ConnectorConfig $c): ConnectionTestResult
    {
        try {
            $this->client->ping($c);

            return new ConnectionTestResult(true, 'Annuaire des entreprises joignable.');
        } catch (SireneUnavailable $e) {
            return new ConnectionTestResult(false, $e->getMessage());
        } catch (Throwable) {
            return new ConnectionTestResult(false, SireneUnavailable::MESSAGE);
        }
    }
}
