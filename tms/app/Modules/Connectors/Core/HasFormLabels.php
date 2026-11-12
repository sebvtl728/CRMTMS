<?php

namespace App\Modules\Connectors\Core;

/**
 * Optional capability: French labels of the settings and secrets, used by
 * the generic configuration form (the key is shown when absent).
 */
interface HasFormLabels
{
    /**
     * @return array<string, string> setting or secret name => label
     */
    public function labels(): array;
}
