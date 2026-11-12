<?php

namespace App\Modules\Connectors\Core;

/**
 * Contract of every external software adapter (Formevol, Sage, SoWeSign,
 * Outlook, SMTP...). Business code never talks to the software directly: it
 * goes through a connector registered in ConnectorRegistry. See
 * docs/connectors.md.
 */
interface Connector
{
    /** Stable machine key (connector_configs.connector_key), e.g. "smtp". */
    public static function key(): string;

    /** French display name. */
    public static function label(): string;

    public static function kind(): ConnectorKind;

    /**
     * Laravel validation rules of the non-secret settings, keyed by setting
     * name (not prefixed). Only the declared keys are ever stored.
     *
     * @return array<string, list<mixed>>
     */
    public function settingsRules(): array;

    /**
     * Names of the secret values (passwords, tokens). Stored encrypted, never
     * sent back to the interface.
     *
     * @return list<string>
     */
    public function secretKeys(): array;

    /** Checks the saved configuration against the external system. Must not hang and must not throw. */
    public function test(ConnectorConfig $c): ConnectionTestResult;
}
