<?php

namespace App\Modules\Connectors\Core;

/**
 * Optional capability: the connector can replay a failed run from its
 * payload_ref (the "relancer" action of the run journal).
 */
interface Retryable
{
    public function retry(ConnectorRun $run): void;
}
