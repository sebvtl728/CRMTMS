<?php

namespace App\Modules\Documents;

enum DocumentStatus: string
{
    case Issued = 'issued';
    case Sent = 'sent';
    case SignaturePending = 'signature_pending';
    case Signed = 'signed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Émis',
            self::Sent => 'Envoyé',
            self::SignaturePending => 'En attente de signature',
            self::Signed => 'Signé',
            self::Cancelled => 'Annulé',
        };
    }
}
