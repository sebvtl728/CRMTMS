<?php

namespace App\Modules\Connectors\Core;

enum ConnectorKind: string
{
    case Import = 'import';
    case Export = 'export';
    case Sync = 'sync';
    case Delivery = 'delivery';
    case Assistant = 'assistant';

    public function label(): string
    {
        return match ($this) {
            self::Import => 'Import',
            self::Export => 'Export',
            self::Sync => 'Synchronisation',
            self::Delivery => 'Envoi',
            self::Assistant => 'Assistant',
        };
    }
}
