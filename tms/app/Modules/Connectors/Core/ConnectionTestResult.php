<?php

namespace App\Modules\Connectors\Core;

/**
 * Outcome of Connector::test(). The message is French, shown to the admin,
 * and must never contain a secret.
 */
final class ConnectionTestResult
{
    public function __construct(public bool $ok, public string $message) {}
}
